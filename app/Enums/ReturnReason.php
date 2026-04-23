<?php

namespace App\Enums;

enum ReturnReason: string
{
    case WRONG_ASSIGNMENT = 'wrong_assignment';
    case VEHICLE_ISSUE = 'vehicle_issue';
    case CUSTOMER_UNREACHABLE = 'customer_unreachable';
    case UNSAFE_LOCATION = 'unsafe_location';
    case EMERGENCY_SITUATION = 'emergency_situation';
    case CUSTOMER_CANCELLED = 'customer_cancelled';
    case WRONG_VEHICLE_INFO = 'wrong_vehicle_info';
    case ACCESS_ISSUE = 'access_issue';
    case OTHER = 'other';

    public function label(): string
    {
        return match($this) {
            self::WRONG_ASSIGNMENT => 'Wrong Assignment',
            self::VEHICLE_ISSUE => 'Vehicle Issue',
            self::CUSTOMER_UNREACHABLE => 'Customer Unreachable',
            self::UNSAFE_LOCATION => 'Unsafe Location',
            self::EMERGENCY_SITUATION => 'Emergency Situation',
            self::CUSTOMER_CANCELLED => 'Customer Cancelled On-Site',
            self::WRONG_VEHICLE_INFO => 'Wrong Vehicle Information',
            self::ACCESS_ISSUE => 'Cannot Access Vehicle',
            self::OTHER => 'Other',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::WRONG_ASSIGNMENT => 'Unit type or location mismatch',
            self::VEHICLE_ISSUE => 'Unit mechanical problem or breakdown',
            self::CUSTOMER_UNREACHABLE => 'Cannot contact customer after multiple attempts',
            self::UNSAFE_LOCATION => 'Security concern or dangerous area',
            self::EMERGENCY_SITUATION => 'Team leader personal emergency',
            self::CUSTOMER_CANCELLED => 'Customer cancelled upon arrival',
            self::WRONG_VEHICLE_INFO => 'Vehicle type/size different from booking',
            self::ACCESS_ISSUE => 'Parking garage, locked gate, or physical barrier',
            self::OTHER => 'Other reason not listed',
        };
    }

    public function requiresNote(): bool
    {
        return match($this) {
            self::VEHICLE_ISSUE,
            self::UNSAFE_LOCATION,
            self::EMERGENCY_SITUATION,
            self::WRONG_VEHICLE_INFO,
            self::ACCESS_ISSUE,
            self::OTHER => true,
            default => false,
        };
    }

    public function priority(): string
    {
        return match($this) {
            self::EMERGENCY_SITUATION => 'critical',
            self::WRONG_ASSIGNMENT,
            self::VEHICLE_ISSUE,
            self::UNSAFE_LOCATION => 'high',
            default => 'medium',
        };
    }

    public function shouldAutoReassign(): bool
    {
        return match($this) {
            self::WRONG_ASSIGNMENT,
            self::VEHICLE_ISSUE,
            self::EMERGENCY_SITUATION => true,
            default => false,
        };
    }

    public function shouldMarkUnitUnavailable(): bool
    {
        return $this === self::VEHICLE_ISSUE;
    }

    public function shouldMarkTLUnavailable(): bool
    {
        return $this === self::EMERGENCY_SITUATION;
    }

    public function shouldChargeServiceFee(): bool
    {
        return $this === self::CUSTOMER_CANCELLED;
    }

    public function requiresDispatcherDecision(): bool
    {
        return match($this) {
            self::CUSTOMER_UNREACHABLE,
            self::UNSAFE_LOCATION,
            self::ACCESS_ISSUE => true,
            default => false,
        };
    }

    public function requiresRequote(): bool
    {
        return $this === self::WRONG_VEHICLE_INFO;
    }

    public function minNoteLength(): int
    {
        return $this === self::OTHER ? 20 : 10;
    }

    public static function toArray(): array
    {
        return array_map(
            fn(self $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
                'description' => $reason->description(),
                'requires_note' => $reason->requiresNote(),
                'priority' => $reason->priority(),
            ],
            self::cases()
        );
    }

    public static function orderedByPriority(): array
    {
        $cases = self::cases();
        usort($cases, function($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2];
            return $priorityOrder[$a->priority()] <=> $priorityOrder[$b->priority()];
        });
        return $cases;
    }
}
