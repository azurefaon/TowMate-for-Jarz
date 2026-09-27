<?php

use App\Models\Role;
use App\Models\User;

function bmscDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

it('renders #rbDrawer nested INSIDE #rbDrawerOverlay, not as a sibling', function () {
    // This is the actual regression: the two elements were briefly rendered as
    // sibling <div>s (an earlier, already-fixed-once bug re-introduced by an
    // unrelated concurrent edit). As siblings, #rbDrawer is a normal-flow block
    // element that contributes its own height to the document and is never
    // constrained/centered by the overlay's fixed+flex-centering box at all —
    // which is exactly what caused the modal to extend below the viewport and
    // the page itself to become scrollable.
    $dispatcher = bmscDispatcher();

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    $overlayPos = strpos($html, 'id="rbDrawerOverlay"');
    $drawerPos = strpos($html, 'id="rbDrawer"');
    expect($overlayPos)->not->toBeFalse();
    expect($drawerPos)->not->toBeFalse();
    expect($drawerPos)->toBeGreaterThan($overlayPos);

    // Between the overlay's opening tag and the drawer's opening tag there must
    // be NO closing </div> at the overlay's own nesting depth — i.e. the overlay
    // div must not have already closed before #rbDrawer appears. The overlay is
    // a single, empty (server-rendered) container immediately followed by the
    // drawer mount point, so the simplest sufficient proof is: the exact nested
    // markup snippet appears verbatim in the response.
    expect($html)->toContain('<div class="rb-drawer-overlay" id="rbDrawerOverlay">');
    expect($html)->toContain('<div class="rb-drawer" id="rbDrawer"></div>');

    $nestedSnippet = '<div class="rb-drawer-overlay" id="rbDrawerOverlay">'
        . "\n        " . '<div class="rb-drawer" id="rbDrawer"></div>'
        . "\n    " . '</div>';
    expect($html)->toContain($nestedSnippet);
});

it('backs the existing rb-modal-open body class (toggled by booking-drawer.js) with a real scroll-lock CSS rule', function () {
    $dispatcher = bmscDispatcher();

    $response = test()->actingAs($dispatcher)->get(route('admin.dispatch'));
    $response->assertOk();
    $html = $response->getContent();

    // The JS-side toggle already exists (document.body.classList.add/remove
    // "rb-modal-open")) — this only asserts the CSS half exists so the toggle
    // actually does something.
    expect($html)->toMatch('/body\.rb-modal-open\s*\{[^}]*overflow:\s*hidden/');
});
