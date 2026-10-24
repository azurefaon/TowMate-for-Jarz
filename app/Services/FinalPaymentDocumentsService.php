<?php

namespace App\Services;

use App\Mail\FinalPaymentDocumentsMail;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends the one email that follows an authoritative payment confirmation: the invoice
 * and the receipt as two separate PDFs. Safe to call again for the same booking:
 * `email_sent` is only set after the mail transport accepted the message, so a failed
 * attempt can be retried and a sent one is never repeated.
 */
class FinalPaymentDocumentsService
{
    public const SENT = 'sent';
    public const ALREADY_SENT = 'already_sent';
    public const NOT_COMPLETED = 'not_completed';
    public const NO_RECIPIENT = 'no_recipient';
    public const BUSY = 'busy';

    public function __construct(
        private DocumentGenerationService $documents,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * Every member of a group shares one financial transaction (see DocumentPresenter::transactionBookingIds),
     * so the lowest member id names the lock and the receipt/delivery check for all of them.
     */
    private function transactionKey(?Booking $booking, int $bookingId): int
    {
        return $booking ? (int) min($this->presenter->transactionBookingIds($booking)) : $bookingId;
    }

    /** The transaction's single receipt, whichever member it was issued on. */
    private function transactionReceipt(Booking $booking): ?Receipt
    {
        return Receipt::whereIn('booking_id', $this->presenter->transactionBookingIds($booking))
            ->orderBy('id')
            ->first();
    }

    /** True once the final-payment email of this booking's whole transaction was accepted by the mail transport. */
    public function alreadySent(Booking $booking): bool
    {
        return (bool) $this->transactionReceipt($booking)?->email_sent;
    }

    public function send(int $bookingId, array $groupVehicles = [], float $groupAdjustment = 0.0): string
    {
        $lock = Cache::lock('final-payment-documents:' . $this->transactionKey(Booking::find($bookingId), $bookingId), 300);

        if (! $lock->get()) {
            return self::BUSY;
        }

        try {
            return $this->sendLocked($bookingId, $groupVehicles, $groupAdjustment);
        } catch (\Throwable $e) {
            Log::error('Final payment documents email failed', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(int $bookingId, array $groupVehicles, float $groupAdjustment): string
    {
        $booking = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt'])->find($bookingId);

        // Payment confirmation (status `completed`) is the only authority for these documents.
        if (! $booking || $booking->status !== 'completed') {
            return self::NOT_COMPLETED;
        }

        // Re-checked inside the lock: another member of the same transaction may have just finished.
        $existing = $this->transactionReceipt($booking);

        if ($existing?->email_sent) {
            return self::ALREADY_SENT;
        }

        // Reuse the transaction's receipt (and its booking) instead of issuing one for a sibling.
        if ($existing && $existing->booking_id !== $booking->id) {
            $booking = Booking::with(['customer', 'truckType', 'unit', 'assignedTeamLeader', 'receipt'])->findOrFail($existing->booking_id);
        }

        $invoice = $this->transactionInvoice($booking);

        // The invoice PDF is refreshed first, so a later failure can never leave a completed booking
        // with the "Unpaid" PDF generated at submission. It must read Paid before anything is sent.
        if ($this->presenter->invoice($invoice)['pdf_status'] !== 'Paid') {
            throw new RuntimeException('Invoice ' . $invoice->invoice_number . ' is not in a paid state; its documents were not sent.');
        }

        $this->documents->generateInvoice($invoice, $groupVehicles, $groupAdjustment);
        $receipt = $this->documents->generateReceipt($booking, $groupVehicles, $groupAdjustment, $invoice);

        $recipient = $booking->customer?->email;

        if (blank($recipient)) {
            return self::NO_RECIPIENT;
        }

        Mail::to($recipient)->send(new FinalPaymentDocumentsMail($booking, $invoice->fresh(), $receipt->fresh()));

        $receipt->update(['email_sent' => true]);
        $invoice->update(['email_sent' => true]);

        return self::SENT;
    }

    /** The current, non-voided invoice of this booking's own transaction; nothing else may be attached. */
    private function transactionInvoice(Booking $booking): Invoice
    {
        $ids = $this->presenter->transactionBookingIds($booking);

        $invoice = Invoice::whereIn('booking_id', $ids)
            ->where('is_current', true)
            ->where('status', '!=', 'voided')
            ->orderByRaw('booking_id = ? desc', [$booking->id])
            ->orderByDesc('id')
            ->first();

        if (! $invoice) {
            throw new RuntimeException('No current invoice exists for booking ' . $booking->id . '.');
        }

        return $invoice;
    }
}
