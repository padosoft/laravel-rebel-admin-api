<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    | Where the admin-api JSON endpoints are mounted.
    */
    'prefix' => env('REBEL_ADMIN_API_PREFIX', 'rebel/admin/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | guard:   the auth guard the request must be authenticated on ('' = default).
    | ability: the Gate ability the user must pass. Defaults to 'rebel-admin' so the API
    |          is FAIL-CLOSED out of the box — define that Gate (or change this) to grant
    |          access; set it to '' only if your guard already implies admin rights.
    */
    'guard' => env('REBEL_ADMIN_API_GUARD', ''),
    'ability' => env('REBEL_ADMIN_API_ABILITY', 'rebel-admin'),

    /*
    |--------------------------------------------------------------------------
    | Base middleware
    |--------------------------------------------------------------------------
    | Applied before the EnsureAdmin gate (which is always appended).
    */
    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | GDPR retention tiers
    |--------------------------------------------------------------------------
    | Surfaced by GET {prefix}/compliance/overview as the retention summary. Purely
    | descriptive metadata for the panel; pruning itself lives in the core/retention layer.
    */
    'retention_tiers' => [
        ['name' => 'detail', 'days' => 30],
        ['name' => 'aggregate', 'days' => 365],
    ],

];
