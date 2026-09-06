<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\LandingSetting;
use App\Models\Receipt;
use App\Models\SystemSetting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentGenerationService
{
    public function generateQuotation(Booking $booking, bool $isFinal = false): string
    {
        $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader']);

        $html = view('documents.quotation', [
            'booking' => $booking,
            'settings' => $this->documentSettings(),
            'isFinal' => $isFinal,
            'generatedAt' => now(),
        ])->render();

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
        Storage::disk('local')->put($path, $this->renderPdf($html));

        return $path;
    }

    public function generateReceipt(Booking $booking): Receipt
    {
        $booking->loadMissing(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt']);

        $receipt = Receipt::updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'invoice_id' => $booking->currentInvoice()->value('id'),
                'generated_by' => $booking->assigned_team_leader_id ?? $booking->created_by_admin_id,
                'receipt_number' => $booking->receipt->receipt_number ?? sprintf('R-%s-%04d', now()->format('Ymd'), $booking->id),
                'email_sent' => false,
            ]
        );

        $html = view('documents.receipt', [
            'booking' => $booking,
            'receipt' => $receipt,
            'settings' => $this->documentSettings(),
            'generatedAt' => now(),
        ])->render();

        $path = sprintf('documents/receipts/booking-%d-receipt.pdf', $booking->id);
        $legacyHtmlPath = sprintf('documents/receipts/booking-%d-receipt.html', $booking->id);

        Storage::disk('public')->delete($legacyHtmlPath);
        Storage::disk('local')->put($path, $this->renderPdf($html));

        $receipt->update([
            'pdf_path' => $path,
        ]);

        return $receipt->fresh();
    }

    public function generateInvoice(Invoice $invoice): Invoice
    {
        $booking = $invoice->booking()->with(['customer', 'truckType'])->first();

        $html = view('documents.invoice', [
            'booking' => $booking,
            'invoice' => $invoice,
            'settings' => $this->documentSettings(),
            'generatedAt' => now(),
        ])->render();

        $path = sprintf('documents/invoices/invoice-%d.pdf', $invoice->id);
        Storage::disk('local')->put($path, $this->renderPdf($html));

        $invoice->update(['pdf_path' => $path]);

        return $invoice->fresh();
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
            'bank_name' => SystemSetting::getValue('bank_name', 'BDO Bank'),
            'bank_account_name' => SystemSetting::getValue('bank_account_name', 'not yet set'),
            'bank_account_number' => SystemSetting::getValue('bank_account_number', '123456789012'),
            'gcash_name' => SystemSetting::getValue('gcash_name', 'not yet set'),
            'gcash_number' => SystemSetting::getValue('gcash_number', '12345678901'),
            'payment_terms' => SystemSetting::getValue('payment_terms', 'Pay upon service confirmation'),
            'quote_prefix' => SystemSetting::getValue('quote_prefix', 'Q'),
            'logo_url' => $this->assetUrl(SystemSetting::getValue('company_logo'), 'customer/image/TowingLogo.png'),
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

    protected function renderPdf(string $html, ?string $footerText = null): string
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

        if ($footerText !== null) {
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

        if (Str::startsWith($path, ['customer/', 'home_page/', 'admin/', 'superadmin/'])) {
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
