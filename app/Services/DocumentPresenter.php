<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use Carbon\CarbonInterface;

/**
 * Read-only view models for the Invoice and Payment Receipt documents. The same
 * arrays feed the A4 PDF templates and the Owner viewers' fallback preview.
 *
 * Nothing here invents or derives a financial figure. Every amount shown is a
 * stored value; an itemised breakdown (VAT, adjustment, per-vehicle lines) is
 * shown only when the stored values reconcile with the stored invoice total, and
 * otherwise the document shows the stored total alone.
 */
class DocumentPresenter
{
    private const NOT_CHARGED_STATUSES = ['cancelled', 'rejected'];

    private const PAYMENT_METHODS = ['gcash' => 'GCash', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash'];

    private const BADGES = [
        'current' => ['Current', '#166534'],
        'replaced' => ['Replaced', '#6D28D9'],
        'voided' => ['Voided', '#B91C1C'],
        'issued' => ['Issued', '#525252'],
    ];

    public function __construct(private DocumentGenerationService $documents)
    {
    }

    public function peso(float $amount): string
    {
        return ($amount < 0 ? '−' : '') . '₱' . number_format(abs($amount), 2);
    }

    private function date(?CarbonInterface $at): ?string
    {
        return $at?->format('j M Y');
    }

    private function stamp(?CarbonInterface $at): ?string
    {
        return $at?->format('j M Y, H:i');
    }

    /** Company block from the Owner's business settings, plus the official logo. */
    public function company(): array
    {
        $settings = $this->documents->documentSettings();

        // Same signatory rule the previous invoice and receipt templates used.
        $signatory = trim((string) preg_replace(
            '/\bfranchisee\b/i',
            '',
            (string) (($settings['gcash_name'] ?? null) ?: ($settings['bank_account_name'] ?? ''))
        ));

        return [
            'name' => $settings['company_name'],
            'address' => $settings['company_address'],
            'phone' => $settings['company_phone'],
            'email' => $settings['company_email'],
            'logo' => $settings['document_logo_url'],
            'accreditation_logo' => $settings['secondary_logo_url'],
            'bank_name' => $settings['bank_name'],
            'bank_account_name' => $settings['bank_account_name'],
            'bank_account_number' => $settings['bank_account_number'],
            'gcash_name' => $settings['gcash_name'],
            'gcash_number' => $settings['gcash_number'],
            'payment_terms' => $settings['payment_terms'],
            // Only a signature the Owner uploaded; otherwise a blank signature line.
            'signature_image' => $settings['signature_url'],
            'signatory' => $signatory !== '' ? $signatory : null,
            'signatory_role' => 'Franchisee',
        ];
    }

    /**
     * Only what the records can prove. `email_sent` is set after the mail
     * transport accepted the message: it proves the sending operation completed,
     * not delivery or that the customer received it. There is no timestamp, no
     * failure record and no queue state, so Pending, Failed and Delivered are
     * never shown.
     */
    public function emailStatus(bool $sent, ?string $recipient): array
    {
        if ($sent && filled($recipient)) {
            return [
                'key' => 'sent',
                'label' => 'Email Sent',
                'bg' => '#0F766E',
                'detail' => 'The email was sent successfully from the system. Delivery to, and receipt by, the customer is not confirmed.',
                'recipient' => $recipient,
            ];
        }

        return ['key' => 'unavailable', 'label' => 'Email Not Available', 'bg' => '#525252', 'detail' => 'No email send record exists for this document.', 'recipient' => null];
    }

    public function invoice(Invoice $invoice, ?array $groupVehicles = null): array
    {
        $invoice->loadMissing(['booking.customer', 'booking.truckType', 'booking.vehicleType', 'previousInvoice']);
        $booking = $invoice->booking;
        $customer = $booking->customer;
        $successor = Invoice::where('previous_invoice_id', $invoice->id)->orderBy('id')->first();

        $state = match (true) {
            $invoice->status === 'voided' && $successor !== null => 'replaced',
            $invoice->status === 'voided' => 'voided',
            $invoice->is_current => 'current',
            default => 'issued',
        };

        $note = match ($state) {
            'replaced' => 'Replaced by ' . $successor->invoice_number . ', issued ' . $this->date($successor->created_at) . '.',
            'voided' => 'Voided on ' . ($this->date($invoice->voided_at) ?? 'an unrecorded date') . '.',
            default => null,
        };

        if ($note && filled($invoice->void_reason)) {
            $note .= ' Reason: ' . trim((string) $invoice->void_reason);
        }

        $total = (float) $invoice->total;
        $fee = (float) $invoice->additional_fee;
        $discount = (float) $invoice->discount;
        $isGroup = ! empty($groupVehicles) || Invoice::isNormalizedGroupTransaction($booking);
        $lines = [];
        $rows = [];
        $reliable = false;

        if ($isGroup) {
            $vehicles = $groupVehicles ?? $this->groupVehicles($booking);
            $base = round(array_sum(array_column($vehicles, 'final_total')), 2);

            // Group invoice total = sum of vehicle totals + quotation fee - quotation discount.
            $reliable = ! empty($vehicles) && abs($base + $fee - $discount - $total) < 0.01;

            if ($reliable) {
                foreach (array_values($vehicles) as $i => $vehicle) {
                    $amount = (float) ($vehicle['final_total'] ?? 0);
                    $lines[] = [
                        'title' => 'Vehicle ' . ($i + 1) . ' — ' . ($vehicle['truck_type_name'] ?? 'Towing service'),
                        'detail' => $i === 0 ? $this->route($booking) : null,
                        'qty' => '1',
                        'unit' => '—',
                        'amount' => $this->peso($amount),
                    ];
                }

                $rows[] = ['label' => 'Subtotal', 'value' => $this->peso($base)];

                if ($fee >= 0.01) {
                    $rows[] = ['label' => 'Additional fee', 'value' => $this->peso($fee)];
                }

                if ($discount >= 0.01) {
                    $rows[] = ['label' => 'Discount', 'value' => $this->peso(-$discount)];
                }
            }
        } else {
            $subtotal = (float) $invoice->subtotal;
            $vat = $booking->vat_amount;
            $vatExclusive = $booking->vat_exclusive_total;

            // Subtotal (VAT-exclusive, after discount) + stored VAT + stored adjustment = total.
            $reliable = $vat !== null && $vatExclusive !== null
                && abs((float) $vatExclusive - $subtotal) < 0.01
                && abs($subtotal + (float) $vat + $fee - $total) < 0.01;

            if ($reliable) {
                $gross = round($subtotal + $discount, 2);
                $rate = (float) $booking->base_rate;
                $perKm = (float) $booking->per_km_rate;
                $km = (float) $booking->distance_km;
                $vehicle = $booking->vehicleType->name ?? ($booking->truckType->name ?? null);
                $detail = ($vehicle ? 'Vehicle: ' . $vehicle . "\n" : '') . $this->route($booking);

                if ($rate > 0 && $perKm > 0 && $km > 0 && abs($rate + $km * $perKm - $gross) < 0.01) {
                    $lines[] = ['title' => 'Towing service', 'detail' => $detail, 'qty' => '1', 'unit' => $this->peso($rate), 'amount' => $this->peso($rate)];
                    $lines[] = [
                        'title' => 'Distance charge',
                        'detail' => 'Per kilometre, as recorded on the booking',
                        'qty' => rtrim(rtrim(number_format($km, 1), '0'), '.') . ' km',
                        'unit' => $this->peso($perKm),
                        'amount' => $this->peso(round($km * $perKm, 2)),
                    ];
                } else {
                    $lines[] = ['title' => 'Towing service', 'detail' => $detail, 'qty' => '1', 'unit' => $this->peso($gross), 'amount' => $this->peso($gross)];
                }

                $rows[] = ['label' => 'Subtotal', 'value' => $this->peso($gross)];

                if ($discount >= 0.01) {
                    $rows[] = ['label' => 'Discount', 'value' => $this->peso(-$discount)];
                }

                if ((float) $vat >= 0.01) {
                    $rows[] = ['label' => 'VAT', 'value' => $this->peso((float) $vat)];
                }

                if (abs($fee) >= 0.01) {
                    $rows[] = ['label' => $fee < 0 ? 'Adjustment' : 'Additional fee', 'value' => $this->peso($fee)];
                }
            }
        }

        $breakdownNote = null;

        if (! $reliable) {
            // Stored total only: no subtotal, VAT, adjustment or per-line figures are presented.
            $vehicle = $booking->vehicleType->name ?? ($booking->truckType->name ?? null);
            $lines = [[
                'title' => 'Towing service',
                'detail' => ($vehicle ? 'Vehicle: ' . $vehicle . "\n" : '') . $this->route($booking),
                'qty' => '1',
                'unit' => '—',
                'amount' => $this->peso($total),
            ]];
            $rows = [];
            $breakdownNote = 'An itemised VAT and adjustment breakdown is not recorded for this invoice. The total shown is the stored invoice total.';
        }

        $transaction = Invoice::whereIn('booking_id', $this->transactionBookingIds($booking))->orderBy('id')->pluck('id')->values();
        $position = $transaction->search($invoice->id);

        [$statusLabel, $statusBg] = self::BADGES[$state];
        $company = $this->company();
        $showPayment = $state === 'current'
            && (filled($company['bank_account_number']) || filled($company['gcash_number']));

        return [
            'company' => $company,
            'state' => $state,
            'status_label' => $statusLabel,
            'status_bg' => $statusBg,
            'note' => $note,
            'number' => $invoice->invoice_number,
            'date' => $this->date($invoice->created_at),
            'issued_at' => $this->stamp($invoice->created_at),
            'version' => $position === false ? 1 : $position + 1,
            'versions' => max($transaction->count(), 1),
            'replaces' => $invoice->previousInvoice?->invoice_number,
            'replaced_by' => $successor?->invoice_number,
            'void_reason' => $invoice->status === 'voided' ? $invoice->void_reason : null,
            'booking_ref' => $booking->job_code,
            'customer' => ['name' => $customer->full_name ?? 'Customer', 'phone' => $customer->phone ?? null, 'email' => $customer->email ?? null],
            'lines' => $lines,
            'rows' => $rows,
            'reliable' => $reliable,
            'breakdown_note' => $breakdownNote,
            'total' => $this->peso($total),
            'total_bg' => $state === 'current' || $state === 'issued' ? '#171717' : '#525252',
            'total_fg' => $state === 'current' ? '#FACC15' : '#FFFFFF',
            'show_payment' => $showPayment,
            'email' => $this->emailStatus((bool) $invoice->email_sent, $customer->email ?? null),
        ];
    }

    public function receipt(Receipt $receipt): array
    {
        $receipt->loadMissing(['booking.customer', 'invoice']);
        $booking = $receipt->booking;
        $customer = $booking->customer;
        $invoice = $receipt->invoice ?? $booking->currentInvoice()->first();

        $verified = $booking->status === 'completed' && $booking->completed_at !== null;
        $amount = (float) ($invoice?->total ?? $booking->final_total);
        $method = self::PAYMENT_METHODS[$booking->payment_method] ?? null;
        $verifier = $verified
            ? AuditLog::with('user')->where('action', 'payment_confirmed')->where('entity_type', 'Booking')
                ->whereIn('entity_id', $this->transactionBookingIds($booking))->latest('id')->first()?->user
            : null;
        $verifierName = $verifier?->full_name ?: $verifier?->name;

        $cash = $booking->payment_method === 'cash' && $booking->cash_received !== null ? (float) $booking->cash_received : null;
        $rows = [
            ['label' => 'Payment method', 'value' => $method ?? 'Not available', 'mono' => false, 'muted' => $method === null],
            // No payment reference is stored anywhere in the system.
            ['label' => 'Payment reference', 'value' => 'Not available', 'mono' => false, 'muted' => true],
        ];

        if ($cash !== null) {
            $rows[] = ['label' => 'Cash received', 'value' => $this->peso($cash), 'mono' => false, 'muted' => false];
        }

        $rows[] = [
            'label' => 'Verification',
            'value' => $verified
                ? 'Verified' . ($verifierName ? ' by ' . $verifierName : '') . ', ' . $this->stamp($booking->completed_at)
                : 'Not verified',
            'mono' => false,
            'muted' => ! $verified,
        ];
        $vehicle = $booking->vehicleType->name ?? ($booking->truckType->name ?? null);
        $verificationIndex = count($rows) - 1;
        $rows[] = ['label' => 'Booking reference', 'value' => $booking->job_code, 'mono' => true, 'muted' => false];
        $rows[] = ['label' => 'Invoice reference', 'value' => $invoice?->invoice_number ?? 'Not available', 'mono' => $invoice !== null, 'muted' => $invoice === null];

        // Service details the previous receipt carried (vehicle type, pick-up, drop-off).
        if ($vehicle) {
            $rows[] = ['label' => 'Vehicle', 'value' => $vehicle, 'mono' => false, 'muted' => false];
        }
        $rows[] = ['label' => 'Pick-up', 'value' => $booking->pickup_address ?: 'Not available', 'mono' => false, 'muted' => blank($booking->pickup_address)];
        $rows[] = ['label' => 'Drop-off', 'value' => $booking->dropoff_address ?: 'Not available', 'mono' => false, 'muted' => blank($booking->dropoff_address)];

        return [
            'company' => $this->company(),
            'number' => $receipt->receipt_number,
            'date' => $this->date($receipt->created_at),
            'issued_at' => $this->stamp($receipt->created_at),
            'customer' => ['name' => $customer->full_name ?? 'Customer', 'phone' => $customer->phone ?? null, 'email' => $customer->email ?? null],
            'verified' => $verified,
            'amount_label' => $verified ? 'AMOUNT RECEIVED' : 'PAYMENT NOT VERIFIED',
            'amount' => $this->peso($amount),
            'method' => $method,
            'verification' => $rows[$verificationIndex]['value'],
            'rows' => $rows,
            'booking_ref' => $booking->job_code,
            'invoice_number' => $invoice?->invoice_number,
            'email' => $this->emailStatus((bool) $receipt->email_sent, $customer->email ?? null),
        ];
    }

    /** Same transaction definition Void & Replace and the receipt lock use. */
    public function transactionBookingIds(Booking $booking): array
    {
        return Invoice::isNormalizedGroupTransaction($booking)
            ? Invoice::sameTripMembers($booking)->pluck('id')->all()
            : [$booking->id];
    }

    private function groupVehicles(Booking $booking): array
    {
        return Invoice::sameTripMembers($booking)
            ->with(['truckType', 'vehicleType'])
            ->orderBy('id')
            ->get()
            ->reject(fn (Booking $m) => in_array($m->status, self::NOT_CHARGED_STATUSES, true))
            ->map(fn (Booking $m) => [
                'truck_type_name' => $m->vehicleType->name ?? ($m->truckType->name ?? 'Towing service'),
                'final_total' => (float) $m->final_total,
            ])
            ->values()
            ->all();
    }

    private function route(Booking $booking): string
    {
        return 'Pick-up: ' . ($booking->pickup_address ?: '—') . "\nDrop-off: " . ($booking->dropoff_address ?: '—');
    }
}
