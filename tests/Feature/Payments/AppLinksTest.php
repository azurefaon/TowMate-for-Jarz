<?php

use Illuminate\Support\Facades\Config;

it('serves assetlinks.json as JSON with no redirect and the release certificate', function () {
    $response = $this->get('/.well-known/assetlinks.json');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
    expect($response->isRedirect())->toBeFalse();

    $entry = $response->json()[0];
    expect($entry['relation'])->toBe(['delegate_permission/common.handle_all_urls']);
    expect($entry['target']['namespace'])->toBe('android_app');
    expect($entry['target']['package_name'])->toBe('com.jarztowing.towmate');
    expect($entry['target']['sha256_cert_fingerprints'])->each->toMatch('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/');
    expect($entry['target']['sha256_cert_fingerprints'])->toContain(
        'F6:BB:53:C6:E6:F8:30:BC:27:95:21:BD:A2:3C:65:7F:BB:E2:12:10:A8:FB:94:D2:31:81:24:A2:C1:DF:95:3A'
    );
});

it('defaults the canonical link domain to www.jarztowing.com, never the railway host', function () {
    expect(config('app_links.base_url'))->toBe('https://www.jarztowing.com');
    expect(config('app_links.base_url'))->not->toContain('railway');
});

it('shows a return page that offers the app and never claims the payment succeeded', function () {
    foreach (['success', 'cancelled', 'failed'] as $outcome) {
        $page = $this->get("/pay/return/{$outcome}?booking=0000001");

        $page->assertOk()->assertSee('Return to TowMate')->assertSee('intent://www.jarztowing.com/pay/return/' . $outcome . '?booking=0000001', false);
        $page->assertDontSee('Payment successful')->assertDontSee('has been paid')->assertDontSee('Paid');
        expect($page->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('rejects unknown outcomes and does not echo a hostile booking parameter', function () {
    $this->get('/pay/return/paid')->assertNotFound();

    $page = $this->get('/pay/return/success?booking=' . urlencode('"><script>alert(1)</script>'));
    $page->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    expect($page->getContent())->not->toContain('alert(1)');
});

it('lets an explicitly configured checkout return URL win, otherwise uses the app link', function () {
    expect(config('paymongo.success_url'))->toBeNull();
    Config::set('paymongo.success_url', 'https://pilot.example/pilot/return?result=success');

    expect(config('paymongo.success_url'))->toBe('https://pilot.example/pilot/return?result=success');
});
