<?php

// TC028.03 — genuine .xlsx export for the booking, vehicle (fleet) and financial reports.

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportWorkbookBuilder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const RX_FROM = '2026-03-10';
const RX_TO = '2026-03-12';

function rxRole(int $id, string $name): void
{
    if (! Role::find($id)) {
        $role = new Role(['name' => $name]);
        $role->id = $id;
        $role->save();
    }
}

function rxOwner(): User
{
    rxRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function rxUser(int $roleId, string $name): User
{
    rxRole($roleId, $name);

    return User::factory()->create(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false]);
}

function rxTruckType(string $name): TruckType
{
    return TruckType::create(['name' => $name, 'base_rate' => 1500, 'per_km_rate' => 60]);
}

function rxUnit(string $name, TruckType $type): Unit
{
    return Unit::create(['name' => $name, 'plate_number' => 'RX-' . random_int(1000, 9999), 'truck_type_id' => $type->id, 'status' => 'available']);
}

function rxBooking(string $createdAt, string $status, float $total, ?Unit $unit, TruckType $type, string $customerName = 'Report Customer', array $extra = []): Booking
{
    $customer = Customer::create([
        'full_name' => $customerName,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'rx-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $type->id,
        'assigned_unit_id' => $unit?->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => $total, 'final_total' => $total,
        'status' => $status,
    ]);
    $at = Carbon::parse($createdAt, config('app.timezone'));
    // The app only ever writes status=completed together with completed_at (see ReportMetricsService::PAYMENT_INVARIANT).
    $booking->forceFill(array_merge(['created_at' => $at, 'completed_at' => $status === 'completed' ? $at : null], $extra))->save();

    return $booking->fresh();
}

/** Standard data set: 2 in-range completed, 1 in-range cancelled, 2 just outside the range. */
function rxSeed(): array
{
    $towA = rxTruckType('RX Heavy');
    $towB = rxTruckType('RX Light');
    $unitA = rxUnit('RX Unit Alpha', $towA);
    $unitB = rxUnit('RX Unit Bravo', $towB);

    rxBooking(RX_FROM . ' 00:00:00', 'completed', 1000.50, $unitA, $towA, 'Start Boundary', ['additional_fee' => 100, 'vat_amount' => 120.06, 'cash_received' => 1000.50]);
    rxBooking(RX_TO . ' 23:59:59', 'completed', 2000.25, $unitB, $towB, 'End Boundary', ['additional_fee' => 50, 'vat_amount' => 240.03, 'cash_received' => 2000.25]);
    rxBooking('2026-03-11 10:00:00', 'cancelled', 500, null, $towA, 'Cancelled One');
    rxBooking('2026-03-09 23:59:59', 'completed', 9999, $unitA, $towA, 'Day Before');
    rxBooking('2026-03-13 00:00:00', 'completed', 8888, $unitB, $towB, 'Day After');

    return compact('towA', 'towB', 'unitA', 'unitB');
}

function rxDownload(User $user, array $query = []): array
{
    $response = test()->actingAs($user)->get(route('superadmin.reports.export-xlsx', array_merge(['from' => RX_FROM, 'to' => RX_TO], $query)));
    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'rx') . '.xlsx';
    file_put_contents($path, $response->streamedContent());
    $spreadsheet = IOFactory::load($path);
    @unlink($path);

    return [$spreadsheet, $response];
}

/** Value of column $col on the first row whose column A equals $label. */
function rxCell(Worksheet $sheet, string $label, string $col = 'B'): mixed
{
    foreach ($sheet->getRowIterator() as $row) {
        $r = $row->getRowIndex();
        if ($sheet->getCell("A{$r}")->getValue() === $label) {
            return $sheet->getCell("{$col}{$r}")->getValue();
        }
    }

    return null;
}

function rxRowOf(Worksheet $sheet, string $label): ?int
{
    foreach ($sheet->getRowIterator() as $row) {
        if ($sheet->getCell('A' . $row->getRowIndex())->getValue() === $label) {
            return $row->getRowIndex();
        }
    }

    return null;
}

// ── Format, naming, structure ─────────────────────────────────────────────
it('returns a genuine xlsx workbook with the right headers and filename', function () {
    rxSeed();
    [$spreadsheet, $response] = rxDownload(rxOwner(), ['section' => 'booking']);

    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))->toMatch('/towmate-booking-report-custom-\d{4}-\d{2}-\d{2}\.xlsx/');

    $raw = $response->streamedContent();
    expect(substr($raw, 0, 2))->toBe('PK'); // OOXML is a zip container, not renamed CSV
    expect($spreadsheet->getSheetNames())->toBe(['Booking Report', 'Booking Details']);
});

