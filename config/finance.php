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
    | Expense categories (key => English label).
    | PROVISIONAL: the client's specified list has not been added to the repository yet.
    | Replace these with the client's categories before Phase 3 goes live. The database stores the key
    | as text, so changing this list does not need a migration.
    */
    'expense_categories' => [
        'fuel' => 'Fuel',
        'toll' => 'Toll',
        'parking' => 'Parking',
        'maintenance' => 'Maintenance and repairs',
        'meals' => 'Meals',
        'other' => 'Other',
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
