<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\DataBackupService;
use Illuminate\Http\Request;
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

        return view('superadmin.protection.index', compact('datasets', 'backups', 'archiveSummary'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'dataset' => ['required', Rule::in($this->backupService->allowedDatasets())],
        ]);

        $this->backupService->generate($validated['dataset'], $request->user());

        return redirect()
            ->route('superadmin.backups.index')
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