it('exports each approved report as its own set of sheets', function () {
    rxSeed();
    $owner = rxOwner();

    expect(rxDownload($owner, ['section' => 'vehicle'])[0]->getSheetNames())->toBe(['Vehicle Report'])
        ->and(rxDownload($owner, ['section' => 'financial'])[0]->getSheetNames())->toBe(['Financial Report'])
        ->and(rxDownload($owner)[0]->getSheetNames())->toBe(['Summary', 'Booking Report', 'Booking Details', 'Vehicle Report', 'Financial Report']);
});

// ── Date range and boundaries ─────────────────────────────────────────────
it('includes records on both boundary days and excludes records outside the range', function () {
    rxSeed();
    [$spreadsheet] = rxDownload(rxOwner(), ['section' => 'booking']);

    $details = $spreadsheet->getSheetByName('Booking Details');
    $customers = [];
    foreach ($details->getRowIterator(4) as $row) {
        $name = $details->getCell('C' . $row->getRowIndex())->getValue();
        if ($name !== null) {
            $customers[] = $name;
        }
    }
    sort($customers);

    expect($customers)->toBe(['Cancelled One', 'End Boundary', 'Start Boundary'])
        ->and(rxCell($spreadsheet->getSheetByName('Booking Report'), 'Total Bookings'))->toBe(3);
});

it('writes the range as real dates in the app timezone', function () {
    rxSeed();
    [$spreadsheet] = rxDownload(rxOwner(), ['section' => 'financial']);
    $sheet = $spreadsheet->getSheetByName('Financial Report');

    $from = $sheet->getCell('B3');
    $to = $sheet->getCell('B4');
    expect(ExcelDate::isDateTime($from))->toBeTrue()
        ->and(ExcelDate::excelToDateTimeObject($from->getValue())->format('Y-m-d'))->toBe(RX_FROM)
        ->and(ExcelDate::excelToDateTimeObject($to->getValue())->format('Y-m-d'))->toBe(RX_TO)
        ->and($sheet->getCell('B5')->getValue())->toBe(config('app.timezone'));
});

it('exports an empty result set as a valid workbook with zero totals', function () {
    rxSeed();
    [$spreadsheet] = rxDownload(rxOwner(), ['from' => '2025-01-01', 'to' => '2025-01-02']);

    expect(rxCell($spreadsheet->getSheetByName('Summary'), 'Total Bookings'))->toBe(0)
        ->and((float) rxCell($spreadsheet->getSheetByName('Summary'), 'Total Revenue'))->toBe(0.0)
        ->and(rxCell($spreadsheet->getSheetByName('Booking Report'), 'Total Bookings'))->toBe(0)
        ->and(rxCell($spreadsheet->getSheetByName('Vehicle Report'), 'Total (0 vehicles used)', 'B'))->toBe(0)
        ->and((float) rxCell($spreadsheet->getSheetByName('Financial Report'), 'Total Revenue'))->toBe(0.0);
});

// ── Displayed report == workbook ──────────────────────────────────────────
it('matches the displayed report page records and totals', function () {
    rxSeed();
    $owner = rxOwner();

    $page = $this->actingAs($owner)->get(route('superadmin.reports.index', ['from' => RX_FROM, 'to' => RX_TO]))->assertOk();
    [$spreadsheet] = rxDownload($owner);

    $summary = $spreadsheet->getSheetByName('Summary');
    expect(rxCell($summary, 'Total Bookings'))->toBe($page->viewData('totalBookings'))
        ->and((float) rxCell($summary, 'Total Revenue'))->toBe((float) $page->viewData('totalRevenue'))
        ->and(rxCell($summary, 'Completed'))->toBe($page->viewData('completedCount'))
        ->and(rxCell($summary, 'Cancelled'))->toBe($page->viewData('cancelledCount'));

    // status breakdown
    $booking = $spreadsheet->getSheetByName('Booking Report');
    foreach ($page->viewData('pipeline') as $line) {
        expect(rxCell($booking, $line['label']))->toBe($line['count']);
    }

    // fleet
    $vehicle = $spreadsheet->getSheetByName('Vehicle Report');
    foreach ($page->viewData('vehicleReport') as $line) {
        expect(rxCell($vehicle, $line['unit_name']))->toBe($line['trips'])
            ->and((float) rxCell($vehicle, $line['unit_name'], 'C'))->toBe((float) $line['revenue']);
    }
    expect(rxCell($vehicle, 'Total (' . $page->viewData('totalVehiclesUsed') . ' vehicles used)'))->toBe((int) $page->viewData('totalTrips'));

    // financial
    $fin = $page->viewData('financial');
    $financial = $spreadsheet->getSheetByName('Financial Report');
    expect((float) rxCell($financial, 'Additional Fees Collected'))->toBe((float) $fin['additionalFees'])
        ->and((float) rxCell($financial, 'VAT Collected'))->toBe((float) $fin['vatCollected'])
        ->and((float) rxCell($financial, 'Cash Received'))->toBe((float) $fin['cashReceived'])
        ->and((float) rxCell($financial, 'Total Revenue'))->toBe((float) $fin['totalRevenue']);
});

