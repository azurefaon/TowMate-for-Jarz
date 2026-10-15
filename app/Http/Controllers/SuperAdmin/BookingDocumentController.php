<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\DocumentPresenter;
use App\Services\OwnerBookingPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only Owner viewers for a booking's invoices and receipt. Every document
 * must belong to the booking's own billing transaction (same-trip group members
 * share one); anything else is a 404. PDFs are streamed to the authenticated
 * Owner only and are never exposed through a public URL.
 */
class BookingDocumentController extends Controller
{
    public function __construct(
        private OwnerBookingPresenter $bookings,
        private DocumentPresenter $documents,
    ) {
    }

    public function invoice(Request $request, $id, int $invoice)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->invoiceOf($booking, $invoice);
        abort_if(! $record, 404);

        $doc = $this->documents->invoice($record);
        $pdfReady = $this->pdfDisk($record->pdf_path) !== null;
        $baseUrl = route('superadmin.bookings.invoices.show', ['id' => $booking->job_code, 'invoice' => $record->id]);

        return view('superadmin.documents.invoice', [
            'booking' => $booking,
            'doc' => $doc,
            'history' => $this->bookings->invoiceHistory($booking),
            'viewing' => $record->id,
            'pdfReady' => $pdfReady,
            'stage' => $this->stage($request, $pdfReady, (bool) $doc['reliable'], 'invoice', $baseUrl),
            'viewUrl' => route('superadmin.bookings.invoices.pdf', ['id' => $booking->job_code, 'invoice' => $record->id]),
            'receiptUrl' => $this->bookings->receiptOf($booking) ? route('superadmin.bookings.receipt.show', ['id' => $booking->job_code]) : null,
        ]);
    }

    public function invoicePdf(Request $request, $id, int $invoice)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->invoiceOf($booking, $invoice);
        abort_if(! $record, 404);

        return $this->stream($record->pdf_path, $record->invoice_number . '.pdf', $request->boolean('download'));
    }

    public function receipt(Request $request, $id)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->receiptOf($booking);
        abort_if(! $record, 404);

        $doc = $this->documents->receipt($record);
        $invoice = $record->invoice_id ? $this->bookings->invoiceOf($booking, (int) $record->invoice_id) : null;
        $pdfReady = $this->pdfDisk($record->pdf_path) !== null;

        return view('superadmin.documents.receipt', [
            'booking' => $booking,
            'doc' => $doc,
            'pdfReady' => $pdfReady,
            'stage' => $this->stage($request, $pdfReady, (bool) $doc['previewable'], 'receipt', route('superadmin.bookings.receipt.show', ['id' => $booking->job_code])),
            'viewUrl' => route('superadmin.bookings.receipt.pdf', ['id' => $booking->job_code]),
            'invoiceUrl' => $invoice ? route('superadmin.bookings.invoices.show', ['id' => $booking->job_code, 'invoice' => $invoice->id]) : null,
        ]);
    }

    public function receiptPdf(Request $request, $id)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->receiptOf($booking);
        abort_if(! $record, 404);

        return $this->stream($record->pdf_path, ($record->receipt_number ?: 'receipt') . '.pdf', $request->boolean('download'));
    }

    /**
     * What the viewer's main panel shows. The original issued PDF is always the
     * authoritative document; the on-screen preview is generated from stored
     * records and is never offered as a download. When the records cannot support
     * an accurate preview, the original PDF is shown with an explanation instead.
     *
     * @return array{mode: string, switchable: bool, explanation: ?string, previewUrl: string, originalUrl: string}
     */
    private function stage(Request $request, bool $pdfReady, bool $previewable, string $noun, string $baseUrl): array
    {
        $wantsOriginal = $request->query('source') === 'original';
        $explanation = null;

        if ($pdfReady && ! $previewable) {
            $mode = 'original';
            $explanation = $noun === 'invoice'
                ? 'A modern preview is not shown because the stored records do not reconcile into an itemised breakdown. The original issued invoice is shown instead.'
                : 'A modern preview is not shown because the receipt has no related invoice to confirm the amount. The original issued receipt is shown instead.';
        } elseif ($pdfReady) {
            $mode = $wantsOriginal ? 'original' : 'preview';
        } else {
            $mode = 'preview';
        }

        return [
            'mode' => $mode,
            'switchable' => $pdfReady && $previewable,
            'explanation' => $explanation,
            'previewUrl' => $baseUrl,
            'originalUrl' => $baseUrl . '?source=original',
        ];
    }

    /** [disk, path] of a stored PDF, or null when the file does not exist. */
    private function pdfDisk(?string $path): ?array
    {
        $path = ltrim((string) $path, '/');

        if ($path === '') {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return ['local', $path];
        }

        // Older documents were written to the public disk as "storage/…".
        if (str_starts_with($path, 'storage/') && Storage::disk('public')->exists(substr($path, 8))) {
            return ['public', substr($path, 8)];
        }

        return null;
    }

    private function stream(?string $path, string $name, bool $download)
    {
        $located = $this->pdfDisk($path);
        abort_if($located === null, 404);

        return Storage::disk($located[0])->response($located[1], $name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }
}
