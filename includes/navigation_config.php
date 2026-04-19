<?php

function vycrm_module_config(): array
{
    return [
        'billing' => [
            'title' => 'Billing & Transactions',
            'section_label' => 'Billing & Transactions',
            'icon' => 'fa-solid fa-wallet',
            'description' => 'Sales, purchases, customers, vendors, products, and daily transaction workflows.',
            'links' => [
                ['label' => 'Customers', 'href' => 'customers.php'],
                ['label' => 'Sale Invoices', 'href' => 'invoices.php'],
                ['label' => 'Vendors', 'href' => 'vendors.php'],
                ['label' => 'Purchase Bills', 'href' => 'purchases.php'],
                ['label' => 'Expenses', 'href' => 'expenses.php'],
                ['label' => 'Products / Service', 'href' => 'products.php'],
            ],
        ],
        'hr_operations' => [
            'title' => 'Attendance & Operations',
            'section_label' => 'Attendance & Operations',
            'icon' => 'fa-solid fa-users-gear',
            'description' => 'Attendance, reports, approvals, and day-to-day employee operations from one place.',
            'links' => [
                ['label' => 'Attendance', 'href' => 'attendance.php'],
                ['label' => 'Attendance Report', 'href' => 'attendance_report.php'],
                ['label' => 'Approvals', 'href' => 'manage_requests.php'],
                ['label' => 'Business Profile', 'href' => 'profile.php'],
            ],
        ],
    ];
}
