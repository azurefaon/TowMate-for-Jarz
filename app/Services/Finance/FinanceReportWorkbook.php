<?php

namespace App\Services\Finance;

use App\Services\ReportWorkbookBuilder;
use App\Support\Money;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel workbook for the Owner Finance Report, built from the SAME array as the page and the PDF
 * (FinanceReportService::build), so every total matches. Text goes through ReportWorkbookBuilder::safeText
 * (formula-injection guard) and is stored as text; amounts are numeric cells formatted as Philippine pesos.
 */
class FinanceReportWorkbook
{
    public const MIME = ReportWorkbookBuilder::MIME;
    private const PESO = '"₱"#,##0.00';
    private const INT = '#,##0';

    public function build(array $r): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('TowMate')->setTitle('Finance Report');
        $book->removeSheetByIndex(0);

        $this->summary($book, $r);
        $this->jobs($book, $r);
        $this->review($book, $r);
        $this->expenses($book, $r);
        $this->funds($book, $r);
        $this->draft($book, $r);
        $this->approved($book, $r);
        $this->payouts($book, $r);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function write(Spreadsheet $book, string $path): void
    {
        (new Xlsx($book))->save($path);
    }

    // ------------------------------------------------------------------ sheets

    private function summary(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'Summary', 'Finance Report — Summary', $r);
        $t = $r['jobs']['totals'];
        $e = $r['expenses']['summary'];
        $f = $r['funds']['totals'];
        $d = $r['draft'];
        $a = $r['approved'];
        $p = $r['payouts'];

