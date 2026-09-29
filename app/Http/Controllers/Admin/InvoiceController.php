<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    /**
     * Statuses in which an invoice can exist at all — payment has already
     * been submitted (waiting_verification, see TLTaskController::complete())
     * or fully confirmed (completed, see JobsController::confirmPayment()).
     * This is the exception path for correcting a mistake discovered after
     * invoice issuance; it is deliberately NOT reachable before that point —
     * pre-invoice corrections go through ActiveBookingsController::updatePricing().
     */
    private const CORRECTABLE_BOOKING_STATUSES = ['waiting_verification', 'completed'];

    public function __construct(private BookingService $bookingService)
    {
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'voided' || ! $invoice->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'This invoice has already been voided.',
            ], 422);
        }

        $booking = $invoice->booking;
        if (! $booking) {
            return response()->json([
                'success' => false,
                'message' => 'This invoice has no associated booking.',
            ], 422);
        }

        if ($this->isGroupedBooking($booking)) {
            return $this->voidGroup($request, $invoice, $booking);
        }

        if (! in_array($booking->status, self::CORRECTABLE_BOOKING_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This booking is not in a state where its invoice can be voided and replaced.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'additional_fee' => 'nullable|numeric|min:0',
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_verified' => 'nullable|boolean',
        ]);

        $reason = $validated['reason'];
        $additionalFee = (float) ($validated['additional_fee'] ?? $booking->additional_fee ?? 0);
        $discountPercentage = (float) ($validated['discount_percentage'] ?? $booking->discount_percentage ?? 0);

        $limitError = $this->bookingService->checkDispatcherDiscountLimit($discountPercentage, $reason)
            ?? $this->bookingService->checkDispatcherAdditionalChargeLimit($additionalFee, $reason);

        if ($limitError !== null) {
            return response()->json(['success' => false, 'message' => $limitError], 422);
        }

        $outcome = DB::transaction(function () use ($invoice, $booking, $reason, $additionalFee, $discountPercentage, $validated) {
            $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (! $lockedInvoice || $lockedInvoice->status === 'voided' || ! $lockedInvoice->is_current) {
                return ['error' => 'This invoice has already been voided.'];
            }

            if (! $lockedBooking || ! in_array($lockedBooking->status, self::CORRECTABLE_BOOKING_STATUSES, true)) {
                return ['error' => 'This booking is not in a state where its invoice can be voided and replaced.'];
            }

            $gross = (float) $lockedBooking->computed_total;
            $discountAmount = round($gross * ($discountPercentage / 100), 2);
            $subtotal = max(round($gross - $discountAmount, 2), 0);
            $totals = $this->bookingService->applyVatAndAdjustment($subtotal, $additionalFee);
            $newTotal = $totals['final_total'];

            $amountChanged = abs($newTotal - (float) $lockedInvoice->total) > 0.005;

            if ($lockedBooking->payment_method === 'cash') {
                $cashReceived = $lockedBooking->cash_received !== null ? (float) $lockedBooking->cash_received : null;
                if ($cashReceived !== null && $cashReceived < $newTotal - 0.005) {
                    return ['error' => sprintf(
                        'Cash received (₱%s) does not cover the corrected total of ₱%s. Collect the balance or adjust the correction before voiding this invoice.',
                        number_format($cashReceived, 2),
                        number_format($newTotal, 2)
                    )];
                }
            } elseif (in_array($lockedBooking->payment_method, ['gcash', 'bank_transfer'], true) && $amountChanged) {
                if (empty($validated['payment_verified'])) {
                    return ['error' => 'This booking was paid via ' . $lockedBooking->payment_method . ' and the corrected total differs from the original. Confirm you have manually verified the corrected total against the payment proof before voiding this invoice.'];
                }
            }

            $lockedBooking->update([
                'additional_fee' => $additionalFee,
                'discount_percentage' => $discountPercentage,
                'vat_exclusive_total' => $totals['subtotal'],
                'vat_amount' => $totals['vat_amount'],
                'final_total' => $newTotal,
            ]);

            $corrections = [
                'subtotal' => $totals['subtotal'],
                'additional_fee' => $additionalFee,
                'discount' => $discountAmount,
                'total' => $newTotal,
            ];

            $oldNumber = $lockedInvoice->invoice_number;
            $oldTotal = (float) $lockedInvoice->total;
            $newInvoice = $lockedInvoice->voidAndReplace($reason, $corrections, auth()->id());

            AuditLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'invoice_voided_replaced',
                'entity_type' => 'Booking',
                'entity_id'   => $lockedBooking->id,
                'reference'   => $lockedBooking->job_code,
                'description' => "Invoice {$oldNumber} voided — replaced by {$newInvoice->invoice_number}. Total: ₱" . number_format($oldTotal, 2) . ' → ₱' . number_format($newTotal, 2) . ". Reason: {$reason}"
                    . (! empty($validated['payment_verified']) ? ' (dispatcher confirmed payment proof covers corrected total)' : ''),
            ]);

            return ['invoice' => $newInvoice];
        });

        if (isset($outcome['error'])) {
            return response()->json(['success' => false, 'message' => $outcome['error']], 422);
        }

        $newInvoice = $outcome['invoice'];
        $oldNumber = $invoice->invoice_number;
        $newInvoice->load('booking.customer');

        try {
            app(DocumentGenerationService::class)->generateInvoice($newInvoice);

            if (filled($newInvoice->booking->customer?->email)) {
                Mail::to($newInvoice->booking->customer->email)->send(new InvoiceMail($newInvoice->fresh()));
                $newInvoice->update(['email_sent' => true]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to generate/send replacement invoice', [
                'invoice_id' => $newInvoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Invoice {$oldNumber} voided. New invoice {$newInvoice->invoice_number} issued.",
            'invoice' => $newInvoice->fresh(),
        ]);
    }

    /**
     * Grouped/consolidated counterpart of the solo void() above. The
     * consolidated total is never authoritative on any single Booking row —
     * it is always recomputed fresh from the active members + the accepted
     * Quotation's group-level adjustment (same formula TLTaskController::
     * completeGroup() uses), so a correction here can only ever touch ONE
     * member Booking (the vehicle actually being corrected) and must never
     * write to the Quotation, which stays immutable.
     */
    private function voidGroup(Request $request, Invoice $invoice, Booking $anchorBooking): JsonResponse
    {
        if (! in_array($anchorBooking->status, self::CORRECTABLE_BOOKING_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This booking is not in a state where its invoice can be voided and replaced.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'additional_fee' => 'nullable|numeric|min:0',
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_verified' => 'nullable|boolean',
            'member_booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $reason = $validated['reason'];
        $memberBookingId = $validated['member_booking_id'] ?? null;

        // Only a per-vehicle correction needs the Owner discount/charge-limit
        // check — a reason-only replacement changes no amount at all.
        if ($memberBookingId !== null) {
            $additionalFee = (float) ($validated['additional_fee'] ?? 0);
            $discountPercentage = (float) ($validated['discount_percentage'] ?? 0);

            $limitError = $this->bookingService->checkDispatcherDiscountLimit($discountPercentage, $reason)
                ?? $this->bookingService->checkDispatcherAdditionalChargeLimit($additionalFee, $reason);

            if ($limitError !== null) {
                return response()->json(['success' => false, 'message' => $limitError], 422);
            }
        }

        try {
            $outcome = DB::transaction(function () use ($invoice, $anchorBooking, $reason, $memberBookingId, $validated) {
                $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

                if (! $lockedInvoice || $lockedInvoice->status === 'voided' || ! $lockedInvoice->is_current) {
                    throw new \RuntimeException('This invoice has already been voided.');
                }

                // Same "same trip" grouping query TLTaskController::completeGroup()
                // uses — locks every member together so a concurrent correction
                // on a sibling vehicle can't race this recompute.
                $groupBookings = Booking::where('group_code', $anchorBooking->group_code)
                    ->where('pickup_address', $anchorBooking->pickup_address)
                    ->where('dropoff_address', $anchorBooking->dropoff_address)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $lockedAnchor = $groupBookings->firstWhere('id', $anchorBooking->id);
                if (! $lockedAnchor || ! in_array($lockedAnchor->status, self::CORRECTABLE_BOOKING_STATUSES, true)) {
                    throw new \RuntimeException('This booking is not in a state where its invoice can be voided and replaced.');
                }

                $activeMembers = $groupBookings->reject(
                    fn (Booking $member) => in_array($member->status, ['cancelled', 'rejected'], true)
                );

                $targetMember = null;
                if ($memberBookingId !== null) {
                    $targetMember = $activeMembers->firstWhere('id', $memberBookingId);
                    if (! $targetMember) {
                        throw new \RuntimeException('The selected vehicle is not part of this active group booking.');
                    }
                }

                $quotation = $lockedAnchor->quotation_id ? \App\Models\Quotation::find($lockedAnchor->quotation_id) : null;
                if (! $quotation) {
                    throw new \RuntimeException('No quotation found for this group.');
                }
                $quotationAdjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);

                if ($targetMember) {
                    $additionalFee = (float) ($validated['additional_fee'] ?? 0);
                    $discountPercentage = (float) ($validated['discount_percentage'] ?? 0);

                    $gross = (float) $targetMember->computed_total;
                    $discountAmount = round($gross * ($discountPercentage / 100), 2);
                    $subtotal = max(round($gross - $discountAmount, 2), 0);
                    $totals = $this->bookingService->applyVatAndAdjustment($subtotal, $additionalFee);

                    // Written now, before the payment guard below — if that guard
                    // throws, the whole transaction (including this write) rolls
                    // back together, so no sibling and no half-applied vehicle
                    // correction is ever left behind.
                    $targetMember->update([
                        'additional_fee' => $additionalFee,
                        'discount_percentage' => $discountPercentage,
                        'vat_exclusive_total' => $totals['subtotal'],
                        'vat_amount' => $totals['vat_amount'],
                        'final_total' => $totals['final_total'],
                    ]);

                    $activeMembers = $activeMembers->map(
                        fn (Booking $member) => $member->id === $targetMember->id ? $targetMember : $member
                    );
                }

                $newSubtotal = round($activeMembers->sum(fn (Booking $member) => (float) ($member->vat_exclusive_total ?? 0)), 2);
                $newTotal = max(round($activeMembers->sum(fn (Booking $member) => (float) $member->final_total) + $quotationAdjustment, 2), 0);
                $amountChanged = abs($newTotal - (float) $lockedInvoice->total) > 0.005;

                $paymentMethod = $lockedAnchor->payment_method;
                if ($paymentMethod === 'cash') {
                    $cashReceived = $lockedAnchor->cash_received !== null ? (float) $lockedAnchor->cash_received : null;
                    if ($cashReceived !== null && $cashReceived < $newTotal - 0.005) {
                        throw new \RuntimeException(sprintf(
                            'Cash received (₱%s) does not cover the corrected consolidated total of ₱%s. Collect the balance or adjust the correction before voiding this invoice.',
                            number_format($cashReceived, 2),
                            number_format($newTotal, 2)
                        ));
                    }
                } elseif (in_array($paymentMethod, ['gcash', 'bank_transfer'], true) && $amountChanged) {
                    if (empty($validated['payment_verified'])) {
                        throw new \RuntimeException('This group was paid via ' . $paymentMethod . ' and the corrected total differs from the original. Confirm you have manually verified the corrected total against the payment proof before voiding this invoice.');
                    }
                }

                $corrections = [
                    'subtotal' => $newSubtotal,
                    'additional_fee' => (float) ($quotation->additional_fee ?? 0),
                    'discount' => (float) ($quotation->discount ?? 0),
                    'total' => $newTotal,
                ];

                $oldNumber = $lockedInvoice->invoice_number;
                $oldTotal = (float) $lockedInvoice->total;
                $newInvoice = $lockedInvoice->voidAndReplace($reason, $corrections, auth()->id());

                AuditLog::create([
                    'user_id'     => auth()->id(),
                    'action'      => 'invoice_voided_replaced',
                    'entity_type' => 'Booking',
                    'entity_id'   => $lockedAnchor->id,
                    'reference'   => $lockedAnchor->job_code,
                    'description' => "Consolidated invoice {$oldNumber} voided — replaced by {$newInvoice->invoice_number}. Total: ₱" . number_format($oldTotal, 2) . ' → ₱' . number_format($newTotal, 2)
                        . ($targetMember ? " (correction applied to vehicle {$targetMember->job_code})" : ' (reason-only replacement, no amount change)')
                        . ". Reason: {$reason}"
                        . (! empty($validated['payment_verified']) ? ' (dispatcher confirmed payment proof covers corrected total)' : ''),
                ]);

                return ['invoice' => $newInvoice];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $newInvoice = $outcome['invoice'];
        $oldNumber = $invoice->invoice_number;
        $newInvoice->load('booking.customer');

        try {
            app(DocumentGenerationService::class)->generateInvoice($newInvoice);

            if (filled($newInvoice->booking->customer?->email)) {
                Mail::to($newInvoice->booking->customer->email)->send(new InvoiceMail($newInvoice->fresh()));
                $newInvoice->update(['email_sent' => true]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to generate/send replacement invoice', [
                'invoice_id' => $newInvoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Invoice {$oldNumber} voided. New invoice {$newInvoice->invoice_number} issued.",
            'invoice' => $newInvoice->fresh(),
        ]);
    }

    private function isGroupedBooking(Booking $booking): bool
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return false;
        }

        $quotation = \App\Models\Quotation::find($booking->quotation_id);
        if (! $quotation) {
            return false;
        }

        return collect($quotation->extra_vehicles ?? [])->contains(fn ($ev) => ! empty($ev['booking_id']));
    }
}
