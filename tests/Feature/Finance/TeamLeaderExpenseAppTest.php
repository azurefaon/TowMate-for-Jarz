<?php

use App\Models\Finance\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/finance_helpers.php';

// Phase 8: the contract the Flutter Team Leader expense screen relies on (field names exactly as the app sends them).

beforeEach(function () {
    Storage::fake('local');
});

it('serves the form options for the Team Leader own unit', function () {
    $leader = finUser(3);
    $unit = finUnit($leader);
    Sanctum::actingAs($leader, ['*']);

    $this->getJson('/api/v1/team-leader/expenses/options')->assertOk()
        ->assertJsonPath('data.unit.id', $unit->id)
        ->assertJsonPath('data.unit.name', $unit->name)
        ->assertJsonPath('data.categories.0.key', 'fuel_diesel')
        ->assertJsonPath('data.funding_sources.1.key', 'company_direct')
        ->assertJsonPath('data.expense_scopes.0.key', 'job');
});

it('accepts the app job-expense submission, starts Dispatcher review and shows the status in the list', function () {
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit);
    $key = (string) Str::uuid();
    Sanctum::actingAs($leader, ['*']);

    $payload = [
        'idempotency_key' => $key,
        'expense_scope' => 'job',
        'booking_ref' => $booking->booking_code,
        'expense_date' => now(config('finance.timezone'))->toDateString(),
        'category' => 'toll',
        'description' => 'NLEX toll, both ways',
        'amount' => '1250.50',
        'fundings' => ['company_direct' => '1250.50'],
        'receipts' => [UploadedFile::fake()->image('receipt-1.jpg', 40, 40)],
    ];
    $this->post('/api/v1/team-leader/expenses', $payload, ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.booking_reference', $booking->booking_code)
        ->assertJsonPath('data.evidence_count', 1)
        ->assertJsonPath('data.fundings.0.source', 'company_direct');

    // A retry with the same key (the app reuses it) does not create a second expense.
    $payload['receipts'] = [UploadedFile::fake()->image('receipt-1.jpg', 41, 41)];
    $this->post('/api/v1/team-leader/expenses', $payload, ['Accept' => 'application/json'])->assertOk();
    $expense = Expense::sole();
    expect($expense->unit_id)->toBe($unit->id)->and($expense->submitted_by)->toBe($leader->id);

    // Dispatcher verifies; the Team Leader list shows the new review status.
    $this->actingAs(finUser(2))->post(route('admin.expense-review.verify', $expense->id), ['note' => 'Receipt matches'])->assertSessionHasNoErrors();
    Sanctum::actingAs($leader, ['*']);
    $this->getJson('/api/v1/team-leader/expenses')->assertOk()
        ->assertJsonPath('data.0.reference', $expense->reference_code)
        ->assertJsonPath('data.0.status', 'verified')
        ->assertJsonPath('data.0.amount', '1250.50');
});

it('returns clear validation messages the app displays', function () {
    $leader = finUser(3);
    finUnit($leader);
    Sanctum::actingAs($leader, ['*']);

    $this->post('/api/v1/team-leader/expenses', [
        'idempotency_key' => (string) Str::uuid(), 'expense_scope' => 'job', 'expense_date' => now()->addDays(3)->toDateString(),
        'category' => 'toll', 'description' => 'x', 'amount' => '12.345', 'fundings' => ['unit_fund' => '12.345'],
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['booking_ref', 'expense_date', 'amount'])
        ->assertJsonPath('errors.expense_date.0', 'Expenses cannot be dated in the future.')
        ->assertJsonPath('errors.booking_ref.0', 'A job expense needs the booking reference.');
    expect(Expense::count())->toBe(0);
});
