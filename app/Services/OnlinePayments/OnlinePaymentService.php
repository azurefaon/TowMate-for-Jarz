<?php

namespace App\Services\OnlinePayments;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayMongo SANDBOX checkout, kept apart from the Team Leader / dispatcher payment flow.
 *
 * Hard rules this class enforces:
 *  - it never changes a booking's status or its payment_method / preferred_payment_method;
 *  - only provider-verified evidence (a signed webhook, or a session retrieved from the provider)
 *    can mark a transaction paid — never a redirect, and never a client-supplied amount;
 *  - at most one open session and one verified payment per payable (database-enforced);
 *  - nothing is ever refunded automatically; odd cases become `review` / `duplicate` for a human.
 */
class OnlinePaymentService
{
    public function __construct(
        protected SandboxGuard $guard,
        protected PayableResolver $resolver,
        protected OnlinePaymentGateway $gateway,
        protected WebhookVerifier $verifier,
    ) {
    }

    public function availableFor(?User $user): bool
    {
        return $this->guard->enabled() && $this->guard->userAllowed($user);
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    /**
     * @return array{transaction: PaymentTransaction, created: bool}
     *
     * @throws OnlinePaymentException
     */
    public function initiate(User $user, string $bookingCode, string $method): array
    {
        $this->assertAvailable($user);

        $channel = config('paymongo.channels.' . $method);
        if (! is_string($channel) || $channel === '') {
            throw new OnlinePaymentException('That payment channel is not available yet.', 422, 'channel_unavailable');
        }

        $customer = Customer::where('user_id', $user->id)->first();
        $booking = $customer
            ? Booking::where('booking_code', $bookingCode)->where('customer_id', $customer->id)->first()
            : null;

        if (! $customer || ! $booking) {
            throw new OnlinePaymentException('Booking not found.', 404, 'not_found');
        }

        $staleSessionId = null;

        try {
            [$transaction, $created] = DB::transaction(function () use ($booking, $customer, $user, $method, &$staleSessionId) {
                // Lock every vehicle of the payable (ascending id, like the Team Leader flow) before deciding anything.
                $ids = $this->resolver->resolve($booking)->memberIds;
                Booking::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

                $payable = $this->resolver->resolve($booking->refresh());
                $this->assertPayable($payable, $customer);

                $existing = PaymentTransaction::where('payable_type', $payable->type)
                    ->where('payable_id', $payable->anchorId)
                    ->lockForUpdate()
                    ->get();

                if ($existing->contains('status', PaymentTransaction::STATUS_PAID)) {
                    throw new OnlinePaymentException('This booking has already been paid online.', 409, 'already_paid');
                }

                if ($existing->contains(fn (PaymentTransaction $t) => in_array($t->status, [PaymentTransaction::STATUS_REVIEW, PaymentTransaction::STATUS_DUPLICATE], true))) {
                    throw new OnlinePaymentException('An earlier online payment is waiting for the dispatcher to review.', 409, 'under_review');
                }

                $pending = $existing->firstWhere('status', PaymentTransaction::STATUS_PENDING);

                if ($pending) {
                    $sameAmount = (int) $pending->amount_centavos === $payable->totalCentavos;

                    if ($sameAmount && $pending->expires_at && $pending->expires_at->isFuture()) {
                        return [$pending, false];
                    }

                    // Out of time, or the total moved since it was opened: never reuse a session for a superseded amount.
                    $staleSessionId = $pending->provider_checkout_session_id;
                    $pending->update([
                        'status' => PaymentTransaction::STATUS_EXPIRED,
                        'flag' => $sameAmount ? 'ttl_elapsed' : 'superseded_total',
                    ]);
                }

                $transaction = PaymentTransaction::create([
                    'provider' => 'paymongo',
                    'reference' => 'TMP-' . strtoupper(Str::random(14)),
                    'payable_type' => $payable->type,
                    'payable_id' => $payable->anchorId,
                    'booking_id' => $payable->anchorId,
                    'customer_id' => $customer->id,
                    'initiated_by_user_id' => $user->id,
                    'method' => $method,
                    'amount_centavos' => $payable->totalCentavos,
                    'currency' => 'PHP',
                    'payable_snapshot' => $payable->snapshot(),
                    'status' => PaymentTransaction::STATUS_PENDING,
                    'livemode' => false,
                    'expires_at' => now()->addMinutes((int) config('paymongo.session_ttl_minutes', 30)),
                ]);

                return [$transaction, true];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request opened the session between our read and our insert.
            $transaction = PaymentTransaction::where('payable_type', $this->resolver->resolve($booking)->type)
                ->where('payable_id', $this->resolver->resolve($booking)->anchorId)
                ->where('status', PaymentTransaction::STATUS_PENDING)
                ->latest('id')
                ->first();

            if (! $transaction) {
                throw new OnlinePaymentException('Please try again in a moment.', 409, 'busy');
            }

            $created = false;
        }

        if ($staleSessionId) {
            $this->tryExpire($staleSessionId);
        }

        if (! $created) {
            if (! $transaction->provider_checkout_session_id) {
                throw new OnlinePaymentException('Your payment is being prepared. Please try again in a moment.', 409, 'preparing');
            }

            return ['transaction' => $transaction, 'created' => false];
        }

        return ['transaction' => $this->openSession($transaction, $channel, $booking), 'created' => true];
    }

    /**
     * The verified App Link the hosted checkout returns to. Opens the TowMate app on the payment status screen; it
     * carries only the booking code and is never evidence of payment.
     */
    protected function returnUrl(string $outcome, Booking $booking): string
    {
        return config('app_links.base_url') . config('app_links.payment_return_path') . '/' . $outcome
            . '?booking=' . rawurlencode($booking->booking_code);
    }

    protected function openSession(PaymentTransaction $transaction, string $channel, Booking $booking): PaymentTransaction
    {
        try {
            $session = $this->gateway->createCheckoutSession([
                'amount_centavos' => $transaction->amount_centavos,
                'name' => 'TowMate towing service',
                'description' => 'Towing service ' . $booking->booking_code,
                'reference' => $transaction->reference,
                'payment_method_types' => [$channel],
                'metadata' => [
                    'payment_transaction' => $transaction->reference,
                    'payable_type' => $transaction->payable_type,
                    'payable_id' => (string) $transaction->payable_id,
                ],
                'success_url' => config('paymongo.success_url') ?: $this->returnUrl('success', $booking),
                'cancel_url' => config('paymongo.cancel_url') ?: $this->returnUrl('cancelled', $booking),
            ]);
        } catch (GatewayException $e) {
            // Only our own coarse code is stored or logged: the provider's wording could echo anything.
            $failureCode = match (true) {
                in_array($e->httpStatus, [401, 403], true) => 'provider_auth',
                $e->httpStatus === 429 => 'provider_rate_limited',
                $e->httpStatus !== null && $e->httpStatus >= 400 && $e->httpStatus < 500 => 'provider_rejected',
                $e->errorCode === 'unexpected_response' => 'provider_unexpected_response',
                default => 'provider_unavailable',
            };

            // Keep the provider's short error identifier (e.g. an account-capability refusal) so a pilot can be
            // diagnosed. Only an identifier-shaped value that cannot be a credential is kept; never its wording.
            if (is_string($e->errorCode)
                && preg_match('/^[A-Za-z0-9_]{1,30}$/', $e->errorCode)
                && ! preg_match('/^(sk|pk|whsk|whsec|unexpected)_/i', $e->errorCode)) {
                $failureCode .= ':' . $e->errorCode;
            }
            Log::warning('Online payment session could not be created', ['reference' => $transaction->reference, 'http_status' => $e->httpStatus, 'failure' => $failureCode]);
            $transaction->update(['status' => PaymentTransaction::STATUS_FAILED, 'failure_code' => $failureCode]);

            // The sanitized diagnostic travels with the exception for the pilot command only; no controller returns it.
            throw new OnlinePaymentException('The payment could not be started. Please try again.', 502, 'gateway_error', $e->diagnostic);
        }

        if ($session['livemode']) {
            // Never accept a live session in the sandbox, even if the provider hands one back.
            $this->tryExpire($session['id']);
            $transaction->update(['status' => PaymentTransaction::STATUS_FAILED, 'failure_code' => 'live_mode_rejected']);

            throw new OnlinePaymentException('The payment could not be started. Please try again.', 502, 'gateway_error');
        }

        $transaction->update([
            'provider_checkout_session_id' => $session['id'],
            'checkout_url' => $session['checkout_url'],
            'livemode' => false,
        ]);

        return $transaction->refresh();
    }

    /** @throws OnlinePaymentException */
    protected function assertAvailable(User $user): void
    {
        // A flag-off, wrong-environment or non-test-user request looks exactly like a missing route.
        if (! $this->availableFor($user)) {
            throw new OnlinePaymentException('Not found.', 404, 'not_found');
        }

        if ($this->guard->credentialsProblem() || $this->guard->webhookSecretProblem()) {
            throw new OnlinePaymentException('Online payment is not configured.', 503, 'not_configured');
        }
    }

    /** @throws OnlinePaymentException */
    protected function assertPayable(Payable $payable, Customer $customer): void
    {
        $members = Booking::whereIn('id', $payable->memberIds)->get();

        if ($members->contains(fn (Booking $member) => (int) $member->customer_id !== (int) $customer->id)) {
            throw new OnlinePaymentException('Booking not found.', 404, 'not_found');
        }

        $active = $members->whereIn('id', $payable->activeIds);

        // Sandbox money only ever moves on capstone demo bookings, which are already kept out of real revenue.
        if ($members->contains(fn (Booking $member) => ! $member->is_demo)) {
            throw new OnlinePaymentException('Online test payment is only available on demo bookings.', 409, 'not_demo_booking');
        }

        if ($active->isEmpty()) {
            throw new OnlinePaymentException('There is nothing to pay on this booking.', 409, 'nothing_to_pay');
        }

        // The final amount only exists once the tow has reached the drop-off.
        if ($active->contains(fn (Booking $member) => $member->status !== 'arrived_dropoff')) {
            throw new OnlinePaymentException('Online payment opens once the tow reaches the drop-off.', 409, 'not_ready');
        }

        // Payment already being handled the normal way (Team Leader / cash): do not collect twice.
        if ($active->contains(fn (Booking $member) => $member->payment_submitted_at !== null || $member->payment_method !== null || $member->cash_received !== null)) {
            throw new OnlinePaymentException('A payment has already been recorded for this booking.', 409, 'payment_already_recorded');
        }

        if ($payable->totalCentavos <= 0) {
            throw new OnlinePaymentException('There is nothing to pay on this booking.', 409, 'nothing_to_pay');
        }
    }

    // ── Customer-facing status / cancel ─────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function statusFor(User $user, string $bookingCode): ?array
    {
        $this->assertAvailable($user);

        $transaction = $this->ownedLatest($user, $bookingCode);

        if (! $transaction) {
            return null;
        }

        $bookingStatus = Booking::where('booking_code', $bookingCode)->value('status');

        return $this->presentTransaction($transaction) + [
            // Only an open session has a usable link; it is never shown for finished ones.
            'checkout_url' => $transaction->isOpen() ? $transaction->checkout_url : null,
            // Gateway success is not booking completion: the app shows both, separately.
            'booking_status' => $bookingStatus,
            'booking_completed' => $bookingStatus === 'completed',
            'note' => 'Verified online payment is recorded for the dispatcher. The booking is completed only after the dispatcher confirms payment.',
        ];
    }

    /** @return array<string, mixed> What the app may know about a transaction (never provider ids, secrets or links). */
    protected function presentTransaction(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'method' => $transaction->method,
            'amount' => Money::centavosToPesos($transaction->amount_centavos),
            'currency' => $transaction->currency,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'paid_at' => $transaction->paid_at?->toIso8601String(),
        ];
    }

    /**
     * What the customer app needs to decide whether to offer "Pay Now" for a booking it is displaying.
     * Server-decided, so the app never guesses eligibility. Null means "the online-payment sandbox is not
     * available to this user at all" and the app shows nothing.
     *
     * @return array<string, mixed>|null
     */
    public function offerFor(User $user, Booking $booking): ?array
    {
        if (! $this->availableFor($user) || $this->guard->credentialsProblem() || $this->guard->webhookSecretProblem()) {
            return null;
        }

        $customer = Customer::where('user_id', $user->id)->first();

        if (! $customer || (int) $booking->customer_id !== (int) $customer->id) {
            return null;
        }

        $payable = $this->resolver->resolve($booking);
        $latest = PaymentTransaction::where('payable_type', $payable->type)
            ->where('payable_id', $payable->anchorId)
            ->latest('id')
            ->first();

        $reason = null;

        if ($latest && $latest->status === PaymentTransaction::STATUS_PAID) {
            $reason = 'already_paid';
        } elseif ($latest && in_array($latest->status, [PaymentTransaction::STATUS_REVIEW, PaymentTransaction::STATUS_DUPLICATE], true)) {
            $reason = 'under_review';
        } elseif ($booking->preferred_payment_method === 'bank_transfer') {
            $reason = 'channel_unavailable'; // Online Banking is not wired yet
        } elseif ($booking->preferred_payment_method !== 'gcash') {
            $reason = 'preference_not_online'; // cash, or no preference recorded
        } else {
            try {
                $this->assertPayable($payable, $customer);
            } catch (OnlinePaymentException $e) {
                $reason = $e->reason;
            }
        }

        return [
            'available' => true,
            'eligible' => $reason === null,
            'reason' => $reason,
            'amount' => $payable->totalPesos,
            'currency' => 'PHP',
            'method' => 'gcash',
            'method_label' => Booking::preferredPaymentMethodLabel('gcash'),
            'is_group' => $payable->type === 'group',
            'transaction' => $latest ? $this->presentTransaction($latest) : null,
        ];
    }

    /** @throws OnlinePaymentException */
    public function cancel(User $user, string $bookingCode): PaymentTransaction
    {
        $this->assertAvailable($user);

        $transaction = $this->ownedLatest($user, $bookingCode);

        if (! $transaction || ! $transaction->isOpen()) {
            throw new OnlinePaymentException('There is no open online payment to cancel.', 404, 'not_found');
        }

        if ($transaction->provider_checkout_session_id) {
            try {
                $this->gateway->expireCheckoutSession($transaction->provider_checkout_session_id);
            } catch (GatewayException $e) {
                // A payment may be in flight. Do not claim "cancelled" unless the provider agrees.
                $this->reconcileOne($transaction);
                $transaction->refresh();

                if ($transaction->isOpen()) {
                    throw new OnlinePaymentException('The payment could not be cancelled right now.', 409, 'cannot_cancel');
                }

                return $transaction;
            }
        }

        $transaction->update(['status' => PaymentTransaction::STATUS_CANCELLED, 'failure_code' => null]);

        return $transaction->refresh();
    }

    protected function ownedLatest(User $user, string $bookingCode): ?PaymentTransaction
    {
        $customer = Customer::where('user_id', $user->id)->first();
        $booking = $customer
            ? Booking::where('booking_code', $bookingCode)->where('customer_id', $customer->id)->first()
            : null;

        if (! $booking) {
            throw new OnlinePaymentException('Booking not found.', 404, 'not_found');
        }

        $payable = $this->resolver->resolve($booking);

        return PaymentTransaction::where('payable_type', $payable->type)
            ->where('payable_id', $payable->anchorId)
            ->latest('id')
            ->first();
    }

    // ── Webhook ─────────────────────────────────────────────────────────────

    /** @return array{status: int, outcome: string} */
    public function handleWebhook(string $rawBody, ?string $signatureHeader): array
    {
        if (! $this->guard->enabled()) {
            return ['status' => 404, 'outcome' => 'disabled'];
        }

        if ($this->guard->webhookSecretProblem()) {
            return ['status' => 503, 'outcome' => 'not_configured'];
        }

        // Real events are a few KB. Refuse anything absurd before spending a hash on it.
        if (strlen($rawBody) > 1_000_000) {
            return ['status' => 413, 'outcome' => 'payload_too_large'];
        }

        // Signature first, over the raw bytes, before the body is parsed or trusted for anything.
        $verdict = $this->verifier->verify($rawBody, $signatureHeader);
        if (! $verdict['ok']) {
            return ['status' => 401, 'outcome' => 'invalid_signature'];
        }

        // PayMongo's documented event envelope: data.id (evt_…), data.attributes.type / .livemode / .data (the resource).
        $payload = json_decode($rawBody, true);
        $eventId = is_array($payload) ? ($payload['data']['id'] ?? null) : null;
        $type = is_array($payload) ? ($payload['data']['attributes']['type'] ?? null) : null;
        $livemode = is_array($payload) ? ($payload['data']['attributes']['livemode'] ?? null) : null;
        $resource = is_array($payload) ? ($payload['data']['attributes']['data'] ?? null) : null;

        if (! is_string($eventId) || ! preg_match('/^evt_\w{1,200}$/', $eventId)
            || ! is_string($type) || $type === '' || strlen($type) > 80
            || ! is_bool($livemode) || ! is_array($resource)) {
            return ['status' => 400, 'outcome' => 'malformed_event'];
        }

        // The sandbox only ever accepts test-mode events.
        if ($livemode) {
            return ['status' => 400, 'outcome' => 'live_event_rejected'];
        }

        $outcome = DB::transaction(function () use ($eventId, $type, $livemode, $resource, $rawBody) {
            $inserted = DB::table('payment_webhook_events')->insertOrIgnore([
                'provider' => 'paymongo',
                'provider_event_id' => $eventId,
                'event_type' => Str::limit($type, 80, ''),
                'livemode' => $livemode,
                'payload_hash' => hash('sha256', $rawBody),
                'outcome' => 'received',
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return ['outcome' => 'replay', 'transaction_id' => null];
            }

            [$outcome, $transactionId] = match ($type) {
                'checkout_session.payment.paid' => $this->onPaid($resource, $eventId),
                'payment.failed' => $this->onPaymentFailed($resource, $eventId),
                default => ['ignored_event_type', null],
            };

            DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->update([
                'outcome' => $outcome,
                'payment_transaction_id' => $transactionId,
                'updated_at' => now(),
            ]);

            return ['outcome' => $outcome, 'transaction_id' => $transactionId];
        });

        return ['status' => 200, 'outcome' => $outcome['outcome']];
    }

    /** @return array{0: string, 1: ?int} */
    protected function onPaid(array $session, string $eventId): array
    {
        $sessionId = $session['id'] ?? null;
        $transaction = is_string($sessionId) && preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)
            ? PaymentTransaction::where('provider_checkout_session_id', $sessionId)->lockForUpdate()->first()
            : null;

        if (! $transaction) {
            return ['unknown_session', null];
        }

        $payments = $session['attributes']['payments'] ?? [];
        $paid = collect(is_array($payments) ? $payments : [])
            ->first(fn ($payment) => is_array($payment) && ($payment['attributes']['status'] ?? null) === 'paid');

        // An event without a paid payment inside it proves nothing.
        if (! $paid || ! is_string($paid['id'] ?? null) || ! preg_match('/^pay_\w+$/', $paid['id'])) {
            return ['no_paid_payment_in_event', $transaction->id];
        }

        if (($paid['attributes']['livemode'] ?? false) === true || ($session['attributes']['livemode'] ?? false) === true) {
            return ['live_payment_rejected', $transaction->id];
        }

        $amount = $paid['attributes']['amount'] ?? null;
        if (! is_int($amount) || $amount <= 0) {
            return ['no_paid_payment_in_event', $transaction->id];
        }

        // Match the payment back to the order two ways: the session id (above) AND the reference number we
        // sent when creating it. A session whose reference is missing or different is never trusted as paid.
        $integrity = null;
        if (($session['attributes']['reference_number'] ?? null) !== $transaction->reference) {
            $integrity = 'reference_mismatch';
        } elseif (isset($paid['attributes']['currency']) && $paid['attributes']['currency'] !== 'PHP') {
            $integrity = 'currency_mismatch';
        }

        return [$this->applyPaid($transaction, $paid['id'], $amount, $eventId, $integrity), $transaction->id];
    }

    /** @return array{0: string, 1: ?int} */
    protected function onPaymentFailed(array $payment, string $eventId): array
    {
        $reference = $payment['attributes']['metadata']['payment_transaction'] ?? null;
        $transaction = is_string($reference)
            ? PaymentTransaction::where('reference', $reference)->lockForUpdate()->first()
            : null;

        if (! $transaction) {
            return ['unmapped_failure', null];
        }

        // One failed attempt does not end a hosted session (the customer may retry), so the
        // transaction stays open; we only remember why the last attempt failed.
        if ($transaction->isOpen()) {
            $transaction->update([
                'failure_code' => Str::limit((string) ($payment['attributes']['failed_code'] ?? 'payment_failed'), 60, ''),
                'last_event_id' => $eventId,
            ]);
        }

        return ['failure_recorded', $transaction->id];
    }

    /**
     * Turns verified provider evidence into the one place a transaction can become `paid`.
     * Runs inside the caller's transaction.
     */
    protected function applyPaid(PaymentTransaction $transaction, string $paymentId, int $amountCentavos, ?string $eventId, ?string $integrityFlag = null): string
    {
        $transaction = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

        if ($transaction->status === PaymentTransaction::STATUS_PAID) {
            if ($transaction->provider_payment_id === $paymentId) {
                return 'already_paid';
            }

            $transaction->update(['flag' => 'second_payment_same_session']);

            return 'flagged_second_payment';
        }

        if (PaymentTransaction::where('provider_payment_id', $paymentId)->where('id', '!=', $transaction->id)->exists()) {
            return 'payment_id_already_recorded';
        }

        $memberIds = $transaction->payable_snapshot['member_ids'] ?? [$transaction->booking_id];
        $members = Booking::whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
        $evidence = ['provider_payment_id' => $paymentId, 'last_event_id' => $eventId, 'paid_at' => now(), 'livemode' => false];

        if (in_array($transaction->status, [PaymentTransaction::STATUS_CANCELLED, PaymentTransaction::STATUS_EXPIRED, PaymentTransaction::STATUS_FAILED], true)) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_REVIEW, 'flag' => 'paid_after_close']);

            return 'review_paid_after_close';
        }

