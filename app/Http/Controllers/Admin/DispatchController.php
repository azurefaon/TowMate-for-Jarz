<?php

namespace App\Http\Controllers\Admin;

use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Controller;
use App\Mail\BookingAcceptedMail;
use App\Mail\BookingRejectedMail;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DispatchController extends Controller
{
    protected BookingService $bookingService;
    protected DocumentGenerationService $documentGenerationService;

    protected array $reviewableStatuses = ['requested', 'reviewed', 'quoted', 'quotation_sent'];

    public function __construct(BookingService $bookingService, DocumentGenerationService $documentGenerationService)
    {
        $this->bookingService = $bookingService;
        $this->documentGenerationService = $documentGenerationService;
    }

    public function updateStatus(Request $request, $id)
    {
        $booking = Booking::query()
            ->where('booking_code', $id)
            ->orWhereKey($id)
            ->firstOrFail();

        $request->merge([
            'rejection_reason' => $request->input('rejection_reason', $request->input('reason')),
        ]);

        return $this->assignBooking($request, $booking);
    }

    public function index()
    {
        $incomingRequests = Booking::with(['customer', 'truckType'])
            ->whereIn('status', ['requested', 'reviewed'])
            ->oldest('updated_at')
            ->get();

        return view('admin-dashboard.pages.dispatch', compact('incomingRequests'));
    }

    public function pendingBookingsCount()
    {
        return response()->json([
            'count' => Booking::whereIn('status', ['requested', 'reviewed'])->count(),
        ]);
    }

    public function assignBooking(Request $request, Booking $booking)
    {
        $request->merge([
            'rejection_reason' => $request->input('rejection_reason', $request->input('reason')),
        ]);

        $validated = $request->validate([
            'action' => 'required|in:accept,reject',
            'price' => [
                $request->input('action') === 'accept' ? 'required' : 'nullable',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if ($this->bookingService->parsePrice((string) $value) <= 0) {
                        $fail('Enter a valid quotation amount.');
                    }
                },
            ],
            'dispatcher_note' => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $booking->loadMissing(['customer', 'truckType']);

        if (! in_array($booking->status, $this->reviewableStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This booking can no longer be revised from the dispatcher queue.',
            ], 422);
        }

        if ($validated['action'] === 'accept') {
            $quotedPrice = $this->bookingService->parsePrice($validated['price'] ?? null);

            if ($quotedPrice <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Enter a valid quotation amount before sending the quote.',
                ], 422);
            }

            $quotationNumber = $booking->quotation_number ?: $this->bookingService->generateQuotationNumber($booking);
            $dispatcherNote = filled($validated['dispatcher_note'] ?? null)
                ? trim(strip_tags($validated['dispatcher_note']))
                : null;

            $booking->update([
                'status' => 'quotation_sent',
                'final_total' => $quotedPrice,
                'quotation_number' => $quotationNumber,
                'quotation_generated' => true,
                'reviewed_at' => $booking->reviewed_at ?? now(),
                'quoted_at' => now(),
                'quotation_sent_at' => now(),
                'dispatcher_note' => $dispatcherNote,
                'rejection_reason' => null,
                'final_quote_path' => null,
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType']);

            $initialQuotePath = $this->documentGenerationService->generateQuotation($booking);
            $booking->update([
                'initial_quote_path' => $initialQuotePath,
            ]);

            $booking->refresh()->loadMissing(['customer', 'truckType']);

            if (filled($booking->customer?->email)) {
                Mail::to($booking->customer->email)->send(new BookingAcceptedMail($booking));
            }

            event(new BookingStatusUpdated($booking));

            return response()->json([
                'success' => true,
                'message' => 'Initial quotation sent to the customer. Dispatch will wait for customer approval before assigning the job.',
                'quotation_number' => $quotationNumber,
                'quoted_price' => number_format($quotedPrice, 2),
                'status' => $booking->status,
            ]);
        }

        $rejectionReason = trim((string) ($validated['rejection_reason'] ?? ''));

        if ($rejectionReason === '') {
            $rejectionReason = 'Your request could not be accommodated at this time. Please contact dispatch for assistance.';
        }

        $booking->update([
            'status' => 'cancelled',
            'rejection_reason' => $rejectionReason,
        ]);

        $booking->refresh()->loadMissing(['customer', 'truckType']);

        event(new \App\Events\BookingCancelled($booking));
        event(new BookingStatusUpdated($booking));

        if (filled($booking->customer?->email)) {
            Mail::to($booking->customer->email)->send(new BookingRejectedMail($booking));
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected and the customer was notified by email.',
        ]);
    }
}
