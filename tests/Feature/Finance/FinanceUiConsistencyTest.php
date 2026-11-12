<?php

use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// One design for every Finance screen: solid status badges with white text, readable text colours, neutral controls,
// no decorative left borders. These tests guard the shared stylesheet, the Finance views and print templates.

function fuiCss(): string
{
    return file_get_contents(base_path('public/superadmin/css/finance.css'));
}

/** @return array<string, string> the --fin-* tokens declared on :root */
function fuiTokens(): array
{
    preg_match('/:root\s*\{(.*?)\}/s', fuiCss(), $root);
    preg_match_all('/--(fin-[a-z-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $root[1], $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($x) => [$x[1] => strtolower($x[2])])->all();
}

function fuiLum(string $hex): float
{
    $hex = ltrim($hex, '#');
    $hex = strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex;
    $c = array_map(fn ($v) => hexdec($v) / 255, str_split($hex, 2));
    $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function fuiRatio(string $a, string $b): float
{
    [$x, $y] = [fuiLum($a), fuiLum($b)];

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** Every Finance-owned file that carries markup or styles. */
function fuiFinanceFiles(): array
{
    $files = [base_path('public/superadmin/css/finance.css'), base_path('resources/views/admin-dashboard/pages/finance/expense-review.blade.php')];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('resources/views/superadmin/finance'))) as $f) {
        if ($f->isFile()) {
            $files[] = $f->getPathname();
        }
    }
    foreach (['commission-statement', 'client-review', 'finance-report'] as $doc) {
        $files[] = base_path("resources/views/documents/{$doc}.blade.php");
    }
    $files[] = base_path('resources/views/documents/partials/finance-report-body.blade.php');

    return $files;
}

it('uses status colours that give white text at least 4.5:1, and body text colours that stay dark', function () {
    $t = fuiTokens();
    foreach (['fin-green', 'fin-amber', 'fin-red', 'fin-gray', 'fin-blue'] as $badge) {
        expect(fuiRatio('#ffffff', $t[$badge]))->toBeGreaterThanOrEqual(4.5, "white on {$badge}");
    }
    foreach (['fin-ink', 'fin-body', 'fin-muted'] as $text) {
        expect(fuiRatio($t[$text], '#ffffff'))->toBeGreaterThanOrEqual(7.0, "{$text} on white");
        expect(fuiRatio($t[$text], '#f5f5f5'))->toBeGreaterThanOrEqual(7.0, "{$text} on light grey");
    }
    expect(fuiRatio($t['fin-control-line'], '#ffffff'))->toBeGreaterThanOrEqual(3.0)   // control borders (WCAG 1.4.11)
        ->and(fuiRatio('#6b6b6b', '#ffffff'))->toBeGreaterThanOrEqual(4.5)             // placeholders
        ->and(fuiRatio('#facc15', '#111827'))->toBeGreaterThanOrEqual(4.5);            // yellow totals on the black row
});

it('defines every status badge as a solid colour with white text', function () {
    $css = fuiCss();
    expect($css)->toMatch('/\.fin-status\s*\{[^}]*background:\s*var\(--fin-gray\)[^}]*color:\s*#fff/s');
    foreach (['green' => 'green', 'amber' => 'amber', 'red' => 'red', 'blue' => 'blue', 'grey' => 'gray'] as $name => $token) {
        expect($css)->toMatch('/\.fin-status--' . $name . '\s*\{\s*background:\s*var\(--fin-' . $token . '\)/');
    }
    foreach ([['.fin-tag', 'fin-gray'], ['.fin-tag--void', 'fin-red'], ['.fin-pending', 'fin-amber']] as [$selector, $token]) {
        expect($css)->toMatch('/' . preg_quote($selector, '/') . '\s*\{[^}]*background:\s*var\(--' . $token . '\)/');
    }
    // the old pale-background badges (green/amber/red/blue text on a tint) are gone everywhere
    foreach (fuiFinanceFiles() as $file) {
        expect(file_get_contents($file))->not->toMatch('/#(dcfce7|fef3c7|fee2e2|dbeafe|bbf7d0|fde68a|fecaca)\b/i', basename($file));
    }
});

