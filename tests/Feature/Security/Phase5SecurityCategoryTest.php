<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

it('OTP and password-reset audit actions are categorized as security, not the generic system bucket', function () {
    expect(\App\Services\AuditLogService::categoryForAction('customer_password_reset_otp_sent'))->toBe('security');
    expect(\App\Services\AuditLogService::categoryForAction('customer_password_reset_otp_locked'))->toBe('security');
    expect(\App\Services\AuditLogService::categoryForAction('customer_password_reset_completed'))->toBe('security');
    expect(\App\Services\AuditLogService::categoryForAction('customer_registration_otp_locked'))->toBe('security');
    expect(\App\Services\AuditLogService::categoryForAction('staff_password_reset_otp_sent'))->toBe('security');
    expect(\App\Services\AuditLogService::categoryForAction('staff_password_reset_completed'))->toBe('security');
});

it('unrelated actions are not miscategorized as security', function () {
    expect(\App\Services\AuditLogService::categoryForAction('booking_assigned'))->toBe('assignment_change');
    expect(\App\Services\AuditLogService::categoryForAction('failed_login'))->toBe('login_failed');
});

it('the Owner activity log screen accepts a security category filter and returns matching rows', function () {
    $role = Role::find(1) ?: tap(new Role(['name' => 'Super Admin']), function ($r) {
        $r->id = 1;
        $r->save();
    });
    $owner = User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);

    $log = AuditLog::create([
        'user_id' => null,
        'action' => 'customer_password_reset_otp_locked',
        'entity_type' => 'User',
        'entity_id' => 1,
        'description' => 'OTP invalidated after too many failed verification attempts.',
    ]);

    expect($log->category)->toBe('security');
    expect(AuditLog::where('category', 'security')->whereKey($log->id)->exists())->toBeTrue();

    $response = test()->actingAs($owner)->get(route('superadmin.reports.activity', ['category' => 'security']));

    $response->assertOk();
    $response->assertSee('OTP invalidated after too many failed verification attempts.', false);
});
