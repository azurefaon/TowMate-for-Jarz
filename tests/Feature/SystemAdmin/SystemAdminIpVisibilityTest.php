<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

const IPV_FAILED_IP = '152.233.68.98';
const IPV_LOCK_IP = '152.233.68.105';
const IPV_V6_IP = '2001:db8::7334';

function ipvAdmin(): User
{
    foreach ([2 => 'Dispatcher', 6 => 'System Admin'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    return User::factory()->create([
        'role_id' => 6, 'status' => 'active', 'must_change_password' => false,
        'password' => Hash::make('CorrectPass123!'),
    ]);
}

function ipvSeed(): void
{
    AuditLog::create([
        'user_id' => null, 'action' => 'failed_login', 'category' => 'login_failed',
        'reference' => 'someone@example.com',
        'description' => 'Failed login attempt from IP ' . IPV_FAILED_IP,
        'ip_address' => IPV_FAILED_IP,
    ]);
    AuditLog::create([
        'user_id' => null, 'action' => 'account_temporarily_locked', 'category' => 'security',
        'reference' => 'someone@example.com',
        'description' => 'Account locked for 15 minutes after 3 consecutive failed login attempts from IP ' . IPV_LOCK_IP . '.',
        'ip_address' => IPV_LOCK_IP,
    ]);
    AuditLog::create([
        'user_id' => null, 'action' => 'user_updated', 'category' => 'update',
        'reference' => 'Some User',
        'description' => 'Profile changed, request from ' . IPV_V6_IP . ' noted',
        'ip_address' => IPV_V6_IP,
    ]);
}

function ipvAssertNoIp(string $html): void
{
    foreach ([IPV_FAILED_IP, IPV_LOCK_IP, IPV_V6_IP, '152.233.68'] as $ip) {
        expect($html)->not->toContain($ip);
    }
    expect(strtolower($html))->not->toContain('ip address')->and($html)->not->toContain('from IP');
}

it('shows no IP address on the system admin dashboard', function () {
    $admin = ipvAdmin();
    ipvSeed();

    $html = $this->actingAs($admin)->get(route('system-admin.dashboard'))->assertOk()->getContent();

    ipvAssertNoIp($html);
    expect($html)->toContain('Failed Login')->toContain('Account Temporarily Locked');
});

it('shows no IP address on the security monitor and keeps the meaningful message', function () {
    $admin = ipvAdmin();
    ipvSeed();

    $html = $this->actingAs($admin)->get(route('system-admin.security.monitor'))->assertOk()->getContent();

    ipvAssertNoIp($html);
    expect($html)->toContain('Failed login attempt.')
        ->toContain('Account locked for 15 minutes after 3 consecutive failed login attempts.');
});

it('shows no IP address on the audit logs and keeps the meaningful message', function () {
    $admin = ipvAdmin();
    ipvSeed();

    $html = $this->actingAs($admin)->get(route('system-admin.audit-logs.index'))->assertOk()->getContent();

    ipvAssertNoIp($html);
    expect($html)->toContain('Failed login attempt.')->toContain('Profile changed');
});

it('does not let an IP-like audit search reveal which rows contain an IP', function () {
    $admin = ipvAdmin();
    ipvSeed();

    $html = $this->actingAs($admin)->get(route('system-admin.audit-logs.index', ['search' => '152.233.68']))->assertOk()->getContent();

    expect($html)->not->toContain('Failed login attempt')->and($html)->not->toContain('Account locked');
});

it('still stores the IP on the underlying records', function () {
    ipvSeed();

    expect(AuditLog::where('action', 'failed_login')->value('ip_address'))->toBe(IPV_FAILED_IP)
        ->and(AuditLog::where('action', 'failed_login')->value('description'))->toContain(IPV_FAILED_IP);
});

it('keeps recording the client IP on failed staff logins', function () {
    $admin = ipvAdmin();

    for ($i = 0; $i < 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => IPV_FAILED_IP])->post('/login', [
            'role' => 'systemadmin', 'email' => $admin->email, 'password' => 'WrongPass123!',
        ]);
    }

    $failed = AuditLog::where('action', 'failed_login')->latest('id')->first();
    expect($failed)->not->toBeNull()
        ->and($failed->description)->toContain(IPV_FAILED_IP)
        ->and($failed->ip_address)->toBe(IPV_FAILED_IP);
});

it('redacts ip addresses only from display text', function () {
    expect(redact_ip_addresses('Failed login attempt from IP 152.233.68.98'))->toBe('Failed login attempt.')
        ->and(redact_ip_addresses('IP address: 10.0.0.1 blocked'))->toBe('blocked.')
        ->and(redact_ip_addresses('Opened at 10:30, moved 12.5 km, v1.2.3.4.5'))->toBe('Opened at 10:30, moved 12.5 km, v1.2.3.4.5')
        ->and(redact_ip_addresses(null))->toBeNull();
});