it('has no decorative left borders in Finance styles, views, forms or print templates', function () {
    foreach (fuiFinanceFiles() as $file) {
        expect(file_get_contents($file))->not->toMatch('/border-left(?!-width\s*:\s*0)/i', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
    }
});

it('keeps every Finance text colour readable on a light background', function () {
    $css = fuiCss();
    $t = fuiTokens();
    $checked = 0;
    foreach (explode('}', preg_replace('#/\*.*?\*/#s', '', $css)) as $block) {
        if (! str_contains($block, '{') || preg_match('/background(-color)?\s*:/', $block) || preg_match('/fin-week-total|is-dark|::placeholder|:disabled/', $block)) {
            continue;   // badges, dark rows and alerts declare their own background (checked above and below)
        }
        if (preg_match('/(?<![-\w])color:\s*(#[0-9a-fA-F]{6}|var\(--(fin-[a-z-]+)\))/', $block, $m)) {
            $color = str_starts_with($m[1], '#') ? strtolower($m[1]) : $t[$m[2]];
            expect(fuiRatio($color, '#ffffff'))->toBeGreaterThanOrEqual(4.5, trim(explode('{', $block)[0]) . " uses {$m[1]}");
            $checked++;
        }
    }
    // pale greys are only allowed on the dark rows (black totals, dark KPI card)
    $light = collect(explode('}', $css))->reject(fn ($b) => preg_match('/fin-week-total|is-dark/', $b))->implode('}');
    expect($checked)->toBeGreaterThan(20)
        ->and($light)->not->toMatch('/(?<![-\w])color:\s*#(a3a3a3|9ca3af|d4d4d4|d1d5db|e5e5e5)/i');
    // alerts: dark text on their tint
    foreach (['#14532d' => '#f0fdf4', '#7f1d1d' => '#fef2f2', '#78350f' => '#fffbeb'] as $text => $tint) {
        expect(fuiRatio($text, $tint))->toBeGreaterThanOrEqual(7.0)
            ->and($css)->toContain($text);
    }
});

it('prints status and footer text at AA contrast with a solid status badge', function () {
    $doc = file_get_contents(base_path('resources/views/documents/commission-statement.blade.php'));
    expect($doc)->toMatch('/\.status\s*\{[^}]*background:\s*#404040[^}]*color:\s*#fff/')
        ->and($doc)->toContain('.status.ok { background: #166534; }')->toContain('.status.review { background: #1d4ed8; }')->toContain('.status.void { background: #b91c1c; }');
    foreach (['commission-statement', 'client-review', 'finance-report'] as $name) {
        preg_match('/\.footer\s*\{[^}]*color:\s*(#[0-9a-f]{3,6})/i', file_get_contents(base_path("resources/views/documents/{$name}.blade.php")), $m);
        expect(fuiRatio($m[1], '#ffffff'))->toBeGreaterThanOrEqual(4.5, "{$name} footer");
    }
});

it('renders statement, crew and commission statuses with the shared solid colours', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = finReviewStatement($owner);

    // For review = under review (blue); approved and paid = green; a payout not yet recorded = neutral grey.
    $this->actingAs($owner)->get(route('superadmin.finance.statements.index'))->assertOk()->assertSee('fin-status fin-status--blue', false);
    $approved = app(CommissionStatementService::class)->approve($owner, $s['statement']);
    $this->actingAs($owner)->get(route('superadmin.finance.statements.index'))->assertOk()
        ->assertSee('fin-status fin-status--green', false)->assertDontSee('fin-status--blue', false);
    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $approved->id))->assertOk()
        ->assertSee('fin-status fin-status--green', false)->assertSee('fin-status fin-status--grey">Not recorded', false);
    $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $approved->id))->assertOk()
        ->assertSee('class="status ok"', false)->assertSee('.status.ok { background: #166534; }', false);

    // Blocked commission lines = red; a job with no crew yet = grey "Not recorded" on Job Crew.
    $unit = finUnit();
    finServicedBooking($unit, '800.00');
    $week = finLastWeek()->toDateString();
    $this->actingAs($owner)->get(route('superadmin.finance.commission.index', ['week' => $week, 'unit_id' => $unit->id]))->assertOk()
        ->assertSee('fin-status fin-status--red">', false)->assertSee('blocker(s)')->assertSee('fin-status fin-status--amber">Unallocated', false);
    $this->actingAs($owner)->get(route('superadmin.finance.crew.index', ['week' => $week, 'unit_id' => $unit->id]))->assertOk()
        ->assertSee('fin-status fin-status--grey">Not recorded', false)->assertDontSee('fin-status--red', false);
});

