<?php

return [
    'company' => [
        'name' => env('INVOICE_COMPANY_NAME', 'Fox & Hema'),
        'address' => env('INVOICE_COMPANY_ADDRESS'),
        'phone' => env('INVOICE_COMPANY_PHONE'),
        'email' => env('INVOICE_COMPANY_EMAIL'),
        'tax_number' => env('INVOICE_COMPANY_TAX_NUMBER'),
        'registration_number' => env('INVOICE_COMPANY_REGISTRATION_NUMBER'),
    ],
    'pdf' => ['temp_dir' => storage_path('app/private/mpdf'), 'max_lines' => 200],
];
