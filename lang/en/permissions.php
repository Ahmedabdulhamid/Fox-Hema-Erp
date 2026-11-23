<?php

return [
    'auth_events' => [
        'view' => 'View Authentication Events',
    ],
    'backups' => [
        'delete' => 'Delete Backups',
        'download' => 'Download Backups',
        'restore' => [
            'approve' => 'Approve Backup Restores',
            'execute' => 'Execute Backup Restores',
            'request' => 'Request Backup Restores',
        ],
        'run' => 'Run Backups',
        'settings' => [
            'manage' => 'Manage Backup Settings',
        ],
        'verify' => 'Verify Backups',
        'view' => 'View Backups',
    ],
    'discounts' => [
        'assign' => 'Assign Discounts',
        'create' => 'Create Discounts',
        'delete' => 'Delete Discounts',
        'update' => 'Update Discounts',
        'view' => 'View Discounts',
    ],
    'inventory' => [
        'post' => 'Post Inventory Transactions',
        'quarantine' => [
            'inspect' => 'Inspect Quarantined Stock',
            'view' => 'View Quarantined Stock',
        ],
        'size_allocations' => [
            'manage' => 'Manage Size Allocations',
            'post' => 'Post Size Allocations',
            'view' => 'View Size Allocations',
        ],
        'stock_balances' => [
            'view' => 'View Stock Balances',
        ],
        'stock_movements' => [
            'view' => 'View Stock Movements',
            'view_cost' => 'View Inventory Movement Costs',
        ],
    ],
    'locations' => [
        'create' => 'Create Locations',
        'delete' => 'Delete Locations',
        'update' => 'Update Locations',
        'view' => 'View Locations',
    ],
    'modules' => [
        'update' => 'Update Modules',
        'view' => 'View Modules',
    ],
    'payments' => [
        'allocations' => [
            'create' => 'Create Payment Allocations',
            'release' => 'Release Payment Allocations',
        ],
        'create' => 'Create Payments',
        'customers' => [
            'manage' => 'Manage Customers',
        ],
        'methods' => [
            'manage' => 'Manage Payment Methods',
        ],
        'post' => 'Post Payments',
        'refunds' => [
            'create' => 'Create Payment Refunds',
            'post' => 'Post Payment Refunds',
            'recover' => 'Recover Payment Refunds',
        ],
        'reverse' => 'Reverse Payments',
        'view' => 'View Payments',
        'view_all_locations' => 'View Payments Across All Locations',
    ],
    'permissions' => [
        'assign' => 'Assign Permissions',
        'view' => 'View Permissions',
    ],
    'pos' => [
        'cash_movements' => 'Manage Cash Movements',
        'checkout' => 'Process POS Sales',
        'devices' => [
            'manage' => 'Manage POS Devices',
        ],
        'returns' => 'Manage POS Returns',
        'shifts' => [
            'approve' => 'Approve Cashier Shifts',
            'close' => 'Close Cashier Shifts',
            'open' => 'Open Cashier Shifts',
        ],
        'view' => 'View POS',
    ],
    'products' => [
        'assign_discounts' => 'Assign Product Discounts',
        'assign_taxes' => 'Assign Product Taxes',
        'brands' => [
            'create' => 'Create Brands',
            'delete' => 'Delete Brands',
            'update' => 'Update Brands',
            'view' => 'View Brands',
        ],
        'categories' => [
            'create' => 'Create Product Categories',
            'delete' => 'Delete Product Categories',
            'update' => 'Update Product Categories',
            'update_costing' => 'Update Category Costing Settings',
            'view' => 'View Product Categories',
            'view_costing' => 'View Category Costing Settings',
        ],
        'colors' => [
            'create' => 'Create Colors',
            'delete' => 'Delete Colors',
            'update' => 'Update Colors',
            'view' => 'View Colors',
        ],
        'create' => 'Create Products',
        'delete' => 'Delete Products',
        'images' => [
            'create' => 'Add Product Images',
            'delete' => 'Delete Product Images',
            'update' => 'Update Product Images',
        ],
        'import' => 'Import Products',
        'sizes' => [
            'create' => 'Create Sizes',
            'delete' => 'Delete Sizes',
            'update' => 'Update Sizes',
            'view' => 'View Sizes',
        ],
        'update' => 'Update Products',
        'update_prices' => 'Update Product Prices',
        'update_reference_cost' => 'Update Product Reference Costs',
        'variants' => [
            'create' => 'Create Product Variants',
            'delete' => 'Delete Product Variants',
            'update' => 'Update Product Variants',
        ],
        'view' => 'View Products',
        'view_reference_cost' => 'View Product Reference Costs',
    ],
    'purchasing' => [
        'purchase_orders' => [
            'cancel' => 'Cancel Purchase Orders',
            'confirm' => 'Confirm Purchase Orders',
            'create' => 'Create Purchase Orders',
            'update' => 'Update Purchase Orders',
            'view' => 'View Purchase Orders',
        ],
        'receipts' => [
            'cancel' => 'Cancel Purchase Receipts',
            'create' => 'Create Purchase Receipts',
            'post' => 'Post Purchase Receipts',
            'update' => 'Update Purchase Receipts',
            'view' => 'View Purchase Receipts',
        ],
        'suppliers' => [
            'create' => 'Create Suppliers',
            'deactivate' => 'Deactivate Suppliers',
            'update' => 'Update Suppliers',
            'view' => 'View Suppliers',
        ],
        'vendor_bills' => [
            'cancel' => 'Cancel Vendor Bills',
            'create' => 'Create Vendor Bills',
            'post' => 'Post Vendor Bills',
            'update' => 'Update Vendor Bills',
            'view' => 'View Vendor Bills',
        ],
        'vendor_payments' => [
            'allocate' => 'Allocate Vendor Payments',
            'create' => 'Create Vendor Payments',
            'post' => 'Post Vendor Payments',
            'reverse' => 'Reverse Vendor Payments',
            'view' => 'View Vendor Payments',
            'view_all_locations' => 'View Vendor Payments Across All Locations',
        ],
    ],
    'reporting' => [
        'inventory' => [
            'view' => 'View Inventory Reports',
        ],
        'pos' => [
            'view' => 'View POS Reports',
        ],
        'profitability' => [
            'view' => 'View Profitability Reports',
        ],
        'purchasing' => [
            'view' => 'View Purchasing Reports',
        ],
        'sales' => [
            'view' => 'View Sales Reports',
        ],
        'stock_transfers' => [
            'view' => 'View Stock Transfer Reports',
        ],
        'treasury' => [
            'view' => 'View Treasury Reports',
        ],
    ],
    'roles' => [
        'create' => 'Create Roles',
        'delete' => 'Delete Roles',
        'update' => 'Update Roles',
        'view' => 'View Roles',
    ],
    'sales' => [
        'credit_notes' => [
            'create' => 'Create Sales Credit Notes',
            'download' => 'Download Sales Credit Notes',
            'post' => 'Post Sales Credit Notes',
            'update' => 'Update Sales Credit Notes',
            'view' => 'View Sales Credit Notes',
        ],
        'deliveries' => [
            'cancel' => 'Cancel Sales Deliveries',
            'create' => 'Create Sales Deliveries',
            'post' => 'Post Sales Deliveries',
            'update' => 'Update Sales Deliveries',
            'view' => 'View Sales Deliveries',
        ],
        'invoices' => [
            'cancel' => 'Cancel Sales Invoices',
            'create' => 'Create Sales Invoices',
            'download' => 'Download Sales Invoices',
            'post' => 'Post Sales Invoices',
            'update' => 'Update Sales Invoices',
            'view' => 'View Sales Invoices',
        ],
        'orders' => [
            'cancel' => 'Cancel Sales Orders',
            'confirm' => 'Confirm Sales Orders',
            'create' => 'Create Sales Orders',
            'update' => 'Update Sales Orders',
            'view' => 'View Sales Orders',
        ],
        'returns' => [
            'approve' => 'Approve Sales Returns',
            'cancel' => 'Cancel Sales Returns',
            'create' => 'Create Sales Returns',
            'post' => 'Post Sales Returns',
            'update' => 'Update Sales Returns',
            'view' => 'View Sales Returns',
        ],
    ],
    'sessions' => [
        'revoke' => 'Revoke User Sessions',
        'view' => 'View User Sessions',
    ],
    'stock_transfers' => [
        'approve' => 'Approve Stock Transfers',
        'cancel' => 'Cancel Stock Transfers',
        'dispatch' => 'Dispatch Stock Transfers',
        'manage' => 'Manage Stock Transfers',
        'receive' => 'Receive Stock Transfers',
        'view' => 'View Stock Transfers',
    ],
    'taxes' => [
        'assign' => 'Assign Taxes',
        'create' => 'Create Taxes',
        'delete' => 'Delete Taxes',
        'update' => 'Update Taxes',
        'view' => 'View Taxes',
    ],
    'treasury' => [
        'accounts' => [
            'view' => 'View Treasury Accounts',
        ],
        'balances' => [
            'view' => 'View Treasury Balances',
        ],
        'banks' => [
            'manage' => 'Manage Bank Accounts',
        ],
        'cashboxes' => [
            'manage' => 'Manage Cashboxes',
        ],
        'drafts' => [
            'create' => 'Create Treasury Drafts',
        ],
        'funds' => [
            'transfer' => 'Transfer Treasury Funds',
        ],
        'movements' => [
            'post' => 'Post Treasury Movements',
            'reverse' => 'Reverse Treasury Movements',
        ],
        'operate_all_locations' => 'Operate Treasury Across All Locations',
        'view_all_locations' => 'View Treasury Across All Locations',
    ],
    'users' => [
        'create' => 'Create Users',
        'delete' => 'Delete Users',
        'update' => 'Update Users',
        'view' => 'View Users',
    ],
];
