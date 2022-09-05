<?php

/*
 * You can place your custom package configuration in here.
 */
return [
    'max_count' => env('PHONECONFIRM_MAX_COUNT', 3),
    'code_lenght' => env('PHONECONFIRM_CODE_LENGHT', 4),
    'retry_after' => env('PHONECONFIRM_RETRY_AFTER', 300),
];