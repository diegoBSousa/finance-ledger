<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class PostingBatchData
{
    public const int MAX_ENTRIES = 500;

    /** @var list<PostJournalData> */
    public array $entries;

    /** @param array<array-key, PostJournalData> $entries */
    public function __construct(public string $actorUserId, array $entries)
    {
        DecimalInteger::positive($actorUserId);
        if (! array_is_list($entries) || count($entries) < 1 || count($entries) > self::MAX_ENTRIES) {
            throw new DomainViolation('invalid_posting_batch', 'A posting batch requires between 1 and 500 operations.');
        }
        foreach ($entries as $entry) {
            if ($entry->journal->ownerUserId !== $actorUserId) {
                throw new DomainViolation('account_access_denied', 'A batch can only post to its authenticated owner.');
            }
        }
        $this->entries = $entries;
    }
}
