<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\UserPurgeService;
use Illuminate\Support\Facades\DB;

function auditPreserveRole(int $id, string $name): void
{
    if (Role::find($id)) {
        return;
    }

    $role = new Role(['name' => $name]);
    $role->id = $id;
    $role->save();

    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }
}

it('keeps audit rows with a null actor when the user row is deleted', function () {
    $user = User::factory()->create();
    $log = AuditLog::create(['user_id' => $user->id, 'action' => 'login', 'entity_type' => 'User', 'entity_id' => $user->id]);

    $user->delete();

    $log->refresh();
    expect(AuditLog::count())->toBe(1)
        ->and($log->user_id)->toBeNull()
        ->and($log->actor_label)->toStartWith('Deleted account #' . $user->id);
});

it('does not copy the deleted person name or contact details into the audit row', function () {
    $user = User::factory()->create(['name' => 'Zed Unique Person', 'email' => 'zed.unique@example.test']);
    $log = AuditLog::create(['user_id' => $user->id, 'action' => 'login']);

    $user->delete();

    $row = json_encode(DB::table('audit_logs')->where('id', $log->id)->first());
    expect($row)->not->toContain('Zed Unique')->not->toContain('zed.unique');
});

it('keeps the audit history of a user removed by the purge workflow', function () {
    $user = User::factory()->create();
    AuditLog::create(['user_id' => $user->id, 'action' => 'login']);
    AuditLog::create(['user_id' => $user->id, 'action' => 'logout']);

    $result = app(UserPurgeService::class)->purge($user);

    expect($result)->toBe('deleted');
    expect(AuditLog::where('action', 'login')->count())->toBe(1)
        ->and(AuditLog::where('action', 'logout')->count())->toBe(1)
        ->and(AuditLog::where('action', 'user_permanently_deleted')->count())->toBe(1);
});

it('lets a customer delete their own account but keeps their audit rows', function () {
    auditPreserveRole(5, 'Customer');
    $user = User::factory()->create(['role_id' => 5]);
    AuditLog::create(['user_id' => $user->id, 'action' => 'login']);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(AuditLog::where('action', 'login')->count())->toBe(1);
});

it('refuses self-service account deletion for staff roles', function (int $roleId, string $name) {
    auditPreserveRole($roleId, $name);
    $user = User::factory()->create(['role_id' => $roleId]);

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertForbidden();

    expect(User::find($user->id))->not->toBeNull();
})->with([[1, 'Owner'], [2, 'Dispatcher'], [6, 'System Admin']]);
