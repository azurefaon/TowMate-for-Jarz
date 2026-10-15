<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\DocumentPresenter;
use App\Services\OwnerBookingPresenter;
use App\Services\StoredDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only Owner viewers for a booking's invoices and receipt. Every document
 * must belong to the booking's own billing transaction (same-trip group members
 * share one); anything else is a 404.
 *
 * The viewers render the modern document from stored records. Issued PDFs stay in
 * private storage and the authenticated streaming routes below remain for record
 * retention and audit, but the Owner screens no longer link to or embed them.
 */
class BookingDocumentController extends Controller
{
    public function __construct(
        private OwnerBookingPresenter $bookings,
        private DocumentPresenter $documents,
        private StoredDocuments $files,
    ) {
    }

    public function invoice($id, int $invoice)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->invoiceOf($booking, $invoice);
        abort_if(! $record, 404);

        // Any legacy "?source=…" query on an old bookmark is simply ignored.
        return view('superadmin.documents.invoice', [
            'booking' => $booking,
            'doc' => $this->documents->invoice($record),
            'history' => $this->bookings->invoiceHistory($booking),
            'viewing' => $record->id,
            'receiptUrl' => $this->bookings->receiptOf($booking) ? route('superadmin.bookings.receipt.show', ['id' => $booking->job_code]) : null,
            // PDF actions are offered only for a stored official MODERN invoice PDF; legacy PDFs get none.
            'officialPdfUrl' => $this->files->isOfficialModern($record->pdf_path, 'invoice', $record->invoice_number, (int) $record->id)
                ? route('superadmin.bookings.invoices.pdf', ['id' => $booking->job_code, 'invoice' => $record->id])
                : null,
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

        $invoice = $record->invoice_id ? $this->bookings->invoiceOf($booking, (int) $record->invoice_id) : null;

        return view('superadmin.documents.receipt', [
            'booking' => $booking,
            'doc' => $this->documents->receipt($record),
            'invoiceUrl' => $invoice ? route('superadmin.bookings.invoices.show', ['id' => $booking->job_code, 'invoice' => $invoice->id]) : null,
            'officialPdfUrl' => $this->files->isOfficialModern($record->pdf_path, 'receipt', $record->receipt_number, (int) $record->booking_id)
                ? route('superadmin.bookings.receipt.pdf', ['id' => $booking->job_code])
                : null,
        ]);
    }

    public function receiptPdf(Request $request, $id)
    {
        $booking = $this->bookings->findBooking($id);
        $record = $this->bookings->receiptOf($booking);
        abort_if(! $record, 404);

        return $this->stream($record->pdf_path, ($record->receipt_number ?: 'receipt') . '.pdf', $request->boolean('download'));
    }

    private function stream(?string $path, string $name, bool $download)
    {
        $located = $this->files->locate($path);
        abort_if($located === null, 404);

        return Storage::disk($located[0])->response($located[1], $name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }
}