        $alreadyPaid = PaymentTransaction::where('payable_type', $transaction->payable_type)
            ->where('payable_id', $transaction->payable_id)
            ->where('status', PaymentTransaction::STATUS_PAID)
            ->where('id', '!=', $transaction->id)
            ->exists();

        if ($alreadyPaid) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_DUPLICATE, 'flag' => 'duplicate_payment']);

            return 'duplicate_payment';
        }

        // The provider's evidence does not line up with what we asked for: keep it for a human, never call it paid.
        if ($integrityFlag !== null) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_REVIEW, 'flag' => $integrityFlag]);

            return 'review_' . $integrityFlag;
        }

        if ($amountCentavos !== (int) $transaction->amount_centavos) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_REVIEW, 'flag' => 'amount_mismatch']);

            return 'review_amount_mismatch';
        }

        $activeIds = $transaction->payable_snapshot['active_ids'] ?? $memberIds;
        if ($members->whereIn('id', $activeIds)->contains(fn (Booking $member) => $member->status === 'cancelled')) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_REVIEW, 'flag' => 'booking_cancelled']);

            return 'review_booking_cancelled';
        }

        $anchor = $members->firstWhere('id', $transaction->booking_id);
        if ($anchor && $this->resolver->resolve($anchor)->totalCentavos !== (int) $transaction->amount_centavos) {
            $transaction->update($evidence + ['status' => PaymentTransaction::STATUS_REVIEW, 'flag' => 'superseded_total']);

            return 'review_superseded_total';
        }

        // Paid online AND cash/proof already recorded the normal way: genuine, but flagged so the dispatcher can decide on a refund.
        $collectedElsewhere = $members->contains(fn (Booking $member) => $member->payment_method === 'cash' || $member->cash_received !== null);

        $transaction->update($evidence + [
            'status' => PaymentTransaction::STATUS_PAID,
            'flag' => $collectedElsewhere ? 'cash_also_collected' : null,
        ]);

        return 'paid';
    }

    // ── Reconciliation (missed webhooks, expiry) ────────────────────────────

    /** @return array{checked: int, paid: int, expired: int, unconfirmed: int} */
    public function reconcile(): array
    {
        $summary = ['checked' => 0, 'paid' => 0, 'expired' => 0, 'unconfirmed' => 0];

        if (! $this->guard->enabled() || $this->guard->credentialsProblem()) {
            return $summary;
        }

        PaymentTransaction::where('status', PaymentTransaction::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (PaymentTransaction $transaction) use (&$summary) {
                $summary['checked']++;
                $result = $this->reconcileOne($transaction);
                $summary[$result] = ($summary[$result] ?? 0) + 1;
            });

        return $summary;
    }

    /** @return 'paid'|'expired'|'unconfirmed' */
    public function reconcileOne(PaymentTransaction $transaction): string
    {
        $sessionId = $transaction->provider_checkout_session_id;

        // Ask the provider what really happened before deciding anything: a missed webhook may hide a payment.
        if ($sessionId) {
            try {
                $session = $this->gateway->retrieveCheckoutSession($sessionId);
            } catch (GatewayException) {
                $session = null;
            }

            if ($session) {
                if ($session['livemode']) {
                    return 'unconfirmed';
                }

                $paid = collect($session['payments'])->firstWhere('status', 'paid');
                if ($paid && preg_match('/^pay_\w+$/', $paid['id']) && $paid['amount'] > 0) {
                    // A reference that is present but different, or a non-PHP currency, goes to review.
                    $integrity = ($session['reference'] ?? null) !== null && $session['reference'] !== $transaction->reference
                        ? 'reference_mismatch'
                        : ($paid['currency'] !== 'PHP' ? 'currency_mismatch' : null);

                    DB::transaction(fn () => $this->applyPaid($transaction, $paid['id'], $paid['amount'], null, $integrity));

                    return 'paid';
                }
            }
        }

        if (! $transaction->expires_at || $transaction->expires_at->isFuture()) {
            return 'unconfirmed';
        }

        if ($sessionId) {
            try {
                $this->gateway->expireCheckoutSession($sessionId);
            } catch (GatewayException $e) {
                // 400 = already expired / paid / being paid; anything else = we cannot tell. Keep it open and look again later.
                if ($e->httpStatus !== 400) {
                    return 'unconfirmed';
                }
            }
        }

        $transaction->refresh();
        if ($transaction->isOpen()) {
            $transaction->update(['status' => PaymentTransaction::STATUS_EXPIRED, 'flag' => $sessionId ? null : 'no_session']);
        }

        return 'expired';
    }

    protected function tryExpire(string $sessionId): void
    {
        try {
            $this->gateway->expireCheckoutSession($sessionId);
        } catch (GatewayException) {
            // Best effort: the local record is already closed, and a late payment would land in `review`.
        }
    }
}
