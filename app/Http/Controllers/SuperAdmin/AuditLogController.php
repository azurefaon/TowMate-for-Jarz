<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{

    public function index(Request $request)
    {

        $query = AuditLog::with('user')->latest();

        $logs = $query->paginate(10);

        $totalLogs = AuditLog::count();

        $failedLogins = AuditLog::where('action', 'like', '%failed login%')->count();

        $jobActions = AuditLog::where('entity_type', 'Booking')->count();

        $systemChanges = AuditLog::where('entity_type', 'System')->count();

        return view('superadmin.audit.index', compact(
            'logs',
            'totalLogs',
            'failedLogins',
            'jobActions',
            'systemChanges'
        ));
    }
}
