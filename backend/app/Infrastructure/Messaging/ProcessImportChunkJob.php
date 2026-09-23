<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Imports\Data\ProcessImportChunkRequest;
use App\Application\Imports\ProcessImportChunkUseCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessImportChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 60];

    public function __construct(public readonly string $deliveryId)
    {
        $this->beforeCommit();
    }

    public function handle(ProcessImportChunkUseCase $process): void
    {
        $process->execute(new ProcessImportChunkRequest($this->deliveryId));
    }
}
