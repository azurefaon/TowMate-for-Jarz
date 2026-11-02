<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\OnlinePayments\SandboxGuard;
use Illuminate\Console\Command;

/**
 * Reports whether this environment is set up for a controlled PayMongo SANDBOX pilot. Makes no
 * network call and never prints a secret: only yes/no, counts, names of non-secret settings and the
 * KIND of key (test / live / unknown).
 */
class CheckOnlinePaymentsReadiness extends Command
{
    protected $signature = 'online-payments:check';

    protected $description = 'Check PayMongo sandbox configuration (no network, prints no secret values)';

    public function handle(SandboxGuard $guard): int
    {
        $blockers = [];
        $yes = fn (bool $ok) => $ok ? 'yes' : 'NO';

        $enabledFlag = (bool) config('paymongo.enabled');
        $envAllowed = $guard->environmentAllowed();
        $keyProblem = $guard->credentialsProblem();
        $secretKey = (string) config('paymongo.secret_key');
        $keyKind = $secretKey === '' ? 'missing' : (str_starts_with($secretKey, 'sk_test_') ? 'test' : (str_starts_with($secretKey, 'sk_live_') ? 'LIVE (rejected)' : 'unrecognised (rejected)'));
        $webhookProblem = $guard->webhookSecretProblem();
        $isolated = $guard->databaseIsolated();
        $appUrl = rtrim((string) config('app.url'), '/');
        $publicHttps = $this->isPublicHttps($appUrl);
        $webhookUrl = $appUrl . '/api/webhooks/paymongo';
        $successUrl = (string) config('paymongo.success_url');
        $cancelUrl = (string) config('paymongo.cancel_url');
        [$testUsers, $payableBookings] = $this->testFixtures();

        $rows = [
            ['Sandbox feature flag (PAYMONGO_SANDBOX_ENABLED)', $enabledFlag ? 'on' : 'off'],
            ['Environment', app()->environment() . ' (allowed: ' . $yes($envAllowed) . ')'],
            ['Database', $guard->databaseName() . ' (isolated throw-away database: ' . $yes($isolated) . ')'],
            ['Secret key (PAYMONGO_SECRET_KEY)', $keyKind],
            ['Webhook secret (PAYMONGO_WEBHOOK_SECRET)', $webhookProblem ? 'missing' : 'present'],
            ['Allow-listed test customers configured', (string) (count((array) config('paymongo.test_user_ids')) + count((array) config('paymongo.test_user_emails')))],
            ['  ...of which exist in this database', $testUsers === null ? 'unknown (database unreachable)' : (string) $testUsers],
            ['  ...synthetic bookings awaiting payment for them', $payableBookings === null ? 'unknown' : (string) $payableBookings],
            ['Enabled channels', implode(', ', array_keys((array) config('paymongo.channels'))) . (isset(config('paymongo.channels')['bank_transfer']) ? '' : ' (Online Banking is off: PAYMONGO_ONLINE_BANKING_CHANNEL not set)')],
            ['Webhook URL to register (from APP_URL)', $webhookUrl],
            ['APP_URL is a public https URL on a domain name', $yes($publicHttps)],
            ['Success / cancel return URLs', ($successUrl !== '' ? 'success set' : 'success unset') . ', ' . ($cancelUrl !== '' ? 'cancel set' : 'cancel unset') . '; https: ' . $yes($this->bothHttps($successUrl, $cancelUrl))],
        ];

        $this->table(['Check', 'Result'], $rows);

        if (! $enabledFlag) {
            $blockers[] = 'Set PAYMONGO_SANDBOX_ENABLED=true.';
        }
        if ($guard->isProduction() && ! $envAllowed) {
            $blockers[] = 'Production runs the sandbox only with PAYMONGO_SANDBOX_ALLOW_PRODUCTION=true (listing it in PAYMONGO_SANDBOX_ENVIRONMENTS is not enough).';
        } elseif (! $envAllowed) {
            $blockers[] = 'This APP_ENV is not listed in PAYMONGO_SANDBOX_ENVIRONMENTS.';
        }
        if (! $isolated) {
            $blockers[] = 'The database name does not end in _testing, _pilot, _sandbox or _isolated. Never point the pilot at the shared development or any production database.';
        }
        if ($keyProblem) {
            $blockers[] = 'Provide an sk_test_ secret key in PAYMONGO_SECRET_KEY (' . $keyProblem . ').';
        }
        if ($webhookProblem) {
            $blockers[] = 'Provide the webhook endpoint secret in PAYMONGO_WEBHOOK_SECRET (shown once when the webhook is created in the dashboard).';
        }
        if (count((array) config('paymongo.test_user_ids')) + count((array) config('paymongo.test_user_emails')) === 0) {
            $blockers[] = 'Allow-list a dedicated test customer (PAYMONGO_SANDBOX_TEST_USER_IDS or _EMAILS).';
        } elseif ($testUsers === 0) {
            $blockers[] = 'None of the allow-listed test customers exist in this database. Run online-payments:make-pilot-fixture.';
        } elseif ($payableBookings === 0) {
            $blockers[] = 'The test customer has no synthetic booking awaiting payment. Run online-payments:make-pilot-fixture.';
        }
        if (! $publicHttps) {
            $blockers[] = 'PayMongo only accepts a public https:// webhook URL on a domain name. Point APP_URL at your tunnel URL for the pilot.';
        }

        if ($blockers === []) {
            $this->info('Ready for a controlled sandbox pilot. Register the webhook URL above in PayMongo TEST mode before opening a checkout.');

            return self::SUCCESS;
        }

        $this->warn('Not ready:');
        foreach ($blockers as $blocker) {
            $this->line(' - ' . $blocker);
        }

        return self::FAILURE;
    }

    private function isPublicHttps(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && $host !== ''
            && str_contains($host, '.')
            && ! filter_var($host, FILTER_VALIDATE_IP)
            && ! in_array($host, ['localhost'], true)
            && ! str_ends_with($host, '.local');
    }

    private function bothHttps(string $success, string $cancel): bool
    {
        return str_starts_with($success, 'https://') && str_starts_with($cancel, 'https://');
    }

    /** @return array{0: ?int, 1: ?int} allow-listed customers found, and their synthetic bookings awaiting payment (pay at booking) */
    private function testFixtures(): array
    {
        try {
            $ids = (array) config('paymongo.test_user_ids');
            $emails = (array) config('paymongo.test_user_emails');

            if ($ids === [] && $emails === []) {
                return [0, 0];
            }

            $users = User::query()->where(function ($query) use ($ids, $emails) {
                $query->whereIn('id', $ids ?: [0])->orWhereIn(\DB::raw('lower(email)'), $emails ?: ['']);
            });
            $userIds = $users->pluck('id');
            $customerIds = Customer::whereIn('user_id', $userIds)->pluck('id');

            return [
                $userIds->count(),
                Booking::includingUnsubmitted()->whereIn('customer_id', $customerIds)->where('status', Booking::STATUS_AWAITING_PAYMENT)->count(),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
