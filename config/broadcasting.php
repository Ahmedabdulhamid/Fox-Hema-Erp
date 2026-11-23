<?php

return [
    'default' => env('APP_ENV') === 'load-testing'
        ? env('BROADCAST_CONNECTION', 'log')
        : 'null',

    'connections' => [
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
