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

    public function invoice($id, int $invoice)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->invoiceOf($booking, $invoice);
        abort_if(! $record, 404);

        return view('superadmin.documents.invoice', [
            'booking' => $booking,
            'doc' => $this->documents->invoice($record),
            'history' => $this->bookings->invoiceHistory($booking),
            'viewing' => $record->id,
            'pdfReady' => $this->pdfDisk($record->pdf_path) !== null,
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

    public function receipt($id)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->receiptOf($booking);
        abort_if(! $record, 404);

        $doc = $this->documents->receipt($record);
        $invoice = $record->invoice_id ? $this->bookings->invoiceOf($booking, (int) $record->invoice_id) : null;

        return view('superadmin.documents.receipt', [
            'booking' => $booking,
            'doc' => $doc,
            'pdfReady' => $this->pdfDisk($record->pdf_path) !== null,
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
