<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostedJournalData;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostingBatchResultData;
use App\Application\Accounting\PreparedPostingValidator;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;

/** Contract double; it does not substitute for MySQL transaction/trigger tests. */
final class InMemoryJournalRepository implements JournalRepository
{
    private array $entries = [];

    /** @param list<AccountData> $accounts */
    public function __construct(private readonly array $accounts) {}

    public function post(PostingBatchData $batch): PostingBatchResultData
    {
        $staged = $this->entries;
        $results = [];
        foreach ($batch->entries as $entry) {
            (new PreparedPostingValidator)->validate($entry, $this->accounts);
            $key = $batch->actorUserId.':'.$entry->sourceRowHash;
            if (isset($staged[$key])) {
                [$id, $original] = $staged[$key];
                if ($original->canonicalRecord !== $entry->canonicalRecord) {
                    throw new DomainViolation('idempotency_conflict', 'Canonical identity mismatch.');
                }
                $status = 'duplicate';
            } else {
                $id = (string) (count($staged) + 1);
                $staged[$key] = [$id, $entry];
                $status = 'inserted';
            }
            $results[] = new PostedJournalData($entry->sourceRowHash, $id, $status);
        }
        $this->entries = $staged;

        return new PostingBatchResultData($results);
    }
}
