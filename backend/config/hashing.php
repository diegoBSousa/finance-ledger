<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 3,
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
