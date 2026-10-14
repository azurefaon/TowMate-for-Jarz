<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the Excel (.xlsx) version of the owner reports from the exact same
 * summary data the page and the PDF use. Numbers are written as numeric cells,
 * dates as Excel dates, and every user-controlled string is written as an
 * explicit string cell (never a formula).
 */
class ReportWorkbookBuilder
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Hard cap on per-booking detail rows. Measured peak memory: ~102 MB at 5,000 rows, ~134 MB at 10,000, so 5,000 stays under the 128M PHP limit used in production. */
    public const DETAIL_ROW_CAP = 5000;

    private const FORMAT_PESO = '"₱"#,##0.00';
    private const FORMAT_INT = '#,##0';
    private const FORMAT_PERCENT = '0.0';
    private const FORMAT_DATE = 'yyyy-mm-dd';
    private const FORMAT_DATETIME = 'yyyy-mm-dd hh:mm';

    /**
     * @param  array  $summary  Output of ReportsController::buildSummary()
     * @param  string  $section  all|booking|vehicle|financial
     * @param  Builder|null  $detailQuery  Per-booking query (already filtered), for the booking section
     */
    public function build(array $summary, string $section, string $period, array $filters, ?string $truckTypeName, ?Builder $detailQuery = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if ($section === 'all') {
            $this->summarySheet($spreadsheet, $summary, $period, $filters, $truckTypeName);
        }
        if (in_array($section, ['all', 'booking'], true)) {
            $this->bookingSheet($spreadsheet, $summary, $period, $filters, $truckTypeName);
            if ($detailQuery) {
                $this->bookingDetailSheet($spreadsheet, $summary, $detailQuery);
            }
        }
        if (in_array($section, ['all', 'vehicle'], true)) {
            $this->vehicleSheet($spreadsheet, $summary, $period, $filters, $truckTypeName);
        }
        if (in_array($section, ['all', 'financial'], true)) {
            $this->financialSheet($spreadsheet, $summary, $period, $filters, $truckTypeName);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function write(Spreadsheet $spreadsheet, string $path): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
    }

    /** Neutralise spreadsheet formula injection for user-controlled text. */
    public static function safeText(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $text;
        }

        return $text;
    }

    private function summarySheet(Spreadsheet $spreadsheet, array $summary, string $period, array $filters, ?string $truckTypeName): void
    {
        $sheet = $this->newSheet($spreadsheet, 'Summary');
        $row = $this->header($sheet, 'Reports Summary', $summary, $period, $filters, $truckTypeName);

        $this->tableHead($sheet, $row, ['Metric', 'Value']);
        $metrics = [
            ['Total Bookings', (int) $summary['totalBookings'], self::FORMAT_INT],
            ['Total Revenue', (float) $summary['totalRevenue'], self::FORMAT_PESO],
            ['Completed', (int) $summary['completedCount'], self::FORMAT_INT],
            ['Pending', (int) $summary['pendingCount'], self::FORMAT_INT],
            ['Cancelled', (int) $summary['cancelledCount'], self::FORMAT_INT],
            ['Completion Rate (%)', round((float) $summary['completionRate'], 1), self::FORMAT_PERCENT],
        ];
        foreach ($metrics as [$label, $value, $format]) {
            $row++;
            $this->text($sheet, "A{$row}", $label);
            $this->number($sheet, "B{$row}", $value, $format);
        }

        $this->finish($sheet);
    }

    private function bookingSheet(Spreadsheet $spreadsheet, array $summary, string $period, array $filters, ?string $truckTypeName): void
    {
        $sheet = $this->newSheet($spreadsheet, 'Booking Report');
        $row = $this->header($sheet, 'Booking Report — Status Breakdown', $summary, $period, $filters, $truckTypeName);

        $this->tableHead($sheet, $row, ['Status', 'Bookings', 'Share (%)']);
        foreach ($summary['pipeline'] as $line) {
            $row++;
            $this->text($sheet, "A{$row}", $line['label']);
            $this->number($sheet, "B{$row}", (int) $line['count'], self::FORMAT_INT);
            $this->number($sheet, "C{$row}", round((float) $line['share'], 1), self::FORMAT_PERCENT);
        }

        $row++;
        $this->text($sheet, "A{$row}", 'Total Bookings');
        $this->number($sheet, "B{$row}", (int) $summary['totalBookings'], self::FORMAT_INT);
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);

        $this->finish($sheet);
    }

    private function bookingDetailSheet(Spreadsheet $spreadsheet, array $summary, Builder $query): void
    {
        $sheet = $this->newSheet($spreadsheet, 'Booking Details');
        $this->text($sheet, 'A1', 'Booking Details');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $this->tableHead($sheet, 3, ['Booking #', 'Created', 'Customer', 'Truck Type', 'Unit', 'Status', 'Amount']);

        $row = 3;
        $total = 0.0;
        $count = 0;
        $truncated = false;

        foreach ($query->cursor() as $booking) {
            if ($count >= self::DETAIL_ROW_CAP) {
                $truncated = true;
                break;
            }
            $count++;
            $row++;

            $this->text($sheet, "A{$row}", $booking->job_code);
            if ($booking->created_at) {
                $sheet->setCellValue("B{$row}", ExcelDate::PHPToExcel($booking->created_at->copy()));
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::FORMAT_DATETIME);
            }
            $this->text($sheet, "C{$row}", $booking->customer->full_name ?? 'Guest');
            $this->text($sheet, "D{$row}", $booking->truckType->name ?? '—');
            $this->text($sheet, "E{$row}", $booking->display_unit_name ?? '—');
            $this->text($sheet, "F{$row}", ucfirst(str_replace('_', ' ', (string) $booking->status)));
            $this->number($sheet, "G{$row}", (float) $booking->final_total, self::FORMAT_PESO);
            $total += (float) $booking->final_total;
        }

        $row++;
        $this->text($sheet, "A{$row}", 'Total (' . $count . ' bookings listed)');
        $this->number($sheet, "G{$row}", round($total, 2), self::FORMAT_PESO);
        $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);

        if ($truncated) {
            $row += 2;
            $this->text($sheet, "A{$row}", 'Only the first ' . number_format(self::DETAIL_ROW_CAP) . ' bookings are listed. Narrow the date range or filters to export the rest.');
        }

        $this->finish($sheet);
    }

    private function vehicleSheet(Spreadsheet $spreadsheet, array $summary, string $period, array $filters, ?string $truckTypeName): void
    {
        $sheet = $this->newSheet($spreadsheet, 'Vehicle Report');
        $row = $this->header($sheet, 'Vehicle Report — Units Used', $summary, $period, $filters, $truckTypeName);

        $this->tableHead($sheet, $row, ['Unit', 'Trips', 'Revenue']);
        $revenue = 0.0;
        foreach ($summary['vehicleReport'] as $line) {
            $row++;
            $this->text($sheet, "A{$row}", $line['unit_name']);
            $this->number($sheet, "B{$row}", (int) $line['trips'], self::FORMAT_INT);
            $this->number($sheet, "C{$row}", (float) $line['revenue'], self::FORMAT_PESO);
            $revenue += (float) $line['revenue'];
        }

        $row++;
        $this->text($sheet, "A{$row}", 'Total (' . (int) $summary['totalVehiclesUsed'] . ' vehicles used)');
        $this->number($sheet, "B{$row}", (int) $summary['totalTrips'], self::FORMAT_INT);
        $this->number($sheet, "C{$row}", round($revenue, 2), self::FORMAT_PESO);
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);

        $this->finish($sheet);
    }

    private function financialSheet(Spreadsheet $spreadsheet, array $summary, string $period, array $filters, ?string $truckTypeName): void
    {
        $sheet = $this->newSheet($spreadsheet, 'Financial Report');
        $row = $this->header($sheet, 'Financial Report', $summary, $period, $filters, $truckTypeName);
        $financial = $summary['financial'];

        $this->tableHead($sheet, $row, ['Item', 'Amount']);
        $items = [
            ['Additional Fees Collected', $financial['additionalFees']],
            ['VAT Collected', $financial['vatCollected']],
            ['Cash Received', $financial['cashReceived']],
            ['Average Revenue per Completed Booking', $financial['averagePerBooking']],
        ];
        foreach ($items as [$label, $value]) {
            $row++;
            $this->text($sheet, "A{$row}", $label);
            $this->number($sheet, "B{$row}", (float) $value, self::FORMAT_PESO);
        }

        $row++;
        $this->text($sheet, "A{$row}", 'Total Revenue');
        $this->number($sheet, "B{$row}", (float) $financial['totalRevenue'], self::FORMAT_PESO);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);

        $this->finish($sheet);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function newSheet(Spreadsheet $spreadsheet, string $title): Worksheet
    {
        return $spreadsheet->createSheet()->setTitle($title);
    }

    /** Title + metadata block; returns the row number of the table header. */
    private function header(Worksheet $sheet, string $title, array $summary, string $period, array $filters, ?string $truckTypeName): int
    {
        $this->text($sheet, 'A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $this->text($sheet, 'A2', 'Period');
        $this->text($sheet, 'B2', ucfirst($period));

        $this->text($sheet, 'A3', 'From');
        $this->date($sheet, 'B3', $summary['start']);
        $this->text($sheet, 'A4', 'To');
        $this->date($sheet, 'B4', $summary['end']);

        $this->text($sheet, 'A5', 'Timezone');
        $this->text($sheet, 'B5', (string) config('app.timezone'));

        $row = 5;
        if ($truckTypeName) {
            $row++;
            $this->text($sheet, "A{$row}", 'Vehicle type');
            $this->text($sheet, "B{$row}", $truckTypeName);
        }
        if (($filters['min_amount'] ?? null) !== null) {
            $row++;
            $this->text($sheet, "A{$row}", 'Min amount');
            $this->number($sheet, "B{$row}", (float) $filters['min_amount'], self::FORMAT_PESO);
        }
        if (($filters['max_amount'] ?? null) !== null) {
            $row++;
            $this->text($sheet, "A{$row}", 'Max amount');
            $this->number($sheet, "B{$row}", (float) $filters['max_amount'], self::FORMAT_PESO);
        }

        return $row + 2;
    }

    private function tableHead(Worksheet $sheet, int $row, array $headings): void
    {
        foreach ($headings as $i => $heading) {
            $cell = chr(65 + $i) . $row;
            $this->text($sheet, $cell, $heading);
        }
        $range = 'A' . $row . ':' . chr(64 + count($headings)) . $row;
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function text(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, self::safeText($value), DataType::TYPE_STRING);
    }

    private function number(Worksheet $sheet, string $cell, int|float $value, string $format): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
    }

    private function date(Worksheet $sheet, string $cell, Carbon $date): void
    {
        $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date->copy()->startOfDay()));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::FORMAT_DATE);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function finish(Worksheet $sheet): void
    {
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
