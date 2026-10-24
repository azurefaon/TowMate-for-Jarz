<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\LandingSetting;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\SystemSetting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentGenerationService
{
    public function generateQuotation(Booking $booking, bool $isFinal = false, ?Quotation $quotation = null): string
    {
        $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);

        $html = $this->quotationHtml($booking, $quotation);

        $path = sprintf(
            'documents/quotations/booking-%d-%s.pdf',
            $booking->id,
            $isFinal ? 'final' : 'initial'
        );

        $legacyHtmlPath = sprintf(
            'documents/quotations/booking-%d-%s.html',
            $booking->id,
            $isFinal ? 'final' : 'initial'
        );

        Storage::disk('public')->delete($legacyHtmlPath);
        $this->storePdf($path, $this->renderPdf($html, 'page', true));

        return $path;
    }

    /** The quotation PDF exactly as the customer receives it, rendered from the stored quotation. */
    public function renderQuotationPdf(Quotation $quotation): string
    {
        $quotation->loadMissing(['customer', 'truckType', 'sourceBooking']);

        return $this->renderPdf($this->quotationHtml($quotation->sourceBooking, $quotation), 'page', true);
    }

    private function quotationHtml(?Booking $booking, ?Quotation $quotation): string
    {
        $presenter = app(DocumentPresenter::class);

        if ($quotation === null && $booking?->quotation_id) {
            $quotation = Quotation::find($booking->quotation_id);
        }

        if ($quotation !== null) {
            $doc = $presenter->quotation($quotation, $booking);
        } else {
            $doc = $presenter->quotationForBooking($booking);
        }

        return view('documents.quotation', ['doc' => $doc])->render();
    }

    public function generateReceipt(Booking $booking, array $groupVehicles = [], float $groupAdjustment = 0.0, ?Invoice $invoice = null): Receipt
    {
        $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        $receipt = Receipt::updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'invoice_id' => $invoice?->id ?? $booking->currentInvoice()->value('id'),
                'generated_by' => $booking->assigned_team_leader_id ?? $booking->created_by_admin_id,
                'receipt_number' => $booking->receipt->receipt_number ?? sprintf('R-%s-%04d', now()->format('Ymd'), $booking->id),
            ]
        );

        $html = view('documents.receipt', [
            'doc' => app(DocumentPresenter::class)->receipt($receipt),
        ])->render();

        $path = sprintf('documents/receipts/booking-%d-receipt.pdf', $booking->id);
        $legacyHtmlPath = sprintf('documents/receipts/booking-%d-receipt.html', $booking->id);

        Storage::disk('public')->delete($legacyHtmlPath);
        $this->storePdf($path, $this->renderPdf($html, 'page', true));

        $receipt->update([
            'pdf_path' => $path,
        ]);

        return $receipt->fresh();
    }

    public function generateInvoice(Invoice $invoice, array $groupVehicles = [], float $groupAdjustment = 0.0): Invoice
    {
        $html = view('documents.invoice', [
            'doc' => app(DocumentPresenter::class)->invoice($invoice, ! empty($groupVehicles) ? $groupVehicles : null),
        ])->render();

        $path = sprintf('documents/invoices/invoice-%d.pdf', $invoice->id);
        $this->storePdf($path, $this->renderPdf($html, 'page', true));

        $invoice->update(['pdf_path' => $path]);

        return $invoice->fresh();
    }

    /**
     * Writes a PDF only when it is complete, and replaces an existing file in one step, so a failed or
     * partial render can never overwrite a valid stored document.
     */
    private function storePdf(string $path, string $bytes): void
    {
        if (! str_starts_with($bytes, '%PDF') || ! str_contains(substr($bytes, -2048), '%%EOF')) {
            throw new \RuntimeException('The generated PDF is incomplete and was not stored: ' . $path);
        }

        $disk = Storage::disk('local');
        $temporary = $path . '.tmp';

        try {
            if (! $disk->put($temporary, $bytes)) {
                throw new \RuntimeException('The PDF could not be written: ' . $path);
            }

            $disk->move($temporary, $path);
        } finally {
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }
        }
    }

    public function renderReportPdf(string $view, array $data): string
    {
        $html = view($view, array_merge($data, [
            'settings' => $this->documentSettings(),
            'generatedAt' => now(),
            'generatedBy' => auth()->user()?->full_name ?? auth()->user()?->name ?? 'System',
        ]))->render();

        return $this->renderPdf($html, 'Page {PAGE_NUM} of {PAGE_COUNT}');
    }

    public function publicDocumentUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, 'storage/')) {
            return asset($path);
        }

        $normalized = ltrim($path, '/');

        if (Storage::disk('local')->exists($normalized)) {
            return Storage::disk('local')->temporaryUrl($normalized, now()->addMinutes(30));
        }

        return asset('storage/' . $normalized);
    }

    public function documentSettings(): array
    {
        $landing = LandingSetting::query()->first();

        return [
            'company_name' => SystemSetting::getValue('company_name', 'JARZ TOWING SERVICES'),
            'company_phone' => SystemSetting::getValue('company_phone', $landing->contact_phone ?? '+63 763 4567 123'),
            'company_email' => SystemSetting::getValue('company_email', $landing->contact_email ?? 'jarztowingservices@gmail.com'),
            'company_address' => SystemSetting::getValue('company_address', $landing->contact_location ?? '#3A 1st St. Carreon Village, San Bartolome, Q.C.'),
            'bank_name' => SystemSetting::getValue('bank_name', ''),
            'bank_account_name' => SystemSetting::getValue('bank_account_name', ''),
            'bank_account_number' => SystemSetting::getValue('bank_account_number', ''),
            'gcash_name' => SystemSetting::getValue('gcash_name', ''),
            'gcash_number' => SystemSetting::getValue('gcash_number', ''),
            'payment_terms' => SystemSetting::getValue('payment_terms', 'Pay upon service confirmation'),
            'quote_prefix' => SystemSetting::getValue('quote_prefix', 'Q'),
            'logo_url' => $this->assetUrl(SystemSetting::getValue('company_logo'), 'customer/image/TowingLogo.png'),
            'document_logo_url' => $this->assetUrl(SystemSetting::getValue('company_logo'), 'dispatcher/images/jarz-logo.png'),
            'secondary_logo_url' => $this->assetUrl(SystemSetting::getValue('secondary_logo'), 'customer/image/accridetedlogo.png'),
            'signature_url' => $this->assetUrl(SystemSetting::getValue('signature_image')),
        ];
    }

    protected function assetUrl(?string $value, ?string $default = null): ?string
    {
        foreach ([$value, $default] as $candidate) {
            if (! filled($candidate)) {
                continue;
            }

            if (Str::startsWith($candidate, ['data:', 'http://', 'https://'])) {
                return $candidate;
            }

            $absolutePath = $this->resolveAbsoluteAssetPath(ltrim($candidate, '/'));

            if ($absolutePath) {
                $dataUri = $this->fileAsDataUri($absolutePath);

                if ($dataUri) {
                    return $dataUri;
                }
            }
        }

        return null;
    }

    protected function renderPdf(string $html, ?string $footerText = null, bool $rightAlignedPageNumber = false): string
    {
        if (! extension_loaded('gd')) {
            $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('dpi', 120);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        if ($footerText !== null && $rightAlignedPageNumber) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($font) {
                $text = 'Page ' . $pageNumber . ' of ' . $pageCount;
                $width = $fontMetrics->getTextWidth($text, $font, 9);
                $canvas->text($canvas->get_width() - 42 - $width, $canvas->get_height() - 42, $text, $font, 9, [0.25, 0.25, 0.25]);
            });
        } elseif ($footerText !== null) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $width = $canvas->get_width();
            $height = $canvas->get_height();

            $canvas->page_text($width - 170, $height - 30, $footerText, $font, 9, [0.2, 0.2, 0.2]);
        }

        return $dompdf->output();
    }

    protected function resolveAbsoluteAssetPath(string $path): ?string
    {
        if (Str::startsWith($path, 'storage/')) {
            $publicStoragePath = public_path($path);

            return is_file($publicStoragePath) ? $publicStoragePath : null;
        }

        if (Str::startsWith($path, ['customer/', 'home_page/', 'admin/', 'superadmin/', 'dispatcher/'])) {
            $publicAssetPath = public_path($path);

            return is_file($publicAssetPath) ? $publicAssetPath : null;
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        return null;
    }

    protected function fileAsDataUri(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        $mimeType = mime_content_type($absolutePath) ?: 'application/octet-stream';

        return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
    }
}
