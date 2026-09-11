<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityMonitorController extends Controller
{
    protected const SECURITY_CATEGORIES = ['login_failed', 'security'];

    protected const STAFF_ROLE_IDS = [1, 2, 3, 6];

    public function index(Request $request)
    {
        $lockedStaffCount = User::whereIn('role_id', self::STAFF_ROLE_IDS)
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->count();

        $failedLoginsToday = AuditLog::where('action', 'failed_login')
            ->whereDate('created_at', today())
            ->count();

        $recoveriesTotal = AuditLog::where('action', 'account_unlocked_via_email')->count();

        $securityEventsToday = AuditLog::whereIn('category', self::SECURITY_CATEGORIES)
            ->whereDate('created_at', today())
            ->count();

        $events = AuditLog::with(['user.role'])
            ->whereIn('category', self::SECURITY_CATEGORIES)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $lockedStaff = User::whereIn('role_id', self::STAFF_ROLE_IDS)
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->with('role')
            ->orderByDesc('locked_until')
            ->get();

        return view('system-admin.security.monitor', [
            'lockedStaffCount' => $lockedStaffCount,
            'failedLoginsToday' => $failedLoginsToday,
            'recoveriesTotal' => $recoveriesTotal,
            'securityEventsToday' => $securityEventsToday,
            'events' => $events,
            'lockedStaff' => $lockedStaff,
        ]);
    }
}
