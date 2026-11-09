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

    // Unit fund issuance kinds.
    'issue_kinds' => [
        'initial' => 'Initial funds',
        'additional' => 'Additional funds',
    ],

    // Who paid for the expense. These values are enforced by a database CHECK constraint.
    'funding_sources' => [
        'unit_fund' => 'Unit fund (cash issued to the unit)',
        'company_direct' => 'Paid directly by the company',
        'crew_out_of_pocket' => 'Crew out of pocket (to be reimbursed)',
    ],

    'payout_methods' => [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'bank_transfer' => 'Bank transfer',
    ],

    // Expense evidence: private disk only, served through an authorised route.
    'evidence' => [
        'disk' => 'local',
        'directory' => 'finance/expenses',
        'max_kilobytes' => 8192,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],
];
