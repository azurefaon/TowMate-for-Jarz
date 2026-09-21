<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    protected const CATEGORIES = [
        'login' => 'Login',
        'logout' => 'Logout',
        'login_failed' => 'Failed Login',
        'security' => 'Security',
        'create' => 'Create',
        'update' => 'Update',
        'archive' => 'Archive',
        'restore' => 'Restore',
        'delete' => 'Delete',
        'status_change' => 'Status Change',
        'system' => 'System',
    ];

    public function index(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);

        $category = (string) $request->query('category', '');
        $userId = $request->query('user_id', '');
        $search = trim((string) $request->query('search', ''));

        $logs = AuditLog::with(['user.role'])
            ->whereNotIn('action', AuditLogService::BUSINESS_ACTIONS)
            ->whereBetween('created_at', [$start, $end])
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when(filled($userId), fn ($q) => $q->where('user_id', $userId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $logs->getCollection()->transform(function (AuditLog $log) {
            $log->activity_label = ucwords(str_replace('_', ' ', (string) $log->action));
            return $log;
        });

        return view('system-admin.security.audit-logs', [
            'logs' => $logs,
            'category' => $category,
            'userId' => $userId,
            'search' => $search,
            'fromInput' => $request->query('from'),
            'toInput' => $request->query('to'),
            'categories' => self::CATEGORIES,
            'users' => User::whereIn('role_id', [1, 2, 3, 6])->orderBy('name')->get(['id', 'name', 'first_name', 'middle_name', 'last_name']),
        ]);
    }

    protected function resolveRange(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse($request->query('from'))->startOfDay(),
                Carbon::parse($request->query('to'))->endOfDay(),
            ];
        }

        return [now()->subDays(30)->startOfDay(), now()->endOfDay()];
    }
}
