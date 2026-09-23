<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostCsvBatchRequest;
use App\Application\Accounting\Data\PostCsvBatchResponse;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostJournalData;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class PostCsvBatchUseCase
{
    public function __construct(private PrepareCsvPostingUseCase $prepare, private JournalRepository $journals) {}

    public function execute(PostCsvBatchRequest $request): PostCsvBatchResponse
    {
        if (! array_is_list($request->rows) || count($request->rows) < 1 || count($request->rows) > PostingBatchData::MAX_ENTRIES) {
            throw new DomainViolation('invalid_posting_batch', 'A posting batch requires between 1 and 500 rows.');
        }
        $actor = (string) DecimalInteger::positive($request->actorUserId);
        $entries = [];
        foreach ($request->rows as $row) {
            $entries[] = PostJournalData::fromPrepared($this->prepare->execute(new PrepareCsvPostingRequest($actor, $row)));
        }
        $result = $this->journals->post(new PostingBatchData($actor, $entries));

        return new PostCsvBatchResponse($result->entries);
    }
}
