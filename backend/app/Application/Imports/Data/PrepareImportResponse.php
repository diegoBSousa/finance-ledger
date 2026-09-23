<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class PrepareImportResponse
{
    public function __construct(public bool $prepared) {}
}
