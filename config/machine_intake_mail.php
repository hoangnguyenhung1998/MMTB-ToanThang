<?php

return [
    'default_sender' => env('MACHINE_INTAKE_DEFAULT_SENDER', 'test'),

    'reply_to' => [
        'address' => env('MACHINE_INTAKE_REPLY_TO_ADDRESS'),
        'name' => env('MACHINE_INTAKE_REPLY_TO_NAME', 'MMTB Gmail Agent'),
    ],

    'senders' => [
        'test' => [
            'label' => 'Email test',
            'mailer' => 'machine_intake_test',
            'address' => env('MACHINE_INTAKE_TEST_ADDRESS'),
            'name' => env('MACHINE_INTAKE_TEST_NAME', 'MMTB Test'),
        ],
        'company' => [
            'label' => 'Email công ty',
            'mailer' => 'machine_intake_company',
            'address' => env('MACHINE_INTAKE_COMPANY_ADDRESS'),
            'name' => env('MACHINE_INTAKE_COMPANY_NAME', 'MMTB Công ty'),
        ],
    ],
];
