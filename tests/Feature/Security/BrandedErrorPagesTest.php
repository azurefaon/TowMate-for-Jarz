<?php

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TruckType;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

function bepQuotation(): Quotation
{
    $customer = Customer::create(['full_name' => 'Link Customer', 'phone' => '09170000016', 'email' => 'link-' . uniqid() . '@example.com']);

    return Quotation::create([
        'quotation_number' => 'Q-BEP-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => TruckType::create(['name' => 'Link Truck ' . uniqid(), 'base_rate' => 1000, 'per_km_rate' => 50])->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 3,
        'estimated_price' => 1500,
        'status' => 'sent',
        'sent_at' => now(),
        'is_current' => true,
    ]);
}

beforeEach(function () {
    Route::get('/__bep/403', fn () => abort(403, 'secret detail'));
    Route::get('/__bep/419', fn () => abort(419, 'secret detail'));
    Route::get('/__bep/500', fn () => throw new RuntimeException('SQLSTATE[23000] secret /var/www/app.php'));
});

it('shows a branded 403 page without framework text', function () {
    $this->get('/__bep/403')
        ->assertStatus(403)
        ->assertSee("You don't have permission to access this page.")
        ->assertSee('TowMate')
        ->assertDontSee('secret detail')
        ->assertDontSee('Forbidden');
});

it('shows a branded 404 page', function () {
    $this->get('/no-such-page-' . uniqid())
        ->assertStatus(404)
        ->assertSee("We couldn't find the page you're looking for.")
        ->assertDontSee('NotFoundHttpException');
});

it('shows a branded 419 page', function () {
    $this->get('/__bep/419')
        ->assertStatus(419)
        ->assertSee('Your session has expired. Please refresh the page and try again.')
        ->assertDontSee('secret detail');
});

it('shows a branded 500 page without exception details when debug is off', function () {
    config(['app.debug' => false]);

    $this->get('/__bep/500')
        ->assertStatus(500)
        ->assertSee("We couldn't complete your request right now. Please try again.")
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('/var/www');
});

it('keeps json requests as json instead of branded html', function () {
    $response = $this->getJson('/no-such-page-' . uniqid());

    $response->assertStatus(404)->assertJsonStructure(['message']);
    expect($response->getContent())->not->toContain('<html');
});

it('serves a valid signed quotation link', function () {
    $quotation = bepQuotation();
    $url = URL::temporarySignedRoute('quotation.show', now()->addHour(), ['quotation' => $quotation->id]);

    $this->get($url)->assertOk()->assertDontSee('This quotation link has expired');
});

it('shows the branded invalid-link page for expired, tampered and unsigned quotation links', function () {
    $quotation = bepQuotation();
    $expired = URL::temporarySignedRoute('quotation.show', now()->subMinute(), ['quotation' => $quotation->id]);
    $valid = URL::temporarySignedRoute('quotation.show', now()->addHour(), ['quotation' => $quotation->id]);

    foreach ([$expired, $valid . '&tampered=1', "/quotation/{$quotation->id}"] as $url) {
        $this->get($url)
            ->assertStatus(403)
            ->assertSee('This quotation link has expired or is no longer valid.')
            ->assertDontSee($quotation->quotation_number)
            ->assertDontSee('Link Customer');
    }

    $this->get("/quotation/{$quotation->id}/accept")
        ->assertStatus(403)
        ->assertSee('This quotation link has expired or is no longer valid.');

    expect($quotation->fresh()->status)->toBe('sent');
});

it('does not reveal whether a quotation exists when the link is invalid', function () {
    $this->get('/quotation/99999999')->assertStatus(403)
        ->assertSee('This quotation link has expired or is no longer valid.');
});
