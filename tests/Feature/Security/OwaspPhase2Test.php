<?php

/*
 * OWASP phase 2: pinned third-party scripts (H1), patched dependencies (H2),
 * quotation links that only act on a signed POST (M1), one password rule (M3).
 */

use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/../../Concurrency/Support.php';

/* ---------- H1: third-party scripts are pinned and integrity-checked ---------- */

it('H1: every external script or stylesheet is version-pinned and carries an SRI hash', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $html = file_get_contents($file->getPathname());
        preg_match_all('#<(script|link)\b[^>]*(?:src|href)="(https?://[^"]+)"[^>]*>#i', $html, $m, PREG_SET_ORDER);

        foreach ($m as [$tag, $kind, $url]) {
            $host = parse_url($url, PHP_URL_HOST);
            $isFontOrMaps = preg_match('#(fonts\.(googleapis|gstatic|bunny)\.|maps\.(googleapis|gstatic)\.)#', $host.'/') === 1
                || str_contains($host, 'googleapis.com') || str_contains($host, 'bunny.net');
            $isStylesheet = strtolower($kind) === 'link' && ! str_contains(strtolower($tag), 'stylesheet');

            if ($isFontOrMaps || $isStylesheet) {
                continue;
            }

            if (! str_contains($tag, 'integrity="sha384-') || ! str_contains($tag, 'crossorigin=')) {
                $offenders[] = $file->getFilename() . ' -> ' . $url . ' (no integrity)';
            }

            if (str_contains($url, '@latest') || ! preg_match('#@\d+\.\d+\.\d+|/\d+\.\d+\.\d+/#', $url)) {
                $offenders[] = $file->getFilename() . ' -> ' . $url . ' (not pinned to an exact version)';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('H1: the content security policy was not loosened', function () {
    $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->not->toContain("'unsafe-eval'")
        ->not->toContain('script-src *');
});

/* ---------- H2: patched dependencies ---------- */

it('H2: the locked symfony/http-foundation and league/commonmark include the security fixes', function () {
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
    $versions = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
        $versions[$package['name']] = ltrim($package['version'], 'v');
    }

    expect(version_compare($versions['symfony/http-foundation'], '7.4.21', '>='))->toBeTrue($versions['symfony/http-foundation']);
    expect(version_compare($versions['league/commonmark'], '2.10.4', '>='))->toBeTrue($versions['league/commonmark']);
});

/* ---------- M1: emailed quotation links never act on GET ---------- */

function owaspQuotation(): array
{
    [, $customer] = ccCustomer('Quote Owner ' . random_int(1, 99999));
    $booking = ccScheduledBooking($customer);
    $quotation = ccSentQuotation($booking);

    return [$booking, $quotation];
}

function owaspSigned(Quotation $quotation, string $route = 'quotation.accept', array $extra = []): string
{
    return URL::temporarySignedRoute($route, now()->addHour(), array_merge(['quotation' => $quotation->id, 'v' => $quotation->link_version ?? 1], $extra));
}

it('M1: opening the accept link (what a mail scanner does) changes nothing and only shows the quotation', function () {
    [$booking, $quotation] = owaspQuotation();

    foreach (range(1, 3) as $ignored) {
        $this->get(owaspSigned($quotation))->assertRedirect();
    }

    expect($quotation->fresh()->status)->toBe('sent');
    expect($booking->fresh()->status)->toBe('scheduled');
    expect(ccSlots('2030-03-10'))->toBeNull();
});

it('M1: opening the reject link changes nothing either', function () {
    [$booking, $quotation] = owaspQuotation();

    $this->get(owaspSigned($quotation, 'quotation.reject'))->assertRedirect();

    expect($quotation->fresh()->status)->toBe('sent');
    expect($booking->fresh()->status)->toBe('scheduled');
});

it('M1: the quotation page offers a POST form to accept, not a link that acts', function () {
    [, $quotation] = owaspQuotation();
    $show = URL::temporarySignedRoute('quotation.show', now()->addHour(), ['quotation' => $quotation->id]);

    $html = $this->get($show)->assertOk()->getContent();

    expect($html)->toContain('method="POST"')
        ->toContain("/quotation/{$quotation->id}/accept")
        ->toContain('Accept and Continue');
    expect($html)->not->toMatch('#<a[^>]+href="[^"]*/accept[^"]*"#');
});

it('M1: a signed POST accepts the quotation once, and a repeat submission does nothing more', function () {
    [$booking, $quotation] = owaspQuotation();
    $url = owaspSigned($quotation);

    $this->post($url)->assertRedirect();
    expect($quotation->fresh()->status)->toBe('accepted');
    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
    expect(ccSlots('2030-03-10'))->toBe(1);

    $this->post($url)->assertRedirect();
    expect($quotation->fresh()->status)->toBe('accepted');
    expect(ccSlots('2030-03-10'))->toBe(1); // still one slot
});

it('M1: a POST without a valid signature is refused', function () {
    [$booking, $quotation] = owaspQuotation();

    $this->post("/quotation/{$quotation->id}/accept")->assertStatus(403);
    $this->post(owaspSigned($quotation) . 'x')->assertStatus(403);

    // a signature issued for a different quotation cannot be reused
    [, $other] = owaspQuotation();
    $otherUrl = owaspSigned($other);
    $this->post(str_replace("/quotation/{$other->id}/", "/quotation/{$quotation->id}/", $otherUrl))->assertStatus(403);

    expect($quotation->fresh()->status)->toBe('sent');
    expect($booking->fresh()->status)->toBe('scheduled');
});

it('M1: an expired quotation cannot be accepted through the signed POST', function () {
    [$booking, $quotation] = owaspQuotation();
    DB::table('quotations')->where('id', $quotation->id)->update(['expires_at' => now()->subMinute()]);

    $this->post(owaspSigned($quotation))->assertRedirect();

    expect($quotation->fresh()->status)->not->toBe('accepted');
    expect($booking->fresh()->status)->not->toBe('scheduled_confirmed');
    expect(ccSlots('2030-03-10'))->toBeNull();
});

it('M1: a link from an older version of the quotation cannot accept the newer one', function () {
    [$booking, $quotation] = owaspQuotation();
    $stale = owaspSigned($quotation, 'quotation.accept', ['v' => 1]);
    DB::table('quotations')->where('id', $quotation->id)->update(['link_version' => 2]);

    $this->post($stale)->assertRedirect();

    expect($quotation->fresh()->status)->toBe('sent');
    expect($booking->fresh()->status)->toBe('scheduled');
});

it('M1: a signed POST can reject, and only the POST', function () {
    [, $quotation] = owaspQuotation();

    $this->post(owaspSigned($quotation, 'quotation.reject'), ['reason' => 'Too expensive'])->assertRedirect();

    expect($quotation->fresh()->status)->toBe('rejected');
});

/* ---------- M3: one password rule ---------- */

it('M3: the default password rule is the project policy (12+, mixed case, number, symbol)', function () {
    $check = fn (string $p) => Validator::make(['password' => $p], ['password' => [Password::defaults()]])->passes();

    expect($check('Short1!a'))->toBeFalse();            // too short
    expect($check('alllowercase123!'))->toBeFalse();     // no uppercase
    expect($check('NoNumbersHere!!'))->toBeFalse();      // no number
    expect($check('NoSymbolsHere123'))->toBeFalse();     // no symbol
    expect($check('Tr0ub4dor&Horse-Staple'))->toBeTrue();
});

it('M3: a signed-in web user must pick a policy-compliant password, and keeps working with the old one otherwise', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrorsIn('updatePassword', 'password');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'password',
        'password' => 'Tr0ub4dor&Horse-Staple',
        'password_confirmation' => 'Tr0ub4dor&Horse-Staple',
    ])->assertSessionHasNoErrors();
    expect(Hash::check('Tr0ub4dor&Horse-Staple', $user->fresh()->password))->toBeTrue();
});

