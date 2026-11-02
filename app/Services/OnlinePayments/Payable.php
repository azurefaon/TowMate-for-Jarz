<?php

namespace App\Services\OnlinePayments;

/** What a single checkout would pay for: one booking, or every active vehicle of a normalized group. */
final class Payable
{
    /**
     * @param  array<int,int>  $memberIds  every booking of the group (or just the one booking), ascending
     * @param  array<int,int>  $activeIds  members that are not cancelled
     * @param  array<int,string>  $memberTotals  booking id => final_total, as decimal strings
     */
    public function __construct(
        public readonly string $type,        // booking | group
        public readonly int $anchorId,       // the booking id, or the group's anchor booking
        public readonly array $memberIds,
        public readonly array $activeIds,
        public readonly array $memberTotals,
        public readonly int $customerId,
        public readonly string $totalPesos,
        public readonly int $totalCentavos,
    ) {
    }

    public function snapshot(): array
    {
        return [
            'type' => $this->type,
            'anchor_id' => $this->anchorId,
            'member_ids' => $this->memberIds,
            'active_ids' => $this->activeIds,
            'member_totals' => $this->memberTotals,
            'total_pesos' => $this->totalPesos,
        ];
    }
}
