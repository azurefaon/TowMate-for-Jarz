<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OnlinePayments\OnlinePaymentException;
use App\Services\OnlinePayments\OnlinePaymentService;
use App\Services\OnlinePayments\SandboxGuard;
use Illuminate\Console\Command;

/**
 * Opens ONE real PayMongo TEST-mode checkout session for a prepared test booking, through the same
 * code path the app uses. It contacts PayMongo's API, so it refuses to run without --confirm-sandbox
 * and without a complete, test-only configuration. Never run it against a live key (the guard
 * rejects those) or for a real customer's booking.
 */
class PilotOnlinePaymentCheckout extends Command
{
    protected $signature = 'online-payments:pilot-checkout
        {booking_code : Booking code of the prepared test booking (must be awaiting payment)}
        {--user= : Id of the allow-listed test customer who owns that booking}
        {--confirm-sandbox : Required. Acknowledges this makes a real call to PayMongo TEST mode}';

    protected $description = 'Open one real PayMongo TEST-mode GCash checkout for a prepared test booking (explicit confirmation required)';

    public function handle(OnlinePaymentService $payments, SandboxGuard $guard): int
    {
        if (! $this->option('confirm-sandbox')) {
            $this->error('Refusing to run: this contacts PayMongo TEST mode. Re-run with --confirm-sandbox once the test environment is intentionally configured.');

            return self::FAILURE;
        }

        // A real call to PayMongo is only ever made from a throw-away database, never the shared dev or production one.
        if (! $guard->databaseIsolated()) {
            $this->error('Refusing to run: the database "' . $guard->databaseName() . '" is not a clearly isolated pilot/testing database (name must end in _testing, _pilot, _sandbox or _isolated).');

            return self::FAILURE;
        }

        if (! $guard->enabled() || $guard->credentialsProblem() || $guard->webhookSecretProblem()) {
            $this->error('The sandbox is not fully configured. Run `php artisan online-payments:check` to see what is missing.');

            return self::FAILURE;
        }

        $user = User::find((int) $this->option('user'));

        if (! $user || ! $guard->userAllowed($user)) {
            $this->error('--user must be the id of an allow-listed sandbox test customer.');

            return self::FAILURE;
        }

        try {
            $result = $payments->initiate($user, (string) $this->argument('booking_code'), 'gcash');
        } catch (OnlinePaymentException $e) {
            $this->error("Not started: {$e->getMessage()} ({$e->reason})");

            // The customer-facing message is deliberately generic; for the operator, show the coarse failure code
            // recorded on the attempt (e.g. provider_rejected:<identifier>), which never contains a secret.
            $failure = \App\Models\PaymentTransaction::where('status', 'failed')->latest('id')->value('failure_code');
            if ($failure) {
                $this->line("Recorded failure: {$failure}");
            }

            if ($e->diagnostic) {
                $this->line("Provider diagnostic (structure and error text only; credentials redacted): {$e->diagnostic}");
            }

            return self::FAILURE;
        }

        $transaction = $result['transaction'];

        $this->info($result['created'] ? 'Test checkout created.' : 'An open test checkout already exists.');
        $this->line('Reference : ' . $transaction->reference);
        $this->line('Amount    : PHP ' . number_format($transaction->amount_centavos / 100, 2));
        $this->line('Open this hosted TEST page and choose Authorize (success) or Fail: ' . $transaction->checkout_url);
        $this->line('The booking is not changed by payment. Check the transaction with: php artisan tinker -> App\\Models\\PaymentTransaction::where(\'reference\', \'' . $transaction->reference . '\')->first()');

        return self::SUCCESS;
    }
}
