<?php

namespace App\Infrastructure\Diagnostics;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class ProbeSharedUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $probeId) {}

    public function handle(): void
    {
        $file = 'diagnostics/'.$this->probeId.'.txt';
        $disk = Storage::disk('uploads');

        // A timed-out probe can already have cleaned up its own file.
        if (! $disk->exists($file)) {
            return;
        }

        Cache::store('redis')->put(
            'diagnostics:'.$this->probeId,
            hash('sha256', $disk->get($file)),
            60,
        );
    }
}
