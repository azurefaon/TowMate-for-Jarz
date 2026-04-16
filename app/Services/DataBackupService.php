<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Receipt;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class DataBackupService
{
    public function datasets(): array
    {
        return [
            'users' => [
                'label' => 'Users',
                'description' => 'All active and archived user accounts.',
                'count' => User::count(),
            ],
            'archived_users' => [
                'label' => 'Archived Users',
                'description' => 'Only archived and restored account records.',
                'count' => User::whereNotNull('archived_at')->count(),
            ],
            'bookings' => [
                'label' => 'Bookings',
                'description' => 'Booking lifecycle, notes, and assignment history.',
                'count' => Booking::count(),
            ],
            'receipts' => [
                'label' => 'Receipts',
                'description' => 'Financial receipts and completion evidence.',
                'count' => Receipt::count(),
            ],
            'audit_logs' => [
                'label' => 'Audit Logs',
                'description' => 'Who changed what across the system.',
                'count' => AuditLog::count(),
            ],
            'system_settings' => [
                'label' => 'System Settings',
                'description' => 'Business and platform configuration snapshots.',
                'count' => SystemSetting::count(),
            ],
        ];
    }

    public function allowedDatasets(): array
    {
        return array_keys($this->datasets());
    }

    public function generate(string $dataset, User $actor): string
    {
        $datasets = $this->datasets();

        if (! array_key_exists($dataset, $datasets)) {
            throw new InvalidArgumentException('Unsupported backup dataset.');
        }

        $records = $this->recordsFor($dataset);

        $payload = [
            'dataset' => $dataset,
            'label' => $datasets[$dataset]['label'],
            'generated_at' => now()->toIso8601String(),
            'generated_by' => [
                'id' => $actor->id,
                'name' => $actor->full_name ?: $actor->name,
                'email' => $actor->email,
            ],
            'total_records' => $records->count(),
            'records' => $records->values()->all(),
        ];

        $fileName = sprintf('%s_%s.json.enc', $dataset, now()->format('Ymd_His'));
        $path = 'backups/' . $dataset . '/' . $fileName;

        Storage::disk('local')->put(
            $path,
            Crypt::encryptString(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        );

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'backup_created',
            'entity_type' => 'Backup',
            'entity_id' => $actor->id,
        ]);

        return $path;
    }

    public function existingBackups(): Collection
    {
        return collect(Storage::disk('local')->allFiles('backups'))
            ->map(function (string $path) {
                return [
                    'path' => $path,
                    'file_name' => basename($path),
                    'dataset' => explode('/', $path)[1] ?? 'general',
                    'size_kb' => round(Storage::disk('local')->size($path) / 1024, 2),
                    'last_modified' => optional(now()->createFromTimestamp(Storage::disk('local')->lastModified($path)))->diffForHumans(),
                ];
            })
            ->sortByDesc('file_name')
            ->values();
    }

    protected function recordsFor(string $dataset): Collection
    {
        return match ($dataset) {
            'users' => User::with('role')->get()->map->toArray(),
            'archived_users' => User::with('role')->whereNotNull('archived_at')->get()->map->toArray(),
            'bookings' => Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt'])->get()->map->toArray(),
            'receipts' => Receipt::query()->get()->map->toArray(),
            'audit_logs' => AuditLog::with('user')->latest()->get()->map->toArray(),
            'system_settings' => SystemSetting::query()->get()->map->toArray(),
            default => collect(),
        };
    }
}
