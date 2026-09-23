<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

interface Clock
{
    public function now(): int;
}
