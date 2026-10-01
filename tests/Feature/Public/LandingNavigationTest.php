<?php

it('renders a labelled main navigation landmark with the existing branding', function () {
    $response = $this->get(route('landing'));

    $response->assertOk();
    $response->assertSee('<nav class="jarz-nav jarz-nav--login" aria-label="Main navigation"', false);
    $response->assertSee('class="jarz-brand-name">JARZ Towing Services', false);
});

it('keeps only the existing four navigation destinations', function () {
    $response = $this->get(route('landing'));

    $response->assertSee('href="'.url('/').'"', false);
    $response->assertSee('href="'.url('/').'#about-us"', false);
    $response->assertSee('href="'.url('/').'#our-services"', false);
    $response->assertSee('href="'.route('login').'"', false);
    $response->assertSee('>Home</a>', false);
    $response->assertSee('>About Us</a>', false);
    $response->assertSee('>Staff Login</a>', false);
    $response->assertDontSee('Get the App');
    $response->assertDontSee('jarz-nav-cta', false);
    $response->assertSee('id="about-us"', false);
    $response->assertSee('id="our-services"', false);
});

it('marks the current page link with aria-current', function () {
    expect($this->get(route('landing'))->getContent())
        ->toMatch('/aria-current="page"\s*>Home</');
    expect($this->get(route('login'))->getContent())
        ->toMatch('/aria-current="page"\s*>Staff Login</');
});

it('provides an accessible mobile menu toggle wired to the links', function () {
    $response = $this->get(route('landing'));

    $response->assertSee('aria-label="Toggle navigation menu"', false);
    $response->assertSee('aria-expanded="false"', false);
    $response->assertSee('aria-controls="jarz-nav-links"', false);
    $response->assertSee('id="jarz-nav-links"', false);
    $response->assertSee("event.key === 'Escape'", false);
    $response->assertSee('setAttribute(\'aria-expanded\'', false);
});

it('renders the same navigation on the staff login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('aria-controls="jarz-nav-links"', false)
        ->assertSee('class="jarz-brand-name">JARZ Towing Services', false);
});

it('does not duplicate navigation element ids', function () {
    $html = $this->get(route('landing'))->getContent();

    expect(substr_count($html, 'id="jarz-nav-links"'))->toBe(1);
});

it('limits the hamburger menu to narrow screens in the stylesheet', function () {
    $css = file_get_contents(public_path('admin/css/login.css'));

    expect($css)
        ->toContain('@media (max-width: 760px)')
        ->toContain('.jarz-nav[data-nav] .jarz-nav-toggle')
        ->toContain('.jarz-nav[data-nav].is-open .jarz-nav-links')
        ->toContain('min-height: 48px')
        ->toContain('text-overflow: ellipsis');

    $toggleBase = strpos($css, ".jarz-nav-toggle {\n    display: none;");
    expect($toggleBase)->not->toBeFalse();
});
