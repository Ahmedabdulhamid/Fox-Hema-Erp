<?php

return [
    'CN' => 'Sales Credit Note',
    'evidence' => 'Document links and audit evidence',
    'migration_required' => 'Apply the document numbering migration before creating new documents.',
    'invalid_identity' => 'Select a supported document type and a valid internal identity.',
    'identity_conflict' => 'This document number belongs to another location.',
    'historical_numbering_forbidden' => 'Existing documents cannot be renumbered automatically. Retain the original reference.',
    'location_code_required' => 'A valid stable location code is required before document creation.',
    'sequence_exhausted' => 'The document sequence has reached its supported limit.', 'immutable_number' => 'Allocated document numbers are immutable.',
    'legacy_record' => 'Legacy record #:id', 'legacy_reference' => 'Original legacy reference',
    'related_documents' => 'Related documents', 'status_progression' => 'Document progress',
    'SO' => 'Sales Order', 'SD' => 'Sales Delivery', 'INV' => 'Sales Invoice', 'SR' => 'Sales Return',
    'PO' => 'Purchase Order', 'GRN' => 'Goods Receipt', 'BILL' => 'Vendor Bill', 'SA' => 'Size Allocation',
    'SM' => 'Stock Movement', 'QC' => 'Quarantine Custody', 'TRF' => 'Stock Transfer',
    'draft' => 'Draft', 'rfq' => 'RFQ', 'confirmed' => 'Confirmed', 'partially_fulfilled' => 'Partially fulfilled',
    'fulfilled' => 'Fulfilled', 'closed' => 'Closed', 'partially_received' => 'Partially received', 'received' => 'Received',
    'approved' => 'Approved', 'posted' => 'Posted', 'cancelled' => 'Cancelled', 'pending_inspection' => 'Pending inspection',
    'erp_number' => 'ERP document number', 'source_document' => 'Source document', 'remaining' => 'Remaining quantity',
];
