<?php

return [
    // Dedicated base64 key, distinct from APP_KEY; no development secret fallback.
    'secret' => env('JWT_SECRET', ''),
    'issuer' => env('JWT_ISSUER', 'finance-ledger-api'),
    'audience' => env('JWT_AUDIENCE', 'finance-ledger-spa'),
];
