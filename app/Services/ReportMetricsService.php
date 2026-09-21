<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Quotation;
use App\Models\Unit;
use Illuminate\Support\Collection;

class ReportMetricsService
{
    public const VALID_BOOKING_EXCLUDED_STATUSES = ['requested', 'not_responding', 'rejected'];

    private array $groupAllocationCache = [];

    public const METRIC_DICTIONARY = [
        'cancellation_rate' => [
            'display_name' => 'Cancellation Rate',
            'formula' => 'Cancelled ÷ valid bookings × 100',
            'numerator' => 'Count of bookings with status = cancelled',
            'denominator' => 'Count of valid bookings (see included/excluded statuses)',
            'included_statuses' => 'All statuses except requested, not_responding, rejected',
            'excluded_statuses' => 'requested, not_responding, rejected (never reached quoting/assignment)',
            'payment_requirement' => 'None — this metric is about booking volume, not revenue',
            'date_column' => 'created_at',
            'grouping_column' => 'None',
            'rounding' => 'None in calculation; display rounds to 1 decimal',
        ],
        'average_revenue_per_job' => [
            'display_name' => 'Average Revenue / Job',
            'formula' => 'Collected revenue from completed paid jobs ÷ number of completed paid jobs',
            'numerator' => 'Sum of final_total for completed, payment-verified bookings',
            'denominator' => 'Count of completed, payment-verified bookings',
            'included_statuses' => 'completed only',
            'excluded_statuses' => 'All others',
            'payment_requirement' => 'completed_at IS NOT NULL (see PAYMENT_INVARIANT)',
            'date_column' => 'completed_at',
            'grouping_column' => 'None',
            'rounding' => 'None in calculation; display rounds to 2 decimals as currency',
        ],
        'revenue_by_truck_type' => [
            'display_name' => 'Revenue by Truck Type',
            'formula' => 'Collected revenue from completed paid jobs, grouped by booking truck_type_id',
            'numerator' => 'Sum of final_total per truck_type_id',
            'denominator' => 'N/A (grouped total, not a ratio)',
            'included_statuses' => 'completed only',
            'excluded_statuses' => 'All others',
            'payment_requirement' => 'completed_at IS NOT NULL (see PAYMENT_INVARIANT)',
            'date_column' => 'completed_at',
            'grouping_column' => 'bookings.truck_type_id (the booking\'s own snapshot, never the live unit\'s truck type)',
            'rounding' => 'None in calculation; display rounds to 2 decimals as currency',
        ],
        'completed_jobs_by_truck_type' => [
            'display_name' => 'Completed Jobs by Truck Type',
            'formula' => 'Count of completed paid jobs, grouped by booking truck_type_id',
            'numerator' => 'Count of bookings per truck_type_id',
            'denominator' => 'N/A (grouped count, not a ratio)',
            'included_statuses' => 'completed only',
            'excluded_statuses' => 'All others',
            'payment_requirement' => 'completed_at IS NOT NULL (see PAYMENT_INVARIANT)',
            'date_column' => 'completed_at',
            'grouping_column' => 'bookings.truck_type_id',
            'rounding' => 'None',
        ],
        'current_fleet_utilization' => [
            'display_name' => 'Current Fleet Utilization',
            'formula' => 'Current units on job ÷ current active fleet × 100',
            'numerator' => 'Non-archived units with status = on_job, right now',
            'denominator' => 'Non-archived units, right now',
            'included_statuses' => 'N/A (unit status, not booking status)',
            'excluded_statuses' => 'Archived units, from both numerator and denominator',
            'payment_requirement' => 'N/A',
            'date_column' => 'None — live snapshot, not affected by any selected reporting period',
            'grouping_column' => 'None',
            'rounding' => 'None in calculation; display rounds to 1 decimal',
        ],
        'unit_performance' => [
            'display_name' => 'Unit Revenue / Performance',
            'formula' => 'Collected revenue from completed paid jobs assigned to that unit, plus completed job count and revenue ÷ completed jobs',
            'numerator' => 'Sum of final_total per assigned_unit_id',
            'denominator' => 'Count of completed jobs per unit (for the average sub-metric)',
            'included_statuses' => 'completed only',
            'excluded_statuses' => 'All others',
            'payment_requirement' => 'completed_at IS NOT NULL (see PAYMENT_INVARIANT)',
            'date_column' => 'completed_at',
            'grouping_column' => 'bookings.assigned_unit_id',
            'rounding' => 'None in calculation; display rounds to 2 decimals as currency',
        ],
    ];

    public const PAYMENT_INVARIANT = 'Booking.status is only ever written to "completed" together with completed_at, inside JobsController::confirmPayment() — which itself requires the booking to already be in waiting_verification/payment_pending/payment_submitted before it will run. However, ActiveBookingsController::updateStatus() allows a manual status override directly to "completed" from any status, without setting completed_at and without any payment gate. So status=completed alone does NOT provably imply payment was verified. The reporting layer therefore requires completed_at IS NOT NULL in addition to status=completed on every revenue metric — completed_at is only ever populated by the real payment-confirmation path, so this closes the gap without touching the override endpoint itself.';

