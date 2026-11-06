<?php

namespace App\Services\OnlinePayments;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayMongo SANDBOX checkout: the customer pays when submitting a Book Now request (Step 3 "Pay & Submit"). GCash uses
 * a hosted Checkout Session; Online Banking uses a Payment Intent. Both end in the same applyPaid() and finalizePrepaid().
 *
 * Hard rules this class enforces:
 *  - a booking paid this way stays `awaiting_payment` (invisible to dispatch) until the payment is verified, and is
 *    then submitted exactly once by finalizePrepaid(); it never touches payment_method / preferred_payment_method;
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
        return $this->guard->enabled() && $this->guard->customerEligible($user);
    }

    /** Step 3 "Pay & Submit" can actually run for this user: enabled, an active Customer, and fully configured. */
    public function canPayAtBooking(?User $user): bool
    {
        return $this->availableFor($user) && ! $this->guard->credentialsProblem() && ! $this->guard->webhookSecretProblem();
    }

    /** A TowMate payment method that is wired to a PayMongo channel (GCash; Online Banking only when configured). */
    public function channelAvailable(?string $method): bool
    {
        if ($method === 'bank_transfer') {
            return $this->onlineBanks() !== [];
        }

        return $method !== null && is_string(config('paymongo.channels.' . $method)) && config('paymongo.channels.' . $method) !== '';
    }

    /** @return array<string, array{label: string, type: string, bank_code: string}>  the banks of the configured channel; none while it is off */
    public function onlineBanks(): array
    {
        $channel = config('paymongo.channels.bank_transfer');

        return array_filter((array) config('paymongo.online_banks'), fn (array $bank) => $bank['type'] === $channel);
    }

    /** A bank payment: the id is a PayMongo Payment Intent, not a Checkout Session. */
    protected function isBankIntent(?string $providerId): bool
    {
        return is_string($providerId) && str_starts_with($providerId, 'pi_');
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    /**
     * @return array{transaction: PaymentTransaction, created: bool}
     *
     * @throws OnlinePaymentException
     */
    public function initiate(User $user, string $bookingCode, string $method, ?string $bank = null): array
    {
        $this->assertAvailable($user);

        $channel = config('paymongo.channels.' . $method);
        if (! is_string($channel) || $channel === '') {
            throw new OnlinePaymentException('That payment channel is not available yet.', 422, 'channel_unavailable');
        }

        $bank = $method === 'bank_transfer' ? $bank : null;
        if ($method === 'bank_transfer' && ! array_key_exists((string) $bank, $this->onlineBanks())) {
            throw new OnlinePaymentException('Please choose your bank.', 422, 'bank_required');
        }

        $customer = Customer::where('user_id', $user->id)->first();
        $booking = $customer
            ? Booking::includingUnsubmitted()->where('booking_code', $bookingCode)->where('customer_id', $customer->id)->first()
            : null;

        if (! $customer || ! $booking) {
            throw new OnlinePaymentException('Booking not found.', 404, 'not_found');
        }

        // Moving to another method or bank closes the open attempt, and so does asking for a bank page again (a bank link
        // works only once), so first make sure it was not already paid.
        $payable = $this->resolver->resolve($booking);
        $open = PaymentTransaction::where('payable_type', $payable->type)->where('payable_id', $payable->anchorId)
            ->where('status', PaymentTransaction::STATUS_PENDING)->first();
        if ($open && ($open->method !== $method || $open->bank() !== $bank || $this->isBankIntent($open->provider_checkout_session_id))) {
            $this->syncWithProvider($open);
        }

        $staleSessionId = null;

        try {
            [$transaction, $created] = DB::transaction(function () use ($booking, $customer, $user, $method, $bank, &$staleSessionId) {
                // Lock every vehicle of the payable (ascending id, like the Team Leader flow) before deciding anything.
                $ids = $this->resolver->resolve($booking)->memberIds;
                Booking::includingUnsubmitted()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

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

                    $sameChannel = $pending->method === $method && $pending->bank() === $bank;

                    $singleUse = $this->isBankIntent($pending->provider_checkout_session_id);

                    if ($sameAmount && $sameChannel && ! $singleUse && $pending->expires_at && $pending->expires_at->isFuture()) {
                        return [$pending, false];
                    }

                    // Out of time, the total moved, another method or bank was chosen, or a used bank link: never reuse it.
                    $staleSessionId = $pending->provider_checkout_session_id;
                    $pending->update([
                        'status' => PaymentTransaction::STATUS_EXPIRED,
                        'flag' => match (true) {
                            ! $sameAmount => 'superseded_total',
                            ! $sameChannel => 'superseded_channel',
                            $pending->expires_at?->isFuture() => 'bank_link_replaced',
                            default => 'ttl_elapsed',
                        },
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
                    'payable_snapshot' => $payable->snapshot() + array_filter(['pay_at_booking' => true, 'bank' => $bank]),
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
        $metadata = [
            'payment_transaction' => $transaction->reference,
            'payable_type' => $transaction->payable_type,
            'payable_id' => (string) $transaction->payable_id,
        ];
        $description = 'Towing service ' . $booking->booking_code;
        $successUrl = config('paymongo.success_url') ?: $this->returnUrl('success', $booking);

        try {
            if ($transaction->method === 'bank_transfer') {
                $bank = $this->onlineBanks()[$transaction->bank()];
                $session = $this->gateway->createBankPayment([
                    'amount_centavos' => $transaction->amount_centavos,
                    'description' => $description,
                    'type' => $bank['type'],
                    'bank_code' => $bank['bank_code'],
                    'metadata' => $metadata,
                    'return_url' => $successUrl, // the only return link a bank has; never evidence of payment
                ]);
            } else {
                $session = $this->gateway->createCheckoutSession([
                    'amount_centavos' => $transaction->amount_centavos,
                    'name' => 'TowMate towing service',
                    'description' => $description,
                    'reference' => $transaction->reference,
                    'payment_method_types' => [$channel],
                    'metadata' => $metadata,
                    'success_url' => $successUrl,
                    'cancel_url' => config('paymongo.cancel_url') ?: $this->returnUrl('cancelled', $booking),
                ]);
            }
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
        $members = Booking::includingUnsubmitted()->whereIn('id', $payable->memberIds)->get();

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

        // Online payment is taken only when a Book Now request is submitted (Step 3 "Pay & Submit"). A booking that is
        // already submitted is paid in cash to the Team Leader; there is no pay-after-towing step any more.
        if ($active->contains(fn (Booking $member) => $member->status !== Booking::STATUS_AWAITING_PAYMENT)) {
            throw new OnlinePaymentException('Online payment is made when you submit a booking.', 409, 'not_awaiting_payment');
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

        // A late webhook must not leave the customer waiting: while the checkout is open, ask PayMongo directly (at
        // most every few seconds per transaction). The answer is provider evidence, never the app's word.
        if ($transaction->isOpen() && $transaction->provider_checkout_session_id
            && Cache::add('online-payment-sync:' . $transaction->id, 1, now()->addSeconds(4))) {
            $this->syncWithProvider($transaction);
            $transaction->refresh();
        }

        // Paid but not yet submitted (e.g. the finalization after the webhook failed): submit it now, exactly once.
        if ($transaction->status === PaymentTransaction::STATUS_PAID
            && Booking::includingUnsubmitted()->whereKey($transaction->booking_id)->value('status') === Booking::STATUS_AWAITING_PAYMENT) {
            $this->finalizePrepaid($transaction);
        }

        $bookingStatus = Booking::includingUnsubmitted()->whereKey($transaction->booking_id)->value('status');
        $submitted = $bookingStatus !== null && ! in_array($bookingStatus, Booking::UNSUBMITTED_STATUSES, true);

        return $this->presentTransaction($transaction) + [
            // Only an open session has a usable link; it is never shown for finished ones.
            'checkout_url' => $transaction->isOpen() ? $transaction->checkout_url : null,
            'booking_code' => Booking::includingUnsubmitted()->whereKey($transaction->booking_id)->value('booking_code'),
            'booking_status' => $bookingStatus,
            // Pay & Submit: the booking exists for dispatch only once this is true.
            'booking_submitted' => $submitted,
            'booking_completed' => $bookingStatus === 'completed',
            'pay_at_booking' => (bool) ($transaction->payable_snapshot['pay_at_booking'] ?? false),
            'test_mode' => true,
            'note' => 'TEST / DEMO — NO REAL MONEY. Your booking is submitted only after the payment is verified.',
        ];
    }

    /** @return array<string, mixed> What the app may know about a transaction (never provider ids, secrets or links). */
    protected function presentTransaction(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'method' => $transaction->method,
            'bank' => $transaction->bank(),
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

        // A submitted booking is never paid online afterwards (cash goes to the Team Leader). The booking detail only
        // reports a payment that was made at booking time.
        $reason = match (true) {
            $latest && $latest->status === PaymentTransaction::STATUS_PAID => 'already_paid',
            $latest && in_array($latest->status, [PaymentTransaction::STATUS_REVIEW, PaymentTransaction::STATUS_DUPLICATE], true) => 'under_review',
            default => 'pay_at_booking_only',
        };

        return [
            'available' => true,
            'eligible' => false,
            'reason' => $reason,
            'amount' => $payable->totalPesos,
            'currency' => 'PHP',
            'method' => $latest?->method ?? 'gcash',
            'method_label' => Booking::preferredPaymentMethodLabel($latest?->method ?? 'gcash'),
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
                $this->closeAtProvider($transaction->provider_checkout_session_id);
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
            ? Booking::includingUnsubmitted()->where('booking_code', $bookingCode)->where('customer_id', $customer->id)->first()
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
                'payment.paid' => $this->onBankPaid($resource, $eventId),
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

        // The payment is committed first, so it can never be lost; submitting the booking is a separate, idempotent
        // step (retried by the app's status check and by online-payments:reconcile if it fails here).
        if (in_array($outcome['outcome'], ['paid', 'already_paid'], true) && $outcome['transaction_id']) {
            $this->finalizePrepaid(PaymentTransaction::findOrFail($outcome['transaction_id']));
        }

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

    /**
     * A bank payment. PayMongo sends this event for every payment, GCash included; those belong to the Checkout Session
     * event above, so only a payment on one of our Payment Intents is handled here.
     *
     * @return array{0: string, 1: ?int}
     */
    protected function onBankPaid(array $payment, string $eventId): array
    {
        $intentId = $payment['attributes']['payment_intent_id'] ?? null;
        $transaction = $this->isBankIntent($intentId) && preg_match('/^pi_\w+$/', $intentId)
            ? PaymentTransaction::where('provider_checkout_session_id', $intentId)->lockForUpdate()->first()
            : null;

        if (! $transaction) {
            return ['not_a_bank_payment', null];
        }

        $paymentId = $payment['id'] ?? null;
        $amount = $payment['attributes']['amount'] ?? null;

        if (! is_string($paymentId) || ! preg_match('/^pay_\w+$/', $paymentId)
            || ($payment['attributes']['status'] ?? null) !== 'paid' || ! is_int($amount) || $amount <= 0) {
            return ['no_paid_payment_in_event', $transaction->id];
        }

        if (($payment['attributes']['livemode'] ?? null) !== false) {
            return ['live_payment_rejected', $transaction->id];
        }

        // Matched by the intent id above AND the reference we sent in its metadata.
        $integrity = null;
        if (($payment['attributes']['metadata']['payment_transaction'] ?? null) !== $transaction->reference) {
            $integrity = 'reference_mismatch';
        } elseif (($payment['attributes']['currency'] ?? null) !== 'PHP') {
            $integrity = 'currency_mismatch';
        }

        return [$this->applyPaid($transaction, $paymentId, $amount, $eventId, $integrity), $transaction->id];
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
            // A failed bank payment does end its Payment Intent attempt: the bank link is dead, so a retry starts afresh.
            $endsAttempt = $this->isBankIntent($transaction->provider_checkout_session_id)
                && ($payment['attributes']['payment_intent_id'] ?? null) === $transaction->provider_checkout_session_id;

            $transaction->update([
                'failure_code' => Str::limit((string) ($payment['attributes']['failed_code'] ?? 'payment_failed'), 60, ''),
                'last_event_id' => $eventId,
            ] + ($endsAttempt ? ['status' => PaymentTransaction::STATUS_FAILED] : []));
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
        $members = Booking::includingUnsubmitted()->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
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
        if ($members->whereIn('id', $activeIds)->contains(fn (Booking $member) => in_array($member->status, ['cancelled', Booking::STATUS_PAYMENT_ABANDONED], true))) {
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

    /** @return array{checked: int, paid: int, expired: int, unconfirmed: int, submitted: int} */
    public function reconcile(): array
    {
        $summary = ['checked' => 0, 'paid' => 0, 'expired' => 0, 'unconfirmed' => 0, 'submitted' => 0];

        if (! $this->guard->enabled() || $this->guard->credentialsProblem()) {
            return $summary;
        }

        // Recovery: a verified payment whose booking was not submitted (finalization failed earlier).
        PaymentTransaction::where('status', PaymentTransaction::STATUS_PAID)
            ->whereIn('booking_id', Booking::includingUnsubmitted()->where('status', Booking::STATUS_AWAITING_PAYMENT)->select('id'))
            ->orderBy('id')
            ->each(function (PaymentTransaction $transaction) use (&$summary) {
                if ($this->finalizePrepaid($transaction)) {
                    $summary['submitted']++;
                }
            });

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
        $provider = $this->syncWithProvider($transaction);
        if ($provider === 'paid') {
            return 'paid';
        }
        if ($provider === 'live') {
            return 'unconfirmed'; // never act on a live-mode session, not even to expire it
        }

        if (! $transaction->expires_at || $transaction->expires_at->isFuture()) {
            return 'unconfirmed';
        }

        if ($sessionId) {
            try {
                $this->closeAtProvider($sessionId);
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

    /**
     * Retrieves the checkout session from PayMongo and, if it holds a paid payment, records it through applyPaid()
     * (the same rules as the webhook) and submits the booking.
     *
     * @return 'paid'|'live'|null  `paid` when the provider reported a payment, `live` for a live-mode session (never
     *                             trusted or acted on), null when there is nothing to record or the provider could not be asked
     */
    protected function syncWithProvider(PaymentTransaction $transaction): ?string
    {
        $sessionId = $transaction->provider_checkout_session_id;
        if (! $sessionId) {
            return null;
        }

        try {
            $session = $this->isBankIntent($sessionId)
                ? $this->gateway->retrieveBankPayment($sessionId)
                : $this->gateway->retrieveCheckoutSession($sessionId);
        } catch (GatewayException) {
            return null;
        }

        if ($session['livemode']) {
            return 'live';
        }

        $paid = collect($session['payments'])->firstWhere('status', 'paid');
        if (! $paid || ! preg_match('/^pay_\w+$/', $paid['id']) || $paid['amount'] <= 0) {
            if ($this->isBankIntent($sessionId)) {
                $this->closeEndedBankAttempt($transaction, $session);
            }

            return null;
        }

        // A reference that is different (for a bank, or missing), or a non-PHP currency, goes to review.
        $referenceDiffers = $session['reference'] !== $transaction->reference
            && ($session['reference'] !== null || $this->isBankIntent($sessionId));

        $integrity = $referenceDiffers
            ? 'reference_mismatch'
            : ($paid['currency'] !== 'PHP' ? 'currency_mismatch' : null);

        $outcome = DB::transaction(fn () => $this->applyPaid($transaction, $paid['id'], $paid['amount'], null, $integrity));

        if (in_array($outcome, ['paid', 'already_paid'], true)) {
            $this->finalizePrepaid($transaction->refresh());
        }

        return 'paid';
    }

    /**
     * A Payment Intent that is back to `awaiting_payment_method` has no live bank attempt: a failed one returns there, and
     * so does one abandoned until its bank session ends. The customer retries with a new attempt.
     */
    protected function closeEndedBankAttempt(PaymentTransaction $transaction, array $intent): void
    {
        $update = match ($intent['status']) {
            'awaiting_payment_method' => ['status' => PaymentTransaction::STATUS_FAILED, 'failure_code' => Str::limit($intent['failure'] ?? 'bank_attempt_ended', 60, '')],
            'cancelled' => ['status' => PaymentTransaction::STATUS_CANCELLED],
            default => null,
        };

        if ($update !== null) {
            PaymentTransaction::whereKey($transaction->id)->where('status', PaymentTransaction::STATUS_PENDING)->update($update);
        }
    }

    // ── Pay at booking: submission, abandoned attempts, Team Leader collection ──

    /**
     * Submits a pay-at-booking request once its payment is verified: the hidden `awaiting_payment` rows become a
     * normal confirmed Book Now booking with an accepted quotation at exactly the amount that was paid, ready for the
     * dispatcher to assign. Idempotent (row locks + status check), so the webhook, the app's status check and the
     * reconcile command can all call it. Runs after the payment is committed: a failure here never loses the payment;
     * it is logged for recovery and retried.
     *
     * @return bool true when this call submitted the booking
     */
    public function finalizePrepaid(PaymentTransaction $transaction): bool
    {
        try {
            $primary = DB::transaction(function () use ($transaction) {
                $memberIds = $transaction->payable_snapshot['member_ids'] ?? [$transaction->booking_id];
                $members = Booking::includingUnsubmitted()->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
                $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== PaymentTransaction::STATUS_PAID) {
                    return null;
                }

                $awaiting = $members->where('status', Booking::STATUS_AWAITING_PAYMENT);
                if ($awaiting->isEmpty()) {
                    return null; // already submitted by an earlier call
                }

                $primary = $members->firstWhere('id', $locked->booking_id) ?? $members->first();
                $lineItems = collect($primary->extra_vehicles ?? [])->filter(fn ($ev) => ! empty($ev['booking_id']))->values()->all();

                $quotation = app(\App\Services\QuotationService::class)->createQuotation([
                    'source_booking_id' => $primary->id,
                    'customer_id' => $primary->customer_id,
                    'truck_type_id' => $primary->truck_type_id,
                    'pickup_address' => $primary->pickup_address,
                    'dropoff_address' => $primary->dropoff_address,
                    'distance_km' => $primary->distance_km,
                    'estimated_price' => Money::centavosToPesos($locked->amount_centavos),
                    'service_type' => $primary->service_type,
                    'vehicle_image_path' => $primary->vehicle_image_path,
                    'extra_vehicles' => $lineItems !== [] ? $lineItems : null,
                    'pickup_notes' => $primary->notes,
                    'preferred_payment_method' => $primary->preferred_payment_method,
                ]);
                $quotation->update([
                    'status' => 'accepted',
                    'sent_at' => now(),
                    'responded_at' => now(),
                    'response_note' => 'Paid online at booking (PayMongo TEST mode, ' . $locked->reference . ').',
                ]);

                foreach ($awaiting as $member) {
                    $member->update([
                        'status' => 'confirmed',
                        'quotation_id' => $quotation->id,
                        'customer_approved_at' => now(),
                        'price_locked_at' => now(),
                    ]);
                }

                \App\Models\AuditLog::create([
                    'user_id' => $locked->initiated_by_user_id,
                    'action' => 'prepaid_booking_submitted',
                    'entity_type' => 'Booking',
                    'entity_id' => $primary->id,
                    'reference' => $primary->booking_code,
                    'description' => 'Booking submitted after a verified PayMongo TEST payment (' . $locked->reference . ').',
                ]);

                return $primary->fresh(['customer', 'truckType']);
            });
        } catch (\Throwable $e) {
            Log::error('Prepaid booking could not be submitted after a verified payment; it will be retried', [
                'reference' => $transaction->reference,
                'booking_id' => $transaction->booking_id,
                'error' => class_basename($e),
            ]);

            return false;
        }

        if (! $primary) {
            return false;
        }

        // Announced only now, after verified payment AND submission (the `created` observer skipped it).
        try {
            \App\Models\DispatcherNotification::create([
                'type' => \App\Models\DispatcherNotification::TYPE_NEW_BOOK_NOW,
                'booking_id' => $primary->id,
            ]);
        } catch (\Throwable) {
        }
        \App\Events\BookingStatusUpdated::safeFire($primary);
        if ($primary->customer?->user_id) {
            \App\Services\CustomerNotificationService::send(
                userId: $primary->customer->user_id,
                type: 'booking_update',
                title: 'Booking submitted',
                body: 'Payment received (TEST / DEMO — no real money). Booking ' . $primary->booking_code . ' is submitted; we will notify you when a team is assigned.',
                bookingCode: $primary->booking_code,
            );
        }
        app(\App\Services\Push\BookingStatusPush::class)->notifyEvent($primary, 'submitted_paid');

        return true;
    }

    /**
     * Before a customer submits another booking: their earlier pay-at-booking attempt that was never paid is closed
     * (its checkout expired) so it can never be paid and submitted alongside the new one. If it turns out to be paid,
     * it is submitted instead and its booking code returned, so the caller does not create a second booking.
     *
     * @throws OnlinePaymentException when a payment on the earlier attempt may still be going through
     */
    public function closeUnpaidAttempts(Customer $customer): ?string
    {
        $awaiting = Booking::includingUnsubmitted()
            ->where('customer_id', $customer->id)
            ->where('status', Booking::STATUS_AWAITING_PAYMENT)
            ->orderBy('id')
            ->get();

        $handled = [];
        foreach ($awaiting as $booking) {
            $payable = $this->resolver->resolve($booking);
            if (in_array($payable->anchorId, $handled, true)) {
                continue;
            }
            $handled[] = $payable->anchorId;

            $forPayable = fn () => PaymentTransaction::where('payable_type', $payable->type)->where('payable_id', $payable->anchorId);

            foreach ($forPayable()->where('status', PaymentTransaction::STATUS_PENDING)->get() as $open) {
                $this->syncWithProvider($open);
                $open->refresh();
                if (! $open->isOpen()) {
                    continue;
                }

                if ($open->provider_checkout_session_id) {
                    try {
                        $this->closeAtProvider($open->provider_checkout_session_id);
                    } catch (GatewayException) {
                        throw new OnlinePaymentException('Your earlier online payment is still being processed. Please wait a moment and try again.', 409, 'payment_in_progress');
                    }
                }
                $open->update(['status' => PaymentTransaction::STATUS_CANCELLED, 'flag' => 'superseded_by_new_booking']);
            }

            $paid = $forPayable()->where('status', PaymentTransaction::STATUS_PAID)->first();
            if ($paid) {
                $this->finalizePrepaid($paid);

                return Booking::includingUnsubmitted()->whereKey($payable->anchorId)->value('booking_code');
            }

            Booking::includingUnsubmitted()
                ->whereIn('id', $payable->memberIds)
                ->where('status', Booking::STATUS_AWAITING_PAYMENT)
                ->update(['status' => Booking::STATUS_PAYMENT_ABANDONED, 'updated_at' => now()]);
        }

        return null;
    }

    /**
     * How the Team Leader handles payment at the drop-off, decided only from verified gateway records (never from the
     * app or the customer's preference):
     *  - `cash`           no online payment exists: collect cash (amount, photo, signature) for dispatcher verification;
     *  - `online_prepaid` a verified TEST payment covers the current total: no collection, complete directly;
     *  - `online_review`  an online payment exists but does not cleanly cover the total: nothing may be collected or
     *                     completed until the dispatcher sorts it out (prevents double collection).
     *
     * @return array{mode: string, amount_due: string, amount_paid: ?string, method: ?string, reference: ?string, message: ?string}
     */
    public function collectionStateFor(Booking $booking): array
    {
        $payable = $this->resolver->resolve($booking);
        $transactions = PaymentTransaction::where('payable_type', $payable->type)
            ->where('payable_id', $payable->anchorId)
            ->whereIn('status', [PaymentTransaction::STATUS_PAID, PaymentTransaction::STATUS_REVIEW, PaymentTransaction::STATUS_DUPLICATE])
            ->orderBy('id')
            ->get();

        $base = ['amount_due' => $payable->totalPesos, 'amount_paid' => null, 'method' => null, 'reference' => null, 'message' => null];

        if ($transactions->isEmpty()) {
            return ['mode' => 'cash'] + $base;
        }

        $paid = $transactions->firstWhere('status', PaymentTransaction::STATUS_PAID);
        $state = $base;
        if ($paid) {
            $state['amount_paid'] = Money::centavosToPesos($paid->amount_centavos);
            $state['method'] = $paid->method;
            $state['reference'] = $paid->reference;
        }

        if ($paid && ! $paid->livemode && (int) $paid->amount_centavos >= $payable->totalCentavos
            && ! $transactions->contains('status', PaymentTransaction::STATUS_REVIEW)) {
            return ['mode' => 'online_prepaid'] + $state;
        }

        $state['message'] = $paid
            ? 'The online payment (₱' . $state['amount_paid'] . ') does not cover the current total of ₱' . $payable->totalPesos . '. Do not collect payment; contact the dispatcher.'
            : 'An online payment for this booking needs dispatcher review. Do not collect payment; contact the dispatcher.';

        return ['mode' => 'online_review'] + $state;
    }

    /** @throws GatewayException */
    protected function closeAtProvider(string $providerId): void
    {
        if ($this->isBankIntent($providerId)) {
            $this->gateway->cancelBankPayment($providerId);
        } else {
            $this->gateway->expireCheckoutSession($providerId);
        }
    }

    protected function tryExpire(string $sessionId): void
    {
        try {
            $this->closeAtProvider($sessionId);
        } catch (GatewayException) {
            // Best effort: the local record is already closed, and a late payment would land in `review`.
        }
    }
}
