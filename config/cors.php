<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL is the portal (portal.basesearchmarketing.com). The
    // marketing site (basesearchmarketing.com) needs its own origins here so
    // its cart widget could call this API directly from the browser in the
    // future; today it only reaches this API through a server-side proxy in
    // its WordPress plugin, so this addition has no effect on that flow.
    'allowed_origins' => array_values(array_filter(array_merge(
        [env('FRONTEND_URL', 'http://localhost:3000')],
        array_map('trim', explode(',', env(
            'MARKETING_FRONTEND_URLS',
            'https://basesearchmarketing.com,https://www.basesearchmarketing.com'
        )))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
