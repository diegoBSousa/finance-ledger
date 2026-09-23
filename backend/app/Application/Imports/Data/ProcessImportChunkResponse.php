<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ProcessImportChunkResponse
{
    public function __construct(public bool $processed) {}
}
