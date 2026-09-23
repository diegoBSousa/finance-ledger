<?php

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\Currency;

// HTTP upload/pagination endpoints are introduced in later increments.
return [
    'currency' => Currency::BRL->value,
    'max_upload_bytes' => 100000000,
    'default_page_size' => PageRequest::MAX_PER_PAGE,
    'max_page_size' => PageRequest::MAX_PER_PAGE,
];
