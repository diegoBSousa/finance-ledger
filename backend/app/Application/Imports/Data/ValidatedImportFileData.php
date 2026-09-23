<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ValidatedImportFileData
{
    /** @param list<string> $blockHashes */
    public function __construct(public int $headerOffset, public array $blockHashes = []) {}
}
