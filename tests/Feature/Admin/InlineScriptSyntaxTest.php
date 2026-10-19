<?php

/*
 * A syntax error in one inline <script> stops that whole script block, silently, in the browser,
 * while the page still returns 200 (this once disabled the Dispatch "return reason" action buttons).
 * PHP tests cannot see that, so every inline script on the main role pages is parsed with Node.
 */

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/../../Concurrency/Support.php';

function iscNodeAvailable(): bool
{
    $probe = new Process(['node', '--version']);
    $probe->run();

    return $probe->isSuccessful();
}

/** @return array<int, array{source: string, module: bool}> */
function iscInlineScripts(string $html): array
{
    preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER);

    $scripts = [];
    foreach ($matches as [, $attributes, $body]) {
        if (preg_match('#\bsrc\s*=#i', $attributes) || trim($body) === '') {
            continue;
        }

        $isModule = (bool) preg_match('#type\s*=\s*["\']module["\']#i', $attributes);
        $isData = preg_match('#type\s*=\s*["\'](?!module|text/javascript|application/javascript)#i', $attributes); // json, templates
        if ($isData) {
            continue;
        }

        $scripts[] = ['source' => $body, 'module' => $isModule];
    }

    return $scripts;
}

function iscSyntaxError(string $source, bool $module): ?string
{
    $file = tempnam(sys_get_temp_dir(), 'isc') . ($module ? '.mjs' : '.js');
    file_put_contents($file, $source);

    $process = new Process(['node', '--check', $file]);
    $process->run();
    @unlink($file);

    return $process->isSuccessful() ? null : trim($process->getErrorOutput());
}

it('has no JavaScript syntax errors in any inline script on the main role pages', function () {
    if (! iscNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to syntax-check inline scripts.');
    }

    foreach ([1 => 'Owner', 2 => 'Dispatcher', 5 => 'Customer', 6 => 'System Admin'] as $id => $name) {
        Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
            $r->id = $id;
            $r->save();
        });
    }

    // data on the pages, including a returned task so the "return reason" buttons render
    [, $customer] = ccCustomer('Inline Script Customer');
    ccScheduledBooking($customer);
    ccScheduledBooking($customer, ['service_type' => 'book_now', 'status' => 'requested', 'scheduled_date' => null, 'scheduled_time' => null, 'scheduled_expires_at' => null]);

    $customerUser = User::factory()->create(['role_id' => 5, 'status' => 'active', 'must_change_password' => false]);
    Customer::create(['user_id' => $customerUser->id, 'full_name' => 'Inline Customer', 'phone' => '+639171234500', 'email' => 'inline@example.test']);

    $pages = [
        [User::factory()->create(['role_id' => 2, 'must_change_password' => false]), ['admin.dispatch', 'admin.dashboard', 'admin.jobs']],
        [User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]), ['superadmin.dashboard', 'superadmin.reports.index', 'superadmin.revenue.index']],
        [User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]), ['system-admin.dashboard']],
        [$customerUser, ['customer.book']],
    ];

    $problems = [];
    $checked = 0;
    foreach ($pages as [$user, $routes]) {
        foreach ($routes as $route) {
            $response = $this->actingAs($user)->get(route($route));
            $response->assertOk();

            foreach (iscInlineScripts($response->getContent()) as $index => $script) {
                $checked++;
                if ($error = iscSyntaxError($script['source'], $script['module'])) {
                    $problems[] = "{$route} script #{$index}: " . strtok($error, "\n") . ' | ' . (explode("\n", $error)[1] ?? '');
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(10);
    expect($problems)->toBe([]);
});

it('keeps the "unit not changed" maintenance message as a single-line string', function () {
    $blade = file_get_contents(resource_path('views/admin-dashboard/pages/dispatch.blade.php'));

    expect($blade)->toContain("'\\n\\nThe unit was not changed.");
});

it('does not load the Pusher client: production has no websocket server and the page polls instead', function () {
    $blade = file_get_contents(resource_path('views/admin-dashboard/pages/dispatch.blade.php'));

    expect($blade)->not->toContain('js.pusher.com');
    // dispatch.js and the admin dashboard both fall back to polling when Pusher is absent
    expect(file_get_contents(public_path('dispatcher/js/dispatch.js')))->toContain('typeof Pusher === "undefined"');
    expect(file_get_contents(public_path('admin/js/dashboard.js')))->toContain('typeof Pusher === "undefined"');
});
