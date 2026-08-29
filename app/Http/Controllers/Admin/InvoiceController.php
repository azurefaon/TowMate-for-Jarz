<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Services\DocumentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceController extends Controller
{
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'voided' || ! $invoice->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'This invoice has already been voided.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'total' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'additional_fee' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $subtotal = $validated['subtotal'] ?? (float) $invoice->subtotal;
        $additionalFee = $validated['additional_fee'] ?? (float) $invoice->additional_fee;
        $discount = $validated['discount'] ?? (float) $invoice->discount;
        $total = $validated['total'] ?? round($subtotal + $additionalFee - $discount, 2);

        $corrections = [
            'subtotal' => $subtotal,
            'additional_fee' => $additionalFee,
            'discount' => $discount,
            'total' => $total,
        ];

        $oldNumber = $invoice->invoice_number;
        $newInvoice = $invoice->voidAndReplace($validated['reason'], $corrections, auth()->id());
        $newInvoice->load('booking.customer');

        AuditLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'invoice_voided',
            'entity_type' => 'Booking',
            'entity_id'   => $newInvoice->booking_id,
            'reference'   => $newInvoice->booking?->job_code ?? $newInvoice->invoice_number,
            'description' => "Invoice {$oldNumber} voided — replaced by {$newInvoice->invoice_number}. Reason: {$validated['reason']}",
        ]);

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
}
