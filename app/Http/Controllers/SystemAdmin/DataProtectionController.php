<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\DataBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataProtectionController extends Controller
{
    protected DataBackupService $backupService;

    public function __construct(DataBackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    public function index()
    {
        $datasets = $this->backupService->datasets();
        $backups = $this->backupService->existingBackups();

        $archiveSummary = [
            'archived_users' => User::whereNotNull('archived_at')->count(),
            'completed_bookings' => Booking::where('status', 'completed')->count(),
            'cancelled_bookings' => Booking::where('status', 'cancelled')->count(),
        ];

        $systemInfo = [
            'environment' => app()->environment(),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'database_available' => $this->databaseAvailable(),
            'storage_writable' => $this->storageWritable(),
        ];

        return view('system-admin.maintenance.index', compact('datasets', 'backups', 'archiveSummary', 'systemInfo'));
    }

    protected function databaseAvailable(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function storageWritable(): bool
    {
        try {
            return Storage::disk('local')->exists('') || is_writable(storage_path('app'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'dataset' => ['required', Rule::in($this->backupService->allowedDatasets())],
        ]);

        $this->backupService->generate($validated['dataset'], $request->user());

        return redirect()
            ->route('system-admin.maintenance.index')
            ->with('success', 'Encrypted backup created successfully.');
    }

    public function download(Request $request): BinaryFileResponse
    {
        $path = (string) $request->query('file');

        abort_unless(str_starts_with($path, 'backups/'), 403);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), basename($path));
    }
}