it('M3: the Team Leader password change enforces the same rule, and existing logins are unaffected', function () {
    Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), fn ($r) => tap($r, function ($r) { $r->id = 3; $r->save(); }));
    $tl = User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
    Sanctum::actingAs($tl, ['*']);

    // an 8-character password was accepted before; it is now refused with a clear message
    $this->postJson('/api/v1/team-leader/auth/change-password', [
        'current_password' => 'password',
        'password' => 'Abcdef1!',
        'password_confirmation' => 'Abcdef1!',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
    expect(Hash::check('password', $tl->fresh()->password))->toBeTrue(); // nothing changed

    $this->postJson('/api/v1/team-leader/auth/change-password', [
        'current_password' => 'password',
        'password' => 'Tr0ub4dor&Horse-Staple',
        'password_confirmation' => 'Tr0ub4dor&Horse-Staple',
    ])->assertOk();
    expect(Hash::check('Tr0ub4dor&Horse-Staple', $tl->fresh()->password))->toBeTrue();
});

it('M3: web registration refuses a weak password', function () {
    $this->post('/register', [
        'first_name' => 'Weak', 'last_name' => 'Pass', 'email' => 'weak-pass@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

/* ---------- M1: CSRF is really enforced on the quotation POST (tests normally skip it) ---------- */

function owaspEnforceCsrf(): void
{
    app()->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
        return new class ($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });
}

it('M1: the quotation page puts a CSRF token in the accept form', function () {
    [, $quotation] = owaspQuotation();
    $show = URL::temporarySignedRoute('quotation.show', now()->addHour(), ['quotation' => $quotation->id]);

    $this->get($show)->assertOk()->assertSee('name="_token"', false);
});

it('M1: a signed POST without the CSRF token is refused, and accepted with it', function () {
    [$booking, $quotation] = owaspQuotation();
    owaspEnforceCsrf();
    $url = owaspSigned($quotation);

    $this->post($url)->assertStatus(419);
    expect($quotation->fresh()->status)->toBe('sent');

    $this->withSession(['_token' => 'test-csrf-token'])->post($url, ['_token' => 'test-csrf-token'])->assertRedirect();
    expect($quotation->fresh()->status)->toBe('accepted');
    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
});