        $rows = [
            ['ACTUAL COLLECTIONS (service date)', null, null],
            ['Completed bookings', $t['completed_bookings'], self::INT],
            ['Vehicles towed', $t['vehicles'], self::INT],
            ['Final agreed charges', $t['agreed'], self::PESO],
            ['Verified customer collections', $t['collected'], self::PESO],
            ['Outstanding customer balances', $t['outstanding'], self::PESO],
            ['Allocated to units', $t['allocated'], self::PESO],
            ['Jobs with allocation pending review', $t['pending_allocations'], self::INT],
            ['EXPENSES (expense date)', null, null],
            ['Approved expenses', $e['approved']['total'], self::PESO],
            ['  of which issued unit funds', $e['approved_unit_fund'], self::PESO],
            ['  of which direct company payment', $e['approved_company'], self::PESO],
            ['  of which personal advance (reimbursement rule pending client confirmation)', $e['approved_personal'], self::PESO],
            ['Awaiting review (not deducted)', $e['submitted']['total']->plus($e['verified']['total']), self::PESO],
            ['UNIT FUNDS (cash held by units; not income, not expenses)', null, null],
            ['Opening balance', $f['opening'], self::PESO],
            ['Funds issued', $f['issued'], self::PESO],
            ['Spent (approved unit-fund expenses)', $f['spent'], self::PESO],
            ['Funds returned', $f['returned'], self::PESO],
            ['Remaining balance', $f['remaining'], self::PESO],
            [($r['meta']['policy_confirmed'] ? 'COMMISSION — DRAFT ESTIMATE' : 'COMMISSION — DRAFT / PENDING CLIENT CONFIRMATION') . ' (not approved)', null, null],
            ['Draft sharing base', $d['base'], self::PESO],
            ['Draft company share', $d['company'], self::PESO],
            ['Draft crew commission', $d['crew'], self::PESO],
            ['Unallocated (no one recorded in the role)', $d['unallocated'], self::PESO],
            ['APPROVED COMMISSION (' . $a['filter_label'] . '; frozen snapshots)', null, null],
            ['Statements', $a['count'], self::INT],
            ['Approved sharing base', $a['base'], self::PESO],
            ['Approved company share', $a['company'], self::PESO],
            ['Approved crew payable', $a['personnel'], self::PESO],
            ['RECORDED PAYOUTS (actual disbursements recorded by the Owner)', null, null],
            ['Payouts recorded', $p['count'], self::INT],
            ['Total paid', $p['total'], self::PESO],
        ];
        $row = $this->head($s, ['Item', 'Amount']);
        foreach ($rows as [$label, $value, $format]) {
            $this->text($s, "A{$row}", $label);
            if ($value === null) {
                $s->getStyle("A{$row}")->getFont()->setBold(true);
            } else {
                $this->value($s, "B{$row}", $value, $format);
            }
            $row++;
        }
        $this->finish($s);
    }

    private function jobs(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'Jobs', 'Jobs and collections (one row per booking; vehicles counted separately)', $r);
        $row = $this->head($s, ['Booking', 'Group', 'Service date', 'Payment date', 'Customer', 'Units', 'Vehicles', 'Agreed charge', 'Verified collection', 'Outstanding', 'Payment status', 'Unit allocation', 'Allocation split', 'Notes']);
        foreach ($r['jobs']['rows'] as $j) {
            $this->jobLine($s, $row++, $j);
        }
        $t = $r['jobs']['totals'];
        $this->text($s, "A{$row}", 'TOTAL (' . $t['bookings'] . ' bookings)');
        $this->value($s, "G{$row}", $t['vehicles'], self::INT);
        $this->value($s, "H{$row}", $t['agreed'], self::PESO);
        $this->value($s, "I{$row}", $t['collected'], self::PESO);
        $this->value($s, "J{$row}", $t['outstanding'], self::PESO);
        $s->getStyle("A{$row}:N{$row}")->getFont()->setBold(true);
        $this->finish($s);
    }

    private function review(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'For Review', 'Items kept visible for review (no allocation rule applied; pending client confirmation)', $r);
        $row = $this->head($s, ['Category', 'Booking', 'Group', 'Service date', 'Payment date', 'Customer', 'Units', 'Vehicles', 'Agreed charge', 'Verified collection', 'Outstanding', 'Payment status', 'Unit allocation', 'Allocation split', 'Notes']);
        foreach (['sunday' => 'Sunday job (outside the Monday–Saturday week)', 'late' => 'Collected in this period for earlier service', 'unsettled' => 'Unsettled (payment or allocation)'] as $key => $label) {
            foreach ($r['review'][$key] as $j) {
                $this->text($s, "A{$row}", $label);
                $this->jobLine($s, $row++, $j, 1);
            }
        }
        foreach ($r['review']['blockers'] as $code => $count) {
            $this->text($s, "A{$row}", 'Commission approval blocker');
            $this->text($s, "B{$row}", str_replace('_', ' ', $code));
            $this->value($s, "H{$row}", $count, self::INT);
            $row++;
        }
        $this->finish($s);
    }

    private function expenses(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'Expenses', 'Expenses by category, unit and review status (only Approved amounts are costs)', $r);
        $row = $this->head($s, ['Group', 'Item', 'Count', 'Approved', 'Awaiting review', 'Rejected or voided']);
        foreach (['by_category' => 'Category', 'by_unit' => 'Unit'] as $key => $label) {
            foreach ($r['expenses'][$key] as $x) {
                $this->text($s, "A{$row}", $label);
                $this->text($s, "B{$row}", $x['label']);
                $this->value($s, "C{$row}", $x['count'], self::INT);
                $this->value($s, "D{$row}", $x['approved'], self::PESO);
                $this->value($s, "E{$row}", $x['pending'], self::PESO);
                $this->value($s, "F{$row}", $x['closed'], self::PESO);
                $row++;
            }
        }
        $row++;
        $this->text($s, "A{$row}", 'Status');
        $s->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($r['expenses']['by_status'] as $x) {
            $this->text($s, "A{$row}", 'Status');
            $this->text($s, "B{$row}", $x['label']);
            $this->value($s, "C{$row}", $x['count'], self::INT);
            $this->value($s, "D{$row}", $x['total'], self::PESO);
            $row++;
        }
        $this->finish($s);
    }

    private function funds(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'Unit Funds', 'Unit funds: opening (carried forward) + issued − spent − returned = remaining', $r);
        $row = $this->head($s, ['Unit', 'Opening', 'Issued', 'Spent', 'Returned', 'Remaining', 'Awaiting review']);
        foreach ($r['funds']['rows'] as $u) {
            $this->text($s, "A{$row}", $u['unit_name']);
            foreach (['B' => 'opening', 'C' => 'issued', 'D' => 'spent', 'E' => 'returned', 'F' => 'remaining', 'G' => 'pending_amount'] as $col => $k) {
                $this->value($s, "{$col}{$row}", $u[$k], self::PESO);
            }
            $row++;
        }
        $t = $r['funds']['totals'];
        $this->text($s, "A{$row}", 'TOTAL');
        foreach (['B' => 'opening', 'C' => 'issued', 'D' => 'spent', 'E' => 'returned', 'F' => 'remaining', 'G' => 'pending_amount'] as $col => $k) {
            $this->value($s, "{$col}{$row}", $t[$k], self::PESO);
        }
        $s->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
        $this->finish($s);
    }

    private function draft(Spreadsheet $book, array $r): void
    {
        $label = $r['meta']['policy_confirmed'] ? 'DRAFT estimate (not approved)' : 'DRAFT / PENDING CLIENT CONFIRMATION (not approved)';
        $s = $this->sheet($book, 'Draft Commission', "Commission — {$label}", $r);
        $row = $this->head($s, ['Role', 'Allocated', 'Unallocated']);
        $this->text($s, "A{$row}", 'Company');
        $this->value($s, "B{$row}", $r['draft']['company'], self::PESO);
        $row++;
        foreach ($r['draft']['by_role'] as $x) {
            $this->text($s, "A{$row}", $x['label']);
            $this->value($s, "B{$row}", $x['allocated'], self::PESO);
            $this->value($s, "C{$row}", $x['unallocated'], self::PESO);
            $row++;
        }
        $this->text($s, "A{$row}", 'Draft sharing base');
        $this->value($s, "B{$row}", $r['draft']['base'], self::PESO);
        $s->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $row += 2;
        $row = $this->head($s, ['Person', 'Roles', 'Jobs', 'Draft amount'], $row);
        foreach ($r['draft']['by_recipient'] as $x) {
            if ($r['draft']['person'] && $x['recipient_key'] !== $r['draft']['person']['recipient_key']) {
                continue;
            }
            $this->text($s, "A{$row}", $x['name']);
            $this->text($s, "B{$row}", implode(', ', array_keys($x['roles'])));
            $this->value($s, "C{$row}", $x['jobs'], self::INT);
            $this->value($s, "D{$row}", $x['amount'], self::PESO);
            $row++;
        }
        $this->finish($s);
    }

    private function approved(Spreadsheet $book, array $r): void
    {
        $a = $r['approved'];
        $s = $this->sheet($book, 'Approved Commission', 'Commission statements — ' . $a['filter_label'] . ($a['is_draft_only'] ? ' (NOT APPROVED)' : ' (frozen approval snapshots)'), $r);
        $row = $this->head($s, ['Statement', 'Week start', 'Week end', 'Unit', 'Status', 'Jobs', 'Sharing base', 'Company', 'Crew payable', 'Approved by', 'Approved at']);
        foreach ($a['rows'] as $x) {
            $this->text($s, "A{$row}", $x['reference']);
            $this->text($s, "B{$row}", $x['period_start']->toDateString());
            $this->text($s, "C{$row}", $x['period_end']->toDateString());
            $this->text($s, "D{$row}", $x['unit']);
            $this->text($s, "E{$row}", $x['status_label']);
            $this->value($s, "F{$row}", $x['jobs'], self::INT);
            $this->value($s, "G{$row}", $x['base'], self::PESO);
            $this->value($s, "H{$row}", $x['company'], self::PESO);
            $this->value($s, "I{$row}", $x['personnel'], self::PESO);
            $this->text($s, "J{$row}", $x['approved_by']);
            $this->text($s, "K{$row}", $x['approved_at']?->format('Y-m-d H:i'));
            $row++;
        }
        $this->text($s, "A{$row}", 'TOTAL');
        $this->value($s, "G{$row}", $a['base'], self::PESO);
        $this->value($s, "H{$row}", $a['company'], self::PESO);
        $this->value($s, "I{$row}", $a['personnel'], self::PESO);
        $s->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
        $row += 2;
        $row = $this->head($s, ['Person', 'Roles', 'Approved amount'], $row);
        foreach ($a['by_recipient'] as $x) {
            $this->text($s, "A{$row}", $x['name']);
            $this->text($s, "B{$row}", $x['role_list']);
            $this->value($s, "C{$row}", $x['amount'], self::PESO);
            $row++;
        }
        $this->finish($s);
    }

    private function payouts(Spreadsheet $book, array $r): void
    {
        $s = $this->sheet($book, 'Payouts', 'Recorded payouts (actual disbursements recorded by the Owner; TowMate does not transfer money)', $r);
        $row = $this->head($s, ['Payout', 'Statement', 'Recipient', 'Role(s)', 'Date paid', 'Method', 'Reference', 'Recorded by', 'Amount']);
        foreach ($r['payouts']['rows'] as $x) {
            $this->text($s, "A{$row}", $x['reference']);
            $this->text($s, "B{$row}", $x['statement']);
            $this->text($s, "C{$row}", $x['recipient']);
            $this->text($s, "D{$row}", $x['roles']);
            $this->text($s, "E{$row}", $x['paid_at']?->toDateString());
            $this->text($s, "F{$row}", $x['method']);
            $this->text($s, "G{$row}", $x['external_reference']);
            $this->text($s, "H{$row}", $x['recorded_by']);
            $this->value($s, "I{$row}", $x['amount'], self::PESO);
            $row++;
        }
        $this->text($s, "A{$row}", 'TOTAL');
        $this->value($s, "I{$row}", $r['payouts']['total'], self::PESO);
        $s->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
        $this->finish($s);
    }

    // ------------------------------------------------------------------ helpers

    private function jobLine(Worksheet $s, int $row, array $j, int $offset = 0): void
    {
        $col = fn (int $i) => chr(ord('A') + $i + $offset);
        $this->text($s, $col(0) . $row, $j['reference']);
        $this->text($s, $col(1) . $row, $j['group_code']);
        $this->text($s, $col(2) . $row, $j['service_date']?->toDateString());
        $this->text($s, $col(3) . $row, $j['payment_date']?->toDateString());
        $this->text($s, $col(4) . $row, $j['customer']);
        $this->text($s, $col(5) . $row, $j['units']);
        $this->value($s, $col(6) . $row, $j['vehicles'], self::INT);
        $this->value($s, $col(7) . $row, $j['agreed'], self::PESO);
        $this->value($s, $col(8) . $row, $j['collected'], self::PESO);
        $this->value($s, $col(9) . $row, $j['outstanding'], self::PESO);
        $this->text($s, $col(10) . $row, $j['status_label']);
        $this->text($s, $col(11) . $row, $j['allocation_label']);
        $this->text($s, $col(12) . $row, $j['allocation']);
        $this->text($s, $col(13) . $row, implode('; ', $j['flags']));
    }

    private function sheet(Spreadsheet $book, string $name, string $title, array $r): Worksheet
    {
        $s = $book->createSheet();
        $s->setTitle($name);
        $m = $r['meta'];
        $this->text($s, 'A1', $title);
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $this->text($s, 'A2', 'Period: ' . $m['from']->format('M j, Y') . ' – ' . $m['to']->format('M j, Y') . ' (Asia/Manila business dates)'
            . ($m['weeks_from'] ? '; commission weeks ' . $m['weeks_from']->format('M j') . ' – ' . $m['weeks_to']->format('M j, Y') : ''));
        $this->text($s, 'A3', 'Filters: unit ' . ($m['unit'] ?? 'all') . '; personnel ' . ($m['person'] ?? 'all') . '; payment ' . ($m['payment_status'] ?? 'all')
            . '; expenses ' . ($m['expense_status'] ?? 'all') . '; statements ' . $m['statement_status']);
        $this->text($s, 'A4', 'Commission policy v' . ($m['policy_version'] ?? '—') . ': ' . $m['policy_label']);

        return $s;
    }

    private function head(Worksheet $s, array $headings, int $row = 6): int
    {
        foreach ($headings as $i => $h) {
            $cell = chr(ord('A') + $i) . $row;
            $this->text($s, $cell, $h);
            $s->getStyle($cell)->getFont()->setBold(true);
            $s->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FACC15');
        }

        return $row + 1;
    }

    private function text(Worksheet $s, string $cell, mixed $value): void
    {
        $s->setCellValueExplicit($cell, ReportWorkbookBuilder::safeText($value ?? ''), DataType::TYPE_STRING);
    }

    private function value(Worksheet $s, string $cell, Money|int|string $value, string $format): void
    {
        $number = $value instanceof Money ? $value->toString() : (string) $value;
        $s->setCellValueExplicit($cell, $number, DataType::TYPE_NUMERIC);
        $s->getStyle($cell)->getNumberFormat()->setFormatCode($format);
    }

    private function finish(Worksheet $s): void
    {
        foreach (range('A', 'O') as $col) {
            $s->getColumnDimension($col)->setAutoSize(true);
        }
        $s->freezePane('A7');
    }
}
