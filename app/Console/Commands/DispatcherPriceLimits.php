<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

/*
 * The Owner settings page no longer shows the dispatcher price-limit fields (commit 4cff307), so the
 * limits that BookingService enforces can only be changed outside the UI. This command shows them and,
 * once the Owner has approved the numbers, sets them. It never invents a value: with no options it
 * only reports what is configured.
 */
class DispatcherPriceLimits extends Command
{
    protected $signature = 'towmate:price-limits
        {--max-discount= : Maximum dispatcher discount, in percent (0-100), as approved by the Owner}
        {--max-extra-charge= : Maximum dispatcher additional charge, in PHP, as approved by the Owner}
        {--clear : Remove both limits (no limit enforced)}';

    protected $description = 'Show or set the dispatcher discount / additional-charge limits (no values are assumed)';

    private const DISCOUNT_KEY = 'max_dispatcher_discount_percentage';

    private const CHARGE_KEY = 'max_additional_charge';

    public function handle(): int
    {
        $discount = $this->option('max-discount');
        $charge = $this->option('max-extra-charge');

        if ($this->option('clear')) {
            $this->store(self::DISCOUNT_KEY, '');
            $this->store(self::CHARGE_KEY, '');
        } else {
            if ($discount !== null) {
                if (! is_numeric($discount) || (float) $discount < 0 || (float) $discount > 100) {
                    $this->error('--max-discount must be a number from 0 to 100.');

                    return self::FAILURE;
                }
            }

            if ($charge !== null) {
                if (! is_numeric($charge) || (float) $charge < 0) {
                    $this->error('--max-extra-charge must be a number of 0 or more.');

                    return self::FAILURE;
                }
            }

            if ($discount !== null) {
                $this->store(self::DISCOUNT_KEY, (string) (float) $discount);
            }

            if ($charge !== null) {
                $this->store(self::CHARGE_KEY, (string) (float) $charge);
            }
        }

        $this->report('Dispatcher max discount (%)', self::DISCOUNT_KEY);
        $this->report('Dispatcher max additional charge (PHP)', self::CHARGE_KEY);

        return self::SUCCESS;
    }

    private function store(string $key, string $value): void
    {
        $old = SystemSetting::getValue($key);

        SystemSetting::setValue($key, $value);

        AuditLog::create([
            'action' => 'price_limit_updated',
            'entity_type' => 'SystemSetting',
            'reference' => $key,
            'description' => 'Changed from ' . (filled($old) ? $old : 'not set') . ' to ' . (filled($value) ? $value : 'not set') . ' via console.',
        ]);
    }

    private function report(string $label, string $key): void
    {
        $value = SystemSetting::getValue($key);

        $this->line(sprintf('%-42s %s', $label, filled($value) ? $value : 'NOT SET (no limit enforced)'));
    }
}
