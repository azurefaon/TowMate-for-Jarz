<?php

namespace App\Services;

use App\Enums\ReturnReason;

class ReturnReasonHandler
{
    public function parse(string $returnReasonText): array
    {
        if (blank($returnReasonText)) {
            return [
                'code' => null,
                'label' => null,
                'note' => null,
                'priority' => 'medium',
                'actions' => [],
            ];
        }

        foreach (ReturnReason::cases() as $reason) {
            if (str_starts_with($returnReasonText, $reason->label() . ':')) {
                $note = trim(substr($returnReasonText, strlen($reason->label() . ':')));
                
                return [
                    'code' => $reason->value,
                    'label' => $reason->label(),
                    'note' => $note,
                    'priority' => $reason->priority(),
                    'actions' => $this->getActionsForReason($reason),
                    'badge_class' => $this->getBadgeClass($reason->priority()),
                ];
            }
        }

        return [
            'code' => 'unknown',
            'label' => 'Returned',
            'note' => $returnReasonText,
            'priority' => 'medium',
            'actions' => ['reassign'],
            'badge_class' => 'rr-badge--medium',
        ];
    }

    protected function getActionsForReason(ReturnReason $reason): array
    {
        return match($reason) {
            ReturnReason::WRONG_ASSIGNMENT => [
                'reassign_correct_unit',
            ],
            ReturnReason::VEHICLE_ISSUE => [
                'mark_unit_maintenance',
                'assign_different_unit',
            ],
            ReturnReason::CUSTOMER_UNREACHABLE => [
                'attempt_contact',
                'cancel_booking',
            ],
            ReturnReason::UNSAFE_LOCATION => [
                'contact_customer',
                'cancel_with_reason',
            ],
            ReturnReason::EMERGENCY_SITUATION => [
                'reassign_urgently',
            ],
            ReturnReason::CUSTOMER_CANCELLED => [
                'apply_service_fee',
                'mark_customer_risk',
            ],
            ReturnReason::WRONG_VEHICLE_INFO => [
                'contact_customer',
                'requote_booking',
            ],
            ReturnReason::ACCESS_ISSUE => [
                'contact_for_access',
                'cancel_if_unresolved',
            ],
            ReturnReason::OTHER => [
                'contact_team_leader',
                'reassign',
            ],
        };
    }

    protected function getBadgeClass(string $priority): string
    {
        return match($priority) {
            'critical' => 'rr-badge--critical',
            'high' => 'rr-badge--high',
            'medium' => 'rr-badge--medium',
            default => 'rr-badge--medium',
        };
    }

    public function getActionLabel(string $actionCode): string
    {
        return match($actionCode) {
            'view_zone_coverage' => 'View Zone Coverage',
            'reassign_correct_unit' => 'Reassign Correct Unit',
            'mark_unit_maintenance' => 'Mark Unit for Maintenance',
            'assign_different_unit' => 'Assign Different Unit',
            'attempt_contact' => 'Attempt Contact',
            'reschedule_booking' => 'Reschedule',
            'cancel_booking' => 'Cancel Booking',
            'assess_risk' => 'Assess Risk',
            'contact_customer' => 'Contact Customer',
            'cancel_with_reason' => 'Cancel with Reason',
            'check_tl_status' => 'Check TL Status',
            'reassign_urgently' => 'Reassign Urgently',
            'follow_up_tl' => 'Follow Up with TL',
            'apply_service_fee' => 'Apply Service Fee',
            'mark_customer_risk' => 'Mark Customer Risk',
            'send_cancellation_notice' => 'Send Notice',
            'update_vehicle_info' => 'Update Vehicle Info',
            'requote_booking' => 'Re-Quote',
            'contact_for_access' => 'Request Access Info',
            'cancel_if_unresolved' => 'Cancel if Unresolved',
            'review_details' => 'Review Details',
            'contact_team_leader' => 'Contact Team Leader',
            'decide_action' => 'Decide Action',
            'reassign' => 'Reassign Task',
            default => ucwords(str_replace('_', ' ', $actionCode)),
        };
    }

    public function getActionIcon(string $actionCode): string
    {
        return match($actionCode) {
            'view_zone_coverage' => '📍',
            'reassign_correct_unit', 'reassign_urgently', 'reassign' => '🔄',
            'mark_unit_maintenance' => '🔧',
            'assign_different_unit' => '🚛',
            'attempt_contact', 'contact_customer', 'contact_for_access', 'contact_team_leader' => '📞',
            'reschedule_booking' => '📅',
            'cancel_booking', 'cancel_with_reason', 'cancel_if_unresolved' => '❌',
            'assess_risk' => '⚠️',
            'check_tl_status' => '👤',
            'follow_up_tl' => '💬',
            'apply_service_fee' => '💰',
            'mark_customer_risk' => '🚩',
            'send_cancellation_notice' => '📧',
            'update_vehicle_info' => '✏️',
            'requote_booking' => '💵',
            'review_details' => '🔍',
            'decide_action' => '🤔',
            default => '•',
        };
    }
}
