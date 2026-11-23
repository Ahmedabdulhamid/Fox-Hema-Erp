<?php

return [
    'queued' => 'Export queued. Open Export History to check progress and download.',
    'history' => 'Export History',
    'empty' => 'No exports yet.',
    'statuses' => ['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'],
    'failed' => 'Cannot export products',
    'all' => 'Export All',
    'selected' => 'Export Selected Products',
    'instructions' => 'Extract the ZIP first. Each part contains a complete import workbook and its matching images folder. Import one part at a time and upload the image files from that part. The importer creates NEW products; reimporting into the same database is not an update and may create duplicates. Large exports and repeated product names are separated into parts to respect import limits. Image filenames use the stored basenames because original upload filenames are not retained by the product image model; collisions are renamed consistently in the workbook and ZIP.',
    'costs_omitted' => 'Reference costs are blank because you do not have permission to view them.',
    'missing_images' => ':count missing image files were omitted from both the Images sheet and ZIP.',
    'incompatible' => 'Cannot faithfully export ":name" using the current import format. Check for archived status, duplicate color/size combinations, import row limits, or inactive, ambiguous or unauthorized references. The export has been stopped without dropping relationships.',
    'invalid_image' => 'An image is outside the supported product image storage or exceeds the current import image requirements. The export has been stopped.',
];
