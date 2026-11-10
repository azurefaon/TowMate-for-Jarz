<?php

/*
| Owner Finance and Commission module. See docs/owner-finance/spec.md.
*/
return [
    // Reporting and commission week: Monday to Saturday (confirmed). Sunday is outside every reporting week.
    'timezone' => 'Asia/Manila',
    'week_start_iso_day' => 1, // Monday
    'week_length_days' => 6,   // Monday..Saturday

    /*
    | Expense categories (key => English label), as confirmed by the client.
    | The database stores the key as text.
    */
    'expense_categories' => [
        'fuel_diesel' => 'Fuel / Diesel',
        'toll' => 'Toll',
        'parking' => 'Parking',
        'food_travel_allowance' => 'Food / Travel Allowance',
        'maintenance' => 'Maintenance',
        'repairs' => 'Repairs',
        'parts_equipment' => 'Parts / Equipment',
        'assistance_other_unit' => 'Assistance from Another Unit',
        'other' => 'Other Expenses',
    ],

    /*
    | Unit fund issuance kinds. Units normally receive operating funds per day, so there is no limit on how
    | many issuances a unit gets. The stored keys are unchanged from Phase 2; only the labels changed.
    */
    'issue_kinds' => [
        'initial' => 'Daily operating funds',
        'additional' => 'Additional funds',
    ],

    /*
    | How an expense was paid. One expense may be split across several sources (mixed funding); the splits
    | always sum exactly to the expense amount. Only 'unit_fund' reduces a unit fund balance.
    | These values are enforced by database CHECK constraints.
    */
    'funding_sources' => [
        'unit_fund' => 'Issued unit funds',
        'company_direct' => 'Direct company payment',
        'personal_advance' => 'Personal advance',
    ],

    'expense_scopes' => [
        'job' => 'Job expense',
        'unit' => 'Unit / monthly expense',
    ],

    /*
    | Rules still awaiting client confirmation. Expenses affected by them are recorded and shown with a flag,
    | but no automatic allocation, reimbursement or deduction is made.
    */
    'pending_rules' => [
        'monthly_allocation' => 'Monthly maintenance allocation across jobs is pending client confirmation.',
        'personal_advance_reimbursement' => 'Personal advance reimbursement rules are pending client confirmation. No Team Leader debt is created.',
    ],

    // Recording a payout never initiates a transfer; it records one the Owner already made.
    'payout_methods' => [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'bank_transfer' => 'Bank Transfer',
    ],

    // Expense evidence: private disk only, served through an authorised route.
    // Receipt and payment evidence is REQUIRED before Dispatcher verification and Owner approval (the approval rule is also a DB trigger).
    'evidence' => [
        'disk' => 'local',
        'directory' => 'finance/expenses',
        'max_kilobytes' => 8192,
        'max_files_per_upload' => 5,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    ],
];
