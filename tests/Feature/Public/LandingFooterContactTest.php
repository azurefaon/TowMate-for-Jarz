<?php

use App\Models\SystemSetting;

it('hides the Facebook, Contact, and Email footer items when no settings are saved', function () {
    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertDontSee('jarz-footer-item-label">Facebook', false);
    $response->assertDontSee('jarz-footer-item-label">Contact', false);
    $response->assertDontSee('jarz-footer-item-label">Email', false);
    $response->assertDontSee('N/A');
    $response->assertDontSee('example@email.com');
    $response->assertDontSee('09XX XXX XXXX');
});

it('shows the saved Facebook page in the footer', function () {
    SystemSetting::setValue('company_facebook_url', 'https://www.facebook.com/profile.php?id=100090944122576');

    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('href="https://www.facebook.com/profile.php?id=100090944122576"', false);
});

it('reflects a changed Facebook value on the next request', function () {
    SystemSetting::setValue('company_facebook_url', 'https://www.facebook.com/old-page');
    $this->get(route('landing'))->assertSee('https://www.facebook.com/old-page', false);

    SystemSetting::setValue('company_facebook_url', 'https://www.facebook.com/new-page');
    $response = $this->get(route('landing'));

    $response->assertSee('https://www.facebook.com/new-page', false);
    $response->assertDontSee('https://www.facebook.com/old-page', false);
});

it('shows the saved contact number in the footer using a tel: link', function () {
    SystemSetting::setValue('company_phone', '0909 334 7549');

    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('href="tel:+639093347549"', false);
    $response->assertSee('0909 334 7549');
});

it('shows the saved public email in the footer using a mailto: link', function () {
    SystemSetting::setValue('company_email', 'contact@jarztowing.com');

    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('href="mailto:contact@jarztowing.com"', false);
    $response->assertSee('contact@jarztowing.com');
});

it('no longer depends on .env or config for the footer contact values', function () {
    expect(config('towmate.company'))->toBeNull();

    SystemSetting::setValue('company_phone', '0917 111 2222');

    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('href="tel:+639171112222"', false);
});

it('still renders the approved landing page headings and footer structure', function () {
    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('id="about-us"', false);
    $response->assertSee('id="our-services"', false);
    $response->assertSee('class="jarz-about-heading">About Us', false);
    $response->assertSee('class="jarz-services-heading">Our Services', false);
    $response->assertSee('class="jarz-footer"', false);
    $response->assertSee('JARZ Towing Services. All rights reserved.', false);
    $response->assertSee('class="jarz-footer-wordmark">TowMate', false);

    // No duplicated eyebrow labels above the About Us / Our Services headings.
    $response->assertDontSee('class="jarz-gettowmate-eyebrow">About Us', false);
    $response->assertDontSee('class="jarz-gettowmate-eyebrow">Our Services', false);
});
