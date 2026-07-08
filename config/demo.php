<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Reset
    |--------------------------------------------------------------------------
    |
    | Controls the /demo/reset endpoint, which runs `migrate:fresh` and wipes
    | the entire database. This is destructive and the route is unauthenticated,
    | so it must be explicitly enabled per-environment. Default is false.
    |
    | The production hard-block in DemoController::reset() applies regardless of
    | this flag — production can never reset even if the flag is mistakenly set.
    |
    */

    'reset_enabled' => (bool) env('DEMO_RESET_ENABLED', false),

];