    protected function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['truck_type_id'] ?? null, fn ($q, $id) => $q->where('truck_type_id', $id))
            ->when(($filters['min_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '>=', $filters['min_amount']))
            ->when(($filters['max_amount'] ?? null) !== null, fn ($q) => $q->where('final_total', '<=', $filters['max_amount']));
    }

    protected function completedPaidJobsQuery($start, $end, array $filters = [])
    {
        return $this->applyFilters(
            Booking::where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$start, $end]),
            $filters
        );
    }

    private function groupAdjustmentAllocation(Booking $booking): ?array
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return null;
        }

        if (array_key_exists($booking->quotation_id, $this->groupAllocationCache)) {
            return $this->groupAllocationCache[$booking->quotation_id];
        }

        $quotation = Quotation::find($booking->quotation_id);
        $isNormalizedGroup = $quotation
            && collect($quotation->extra_vehicles ?? [])->contains(fn ($ev) => ! empty($ev['booking_id']));

        if (! $isNormalizedGroup) {
            return $this->groupAllocationCache[$booking->quotation_id] = null;
        }

        $activeServiceTotal = (float) Booking::where('group_code', $booking->group_code)
            ->where('pickup_address', $booking->pickup_address)
            ->where('dropoff_address', $booking->dropoff_address)
            ->where('status', '!=', 'cancelled')
            ->sum('final_total');

        $adjustment = (float) ($quotation->additional_fee ?? 0) - (float) ($quotation->discount ?? 0);

        return $this->groupAllocationCache[$booking->quotation_id] = [
            'active_service_total' => $activeServiceTotal,
            'adjustment' => $adjustment,
        ];
    }

    private function allocatedRevenue(Booking $booking): float
    {
        $allocation = $this->groupAdjustmentAllocation($booking);
        if ($allocation === null || $allocation['active_service_total'] <= 0) {
            return (float) $booking->final_total;
        }

        $share = (float) $booking->final_total / $allocation['active_service_total'];

        return round((float) $booking->final_total + $share * $allocation['adjustment'], 2);
    }

    public function cancellationRate($start, $end, array $filters = []): array
    {
        $validQuery = fn () => $this->applyFilters(
            Booking::whereBetween('created_at', [$start, $end])
                ->whereNotIn('status', self::VALID_BOOKING_EXCLUDED_STATUSES),
            $filters
        );

        $valid = $validQuery()->count();
        $cancelled = $validQuery()->where('status', 'cancelled')->count();

        return [
            'cancelled' => $cancelled,
            'valid' => $valid,
            'rate' => $valid > 0 ? ($cancelled / $valid) * 100 : 0.0,
        ];
    }

    public function averageRevenuePerJob($start, $end, array $filters = []): array
    {
        $bookings = $this->completedPaidJobsQuery($start, $end, $filters)->get();

        $revenue = round($bookings->sum(fn (Booking $b) => $this->allocatedRevenue($b)), 2);
        $completedJobs = $bookings->count();

        return [
            'revenue' => $revenue,
            'completed_jobs' => $completedJobs,
            'average' => $completedJobs > 0 ? $revenue / $completedJobs : 0.0,
        ];
    }

    public function revenueByTruckType($start, $end, array $filters = [], ?int $limit = null): Collection
    {
        $bookings = $this->completedPaidJobsQuery($start, $end, $filters)
            ->whereNotNull('truck_type_id')
            ->with('truckType')
            ->get();

        $grouped = $bookings->groupBy('truck_type_id')
            ->map(fn (Collection $rows, $truckTypeId) => [
                'truck_type_name' => $rows->first()->truckType->name ?? 'Truck Type #' . $truckTypeId,
                'jobs' => $rows->count(),
                'revenue' => round($rows->sum(fn (Booking $b) => $this->allocatedRevenue($b)), 2),
            ])
            ->values()
            ->sortByDesc('revenue')
            ->values();

        return $limit !== null ? $grouped->take($limit)->values() : $grouped;
    }

    public function completedJobsByTruckType($start, $end, array $filters = []): Collection
    {
        return $this->revenueByTruckType($start, $end, $filters)
            ->map(fn (array $row) => ['truck_type_name' => $row['truck_type_name'], 'completed_jobs' => $row['jobs']]);
    }

    public function currentFleetUtilization(): array
    {
        $totalUnits = Unit::whereNull('archived_at')->count();
        $unitsInUse = app(UnitAvailabilityService::class)->evaluateAll()
            ->filter(fn($row) => $row['active_booking'] !== null)
            ->count();

        return [
            'total_units' => $totalUnits,
            'units_in_use' => $unitsInUse,
            'rate' => $totalUnits > 0 ? ($unitsInUse / $totalUnits) * 100 : 0.0,
        ];
    }

    public function unitPerformance($start, $end, array $filters = [], ?int $limit = null): Collection
    {
        $bookings = $this->completedPaidJobsQuery($start, $end, $filters)
            ->whereNotNull('assigned_unit_id')
            ->with('unit.truckType')
            ->get();

        $grouped = $bookings->groupBy('assigned_unit_id')
            ->map(function (Collection $rows, $unitId) {
                $revenue = round($rows->sum(fn (Booking $b) => $this->allocatedRevenue($b)), 2);
                $completedJobs = $rows->count();

                return [
                    'unit_name' => $rows->first()->unit->name ?? 'Unit #' . $unitId,
                    'truck_type_name' => $rows->first()->unit->truckType->name ?? '—',
                    'completed_jobs' => $completedJobs,
                    'revenue' => $revenue,
                    'average_revenue_per_job' => $completedJobs > 0 ? $revenue / $completedJobs : 0.0,
                ];
            })
            ->values()
            ->sortByDesc('revenue')
            ->values();

        return $limit !== null ? $grouped->take($limit)->values() : $grouped;
    }
}
