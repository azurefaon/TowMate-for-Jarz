<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateProtectedFilesToPrivateDisk extends Command
{
    protected $signature = 'towmate:migrate-protected-files {--dry-run}';

    protected $description = 'Move existing task photos, signatures, payment proof, and generated PDFs from the public disk to the private disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $skipped = 0;
        $failed = 0;

        $bookingColumns = [
            'arrival_photo_path',
            'dropoff_photo_path',
            'payment_proof_path',
            'customer_signature_path',
            'initial_quote_path',
            'final_quote_path',
        ];

        Booking::query()
            ->where(function ($query) use ($bookingColumns) {
                foreach ($bookingColumns as $column) {
                    $query->orWhereNotNull($column);
                }
                $query->orWhereNotNull('vehicle_image_path');
            })
            ->chunkById(200, function ($bookings) use ($bookingColumns, $dryRun, &$moved, &$skipped, &$failed) {
                foreach ($bookings as $booking) {
                    foreach ($bookingColumns as $column) {
                        $path = $booking->{$column};

                        if (blank($path)) {
                            continue;
                        }

                        $result = $this->migratePath($path, $dryRun);

                        if ($result === 'moved') {
                            $moved++;
                        } elseif ($result === 'skipped') {
                            $skipped++;
                        } else {
                            $failed++;
                        }
                    }

                    foreach ($booking->vehicle_image_paths ?? [] as $imagePath) {
                        if (blank($imagePath)) {
                            continue;
                        }

                        $result = $this->migratePath($imagePath, $dryRun);

                        if ($result === 'moved') {
                            $moved++;
                        } elseif ($result === 'skipped') {
                            $skipped++;
                        } else {
                            $failed++;
                        }
                    }
                }
            });

        Receipt::query()->whereNotNull('pdf_path')->chunkById(200, function ($receipts) use ($dryRun, &$moved, &$skipped, &$failed) {
            foreach ($receipts as $receipt) {
                $path = $receipt->pdf_path;

                if (Str::startsWith($path, 'storage/')) {
                    $bare = Str::after($path, 'storage/');
                    $result = $this->migratePath($bare, $dryRun);

                    if ($result === 'moved') {
                        $moved++;
                        if (! $dryRun) {
                            $receipt->update(['pdf_path' => $bare]);
                        }
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
            }
        });

        Invoice::query()->whereNotNull('pdf_path')->chunkById(200, function ($invoices) use ($dryRun, &$moved, &$skipped, &$failed) {
            foreach ($invoices as $invoice) {
                $path = $invoice->pdf_path;

                if (Str::startsWith($path, 'storage/')) {
                    $bare = Str::after($path, 'storage/');
                    $result = $this->migratePath($bare, $dryRun);

                    if ($result === 'moved') {
                        $moved++;
                        if (! $dryRun) {
                            $invoice->update(['pdf_path' => $bare]);
                        }
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
            }
        });

        $this->info(($dryRun ? '[dry run] ' : '') . "Moved: {$moved}, skipped (already private or not found): {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migratePath(string $path, bool $dryRun): string
    {
        $path = ltrim($path, '/');

        if (Storage::disk('local')->exists($path)) {
            return 'skipped';
        }

        if (! Storage::disk('public')->exists($path)) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'moved';
        }

        try {
            $contents = Storage::disk('public')->get($path);
            Storage::disk('local')->put($path, $contents);
            Storage::disk('public')->delete($path);

            return 'moved';
        } catch (\Throwable) {
            return 'failed';
        }
    }
}