it('shows Dispatcher expense review statuses as solid badges inside the Finance page frame', function () {
    Storage::fake('local');
    $teamLeader = finUser(3);
    $unit = finUnit($teamLeader);
    $expense = app(ExpenseService::class)->submit($teamLeader, [
        'unit_id' => $unit->id, 'expense_scope' => 'unit', 'coverage_month' => now(config('finance.timezone'))->format('Y-m'),
        'expense_date' => now(config('finance.timezone'))->toDateString(), 'category' => 'fuel_diesel', 'description' => 'Diesel',
        'amount' => '500', 'fundings' => ['company_direct' => '500'],
    ], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', 20, 20)]);
    $dispatcher = finUser(2);
    app(ExpenseService::class)->verify($dispatcher, $expense, 'OK');

    $this->actingAs($dispatcher)->get(route('admin.expense-review.index'))->assertOk()
        ->assertSee('class="xr-page fin-page"', false)
        ->assertSee('fin-status fin-status--amber">Awaiting Owner approval', false);
});

it('keeps Finance pages Owner-only after the UI changes', function () {
    foreach ([2, 3, 5, 6] as $role) {
        $user = finUser($role);
        foreach (['superadmin.finance.commission.index', 'superadmin.finance.driver-records.index', 'superadmin.finance.crew.index', 'superadmin.finance.statements.index'] as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }
});

/** One statement per state, each on its own unit (Draft, For review, Approved, Paid, Voided). */
function fuiStatementsByState($owner): array
{
    $svc = app(CommissionStatementService::class);
    $pay = app(\App\Services\Finance\PayoutService::class);
    $out = [];

    $out['for_review'] = finReviewStatement($owner)['statement'];
    $out['draft'] = $svc->returnToDraft($owner, finReviewStatement($owner)['statement'], 'Back to draft for the UI check');
    $out['approved'] = $svc->approve($owner, finReviewStatement($owner)['statement']);

    $paid = $svc->approve($owner, finReviewStatement($owner)['statement']);
    foreach (array_keys($pay->earningsByRecipient($paid)) as $key) {
        $pay->record($owner, $paid, $key, 'cash', now(config('finance.timezone'))->toDateString(), (string) Str::uuid(), 'REF-' . Str::upper(Str::random(6)));
    }
    $out['paid'] = $pay->markPaid($owner, $paid->fresh());

    $out['voided'] = $svc->void($owner, $svc->approve($owner, finReviewStatement($owner)['statement']), 'Voided for the UI check');

    return $out;
}

it('shows Draft, For review, Approved, Paid and Voided statements with the right solid badge, on screen and in print', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = fuiStatementsByState($owner);
    expect(collect($s)->map->status->all())->toBe(['for_review' => 'for_review', 'draft' => 'draft', 'approved' => 'approved', 'paid' => 'paid', 'voided' => 'voided']);

    $tone = ['draft' => 'grey', 'for_review' => 'blue', 'approved' => 'green', 'paid' => 'green', 'voided' => 'red'];
    $label = ['draft' => 'Draft', 'for_review' => 'For Review', 'approved' => 'Approved', 'paid' => 'Paid', 'voided' => 'Voided'];
    $printClass = ['draft' => 'status ', 'for_review' => 'status review', 'approved' => 'status ok', 'paid' => 'status ok', 'voided' => 'status void'];

    foreach ($s as $state => $statement) {
        $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))->assertOk()
            ->assertSee('fin-status fin-status--' . $tone[$state] . '">' . $label[$state], false);
        $this->actingAs($owner)->get(route('superadmin.finance.statements.index', ['status' => $state]))->assertOk()
            ->assertSee('fin-status fin-status--' . $tone[$state] . '">' . $label[$state], false)->assertSee($statement->reference_code);
        $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $statement->id))->assertOk()
            ->assertSee('class="' . $printClass[$state] . '" data-doc-status="' . $state . '"', false);
    }
});

it('words the payout panel for the statement\'s state, and keeps recording controls only while it is Approved', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = fuiStatementsByState($owner);
    $show = fn ($statement) => $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))->assertOk();

    $show($s['approved'])->assertSee('Record each payment after you have paid the person')->assertSee('Record payout')->assertSee('data-po-mark-paid', false);
    $show($s['paid'])->assertSee('Every payout is recorded and the statement is Paid, which is final.')->assertDontSee('Record each payment after you have paid the person')
        ->assertDontSee('Record payout')->assertDontSee('data-po-mark-paid', false)->assertSee('fin-status fin-status--green">Recorded', false);
    $show($s['voided'])->assertSee('This statement is voided, so no payouts can be recorded.')->assertDontSee('Record each payment after you have paid the person')
        ->assertDontSee('Record payout')->assertDontSee('data-po-mark-paid', false);
    // the earlier payout records of a Paid statement stay listed (audit trail)
    expect(\App\Models\Finance\Payout::where('statement_id', $s['paid']->id)->where('status', 'recorded')->count())->toBeGreaterThan(0);
});
