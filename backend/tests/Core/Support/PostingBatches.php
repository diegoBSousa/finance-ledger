<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostJournalData;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use Tests\Doubles\InMemoryAccountRepository;

final class PostingBatches
{
    public static function entry(string $description = 'Serviços de Limpeza #682', string $amount = '494618', string $type = 'Despesa', string $owner = '7'): PostJournalData
    {
        $prepare = new PrepareCsvPostingUseCase(new InMemoryAccountRepository(Accounts::data()), new CsvRowCanonicalizer);

        return PostJournalData::fromPrepared($prepare->execute(new PrepareCsvPostingRequest(
            $owner, new CsvRowData('2026-08-16', $description, $amount, $type),
        )));
    }

    public static function batch(): PostingBatchData
    {
        return new PostingBatchData('7', [self::entry()]);
    }
}