it('reports the expected figures for the seeded data', function () {
    rxSeed();
    [$spreadsheet] = rxDownload(rxOwner());

    $financial = $spreadsheet->getSheetByName('Financial Report');
    expect((float) rxCell($financial, 'Total Revenue'))->toBe(3000.75)
        ->and((float) rxCell($financial, 'Additional Fees Collected'))->toBe(150.0)
        ->and((float) rxCell($financial, 'VAT Collected'))->toBe(360.09)
        ->and((float) rxCell($financial, 'Cash Received'))->toBe(3000.75);

    $vehicle = $spreadsheet->getSheetByName('Vehicle Report');
    expect(rxCell($vehicle, 'RX Unit Alpha'))->toBe(1)
        ->and((float) rxCell($vehicle, 'RX Unit Bravo', 'C'))->toBe(2000.25)
        ->and((float) rxCell($vehicle, 'Total (2 vehicles used)', 'C'))->toBe(3000.75);

    // detail sheet total equals the sum of listed amounts (all 3 in-range bookings)
    $details = $spreadsheet->getSheetByName('Booking Details');
    expect((float) rxCell($details, 'Total (3 bookings listed)', 'G'))->toBe(3500.75);
});

it('respects the vehicle-type and amount filters like the page does', function () {
    $seed = rxSeed();
    $owner = rxOwner();

    [$byType] = rxDownload($owner, ['section' => 'booking', 'truck_type_id' => $seed['towA']->id]);
    expect(rxCell($byType->getSheetByName('Booking Report'), 'Total Bookings'))->toBe(2); // Start Boundary + Cancelled One

    [$byAmount] = rxDownload($owner, ['section' => 'booking', 'min_amount' => 1500]);
    expect(rxCell($byAmount->getSheetByName('Booking Report'), 'Total Bookings'))->toBe(1); // End Boundary
});

