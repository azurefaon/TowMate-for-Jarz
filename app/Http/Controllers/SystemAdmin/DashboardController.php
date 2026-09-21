<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;

class DashboardController extends Controller
{
    protected const STAFF_ROLE_IDS = [1, 2, 3, 6];

    protected const SECURITY_ACTIONS = [
        'failed_login',
        'account_temporarily_locked',
        'unlock_otp_sent',
        'unlock_otp_failed',
        'account_unlocked_via_email',
        'login_success_after_recovery',
    ];

    protected const ADMIN_ACTIONS = [
        'user_registered',
        'user_updated',
        'user_archived',
        'user_restored',
        'user_queued_for_deletion',
        'user_deletion_cancelled',
        'user_status_toggled',
    ];

    public function index()
    {
        $staffQuery = User::whereIn('role_id', self::STAFF_ROLE_IDS)->whereNull('anonymized_at');

        $stats = [
            'active' => (clone $staffQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->where('status', 'active')->count(),
            'inactive' => (clone $staffQuery)->whereNull('archived_at')->whereNull('pending_delete_at')->where('status', 'inactive')->count(),
            'archived' => (clone $staffQuery)->whereNotNull('archived_at')->count(),
            'locked' => (clone $staffQuery)->whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
        ];

        $securityEvents = AuditLog::with('user')
            ->whereIn('action', self::SECURITY_ACTIONS)
            ->latest()
            ->take(8)
            ->get();

        $recentAdministration = AuditLog::with('user')
            ->where(function ($query) {
                $query->whereIn('action', self::ADMIN_ACTIONS)
                    ->orWhere('action', 'like', 'Toggled status for:%');
            })
            ->latest()
            ->take(8)
            ->get();

        return view('system-admin.dashboard', compact('stats', 'securityEvents', 'recentAdministration'));
    }
}