// ── Cell types ────────────────────────────────────────────────────────────
it('keeps counts and currency numeric and dates as dates', function () {
    rxSeed();
    [$spreadsheet] = rxDownload(rxOwner());

    $financial = $spreadsheet->getSheetByName('Financial Report');
    $row = rxRowOf($financial, 'Total Revenue');
    $cell = $financial->getCell("B{$row}");
    expect($cell->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($cell->getStyle()->getNumberFormat()->getFormatCode())->toContain('#,##0.00');

    $bookingSheet = $spreadsheet->getSheetByName('Booking Report');
    $total = rxRowOf($bookingSheet, 'Total Bookings');
    expect($bookingSheet->getCell("B{$total}")->getDataType())->toBe(DataType::TYPE_NUMERIC);

    $details = $spreadsheet->getSheetByName('Booking Details');
    expect(ExcelDate::isDateTime($details->getCell('B4')))->toBeTrue()
        ->and($details->getCell('G4')->getDataType())->toBe(DataType::TYPE_NUMERIC);
});

// ── Formula injection ─────────────────────────────────────────────────────
it('never writes user-controlled text as a formula', function () {
    $type = rxTruckType('RX Heavy');
    $unit = rxUnit('=HYPERLINK("http://evil.example","unit")', $type);
    rxBooking('2026-03-11 09:00:00', 'completed', 100, $unit, $type, '=cmd|\' /C calc\'!A1');
    rxBooking('2026-03-11 09:30:00', 'completed', 100, null, $type, '@SUM(1+1)');
    rxBooking('2026-03-11 09:45:00', 'completed', 100, null, $type, '+1+1');

    [$spreadsheet] = rxDownload(rxOwner());

    $details = $spreadsheet->getSheetByName('Booking Details');
    $seen = [];
    foreach ($details->getRowIterator(4) as $row) {
        foreach (['C', 'E'] as $col) {
            $cell = $details->getCell($col . $row->getRowIndex());
            if (preg_match('/^\'?[=@+\-]/', (string) $cell->getValue())) {
                $seen[] = $cell->getValue();
                expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
                    ->and($cell->isFormula())->toBeFalse()
                    ->and(str_starts_with((string) $cell->getValue(), "'"))->toBeTrue();
            }
        }
    }
    expect($seen)->not->toBeEmpty();

    $vehicle = $spreadsheet->getSheetByName('Vehicle Report');
    $unitCell = $vehicle->getCell('A' . (rxRowOf($vehicle, "'=HYPERLINK(\"http://evil.example\",\"unit\")") ?? 0));
    expect($unitCell->isFormula())->toBeFalse();
});

it('escapes only dangerous leading characters', function () {
    expect(ReportWorkbookBuilder::safeText('=1+1'))->toBe("'=1+1")
        ->and(ReportWorkbookBuilder::safeText('+1'))->toBe("'+1")
        ->and(ReportWorkbookBuilder::safeText('-1'))->toBe("'-1")
        ->and(ReportWorkbookBuilder::safeText('@x'))->toBe("'@x")
        ->and(ReportWorkbookBuilder::safeText("\tx"))->toBe("'\tx")
        ->and(ReportWorkbookBuilder::safeText('Juan Dela Cruz'))->toBe('Juan Dela Cruz')
        ->and(ReportWorkbookBuilder::safeText(''))->toBe('');
});

// ── Authorization and validation ──────────────────────────────────────────
it('denies the excel export to every non-owner and to guests', function () {
    rxSeed();

    foreach ([[2, 'Dispatcher'], [3, 'Team Leader'], [4, 'Driver'], [5, 'Customer'], [6, 'System Admin']] as [$id, $name]) {
        $this->actingAs(rxUser($id, $name))->get(route('superadmin.reports.export-xlsx'))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('superadmin.reports.export-xlsx'))->assertRedirect();
});

it('rejects invalid dates, sections and filters', function () {
    $owner = rxOwner();

    $this->actingAs($owner)->get(route('superadmin.reports.export-xlsx', ['from' => 'not-a-date', 'to' => RX_TO]))->assertSessionHasErrors('from');
    $this->actingAs($owner)->get(route('superadmin.reports.export-xlsx', ['section' => 'payroll']))->assertSessionHasErrors('section');
    $this->actingAs($owner)->get(route('superadmin.reports.export-xlsx', ['min_amount' => '-5']))->assertSessionHasErrors('min_amount');
});

// ── Existing exports untouched, UI wiring ─────────────────────────────────
it('keeps the PDF and CSV exports working', function () {
    rxSeed();
    $owner = rxOwner();

    $pdf = $this->actingAs($owner)->get(route('superadmin.reports.export-pdf', ['from' => RX_FROM, 'to' => RX_TO, 'section' => 'financial']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $csv = $this->actingAs($owner)->get(route('superadmin.reports.export', ['from' => RX_FROM, 'to' => RX_TO]));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('"Total Bookings (created in period)",3');
});

it('shows an Export Excel button next to each Export PDF button', function () {
    $this->actingAs(rxOwner())->get(route('superadmin.reports.index'))
        ->assertOk()
        ->assertSee('Export Booking PDF')->assertSee('Export Booking Excel')
        ->assertSee('Export Fleet PDF')->assertSee('Export Fleet Excel')
        ->assertSee('Export Financial PDF')->assertSee('Export Financial Excel');
});

it('builds the workbook without evaluating formulas', function () {
    $builder = new ReportWorkbookBuilder();
    $spreadsheet = $builder->build([
        'start' => Carbon::parse(RX_FROM), 'end' => Carbon::parse(RX_TO),
        'totalBookings' => 0, 'totalRevenue' => 0.0, 'completedCount' => 0, 'pendingCount' => 0, 'cancelledCount' => 0,
        'completionRate' => 0.0, 'pipeline' => collect(), 'vehicleReport' => collect(), 'totalVehiclesUsed' => 0, 'totalTrips' => 0,
        'financial' => ['totalRevenue' => 0.0, 'additionalFees' => 0.0, 'vatCollected' => 0.0, 'cashReceived' => 0.0, 'averagePerBooking' => 0.0],
    ], 'financial', 'custom', ['truck_type_id' => null, 'min_amount' => null, 'max_amount' => null], null);

    expect($spreadsheet)->toBeInstanceOf(Spreadsheet::class)
        ->and($spreadsheet->getSheetCount())->toBe(1);
});
