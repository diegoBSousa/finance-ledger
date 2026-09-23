<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\ImportPageQueryData;
use App\Application\Imports\Data\ImportRegistrationData;
use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Pagination\PageRequest;
use PHPUnit\Framework\TestCase;

abstract class ImportRepositoryContract extends TestCase
{
    abstract protected function repository(): ImportRepository;

    public static function registration(string $owner = '7'): ImportRegistrationData
    {
        return new ImportRegistrationData($owner, new StoredImportFileData('imports/'.str_repeat('a', 64).'.csv', 100000000, str_repeat('b', 64)), 'transactions.csv');
    }

    public function test_registration_is_pending_and_file_uploads_do_not_count_as_financial_rows(): void
    {
        $repo = $this->repository();
        $row = $repo->register(self::registration());
        self::assertSame('pending', $row->status);
        self::assertSame('0', $row->processedRows);
        self::assertSame(100000000, $row->fileSizeBytes);
        self::assertNull($row->preparedAt);
        self::assertTrue($repo->referencesFile(self::registration()->file->path));
        self::assertFalse($repo->referencesFile('imports/'.str_repeat('c', 64).'.csv'));
        self::assertEquals($row, $repo->find('7', $row->id));
    }

    public function test_read_is_owner_scoped_and_hides_foreign_imports(): void
    {
        $repo = $this->repository();
        $row = $repo->register(self::registration());
        self::assertNull($repo->find('8', $row->id));
        self::assertNull($repo->find('7', '99999'));
        self::assertSame([], $repo->page(new ImportPageQueryData('8', new PageRequest))->imports);
    }

    public function test_reupload_creates_a_new_attempt_without_file_level_financial_deduplication(): void
    {
        $repo = $this->repository();
        $first = $repo->register(self::registration());
        $second = $repo->register(self::registration());
        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $repo->page(new ImportPageQueryData('7', new PageRequest))->total);
    }

    public function test_pages_contain_at_most_ten_newest_imports_from_the_owner(): void
    {
        $repo = $this->repository();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $repo->register(self::registration())->id;
        }
        $repo->register(self::registration('8'));
        $page = $repo->page(new ImportPageQueryData('7', new PageRequest));
        self::assertSame(12, $page->total);
        self::assertSame(array_slice(array_reverse($ids), 0, 10), array_column($page->imports, 'id'));
        self::assertCount(2, $repo->page(new ImportPageQueryData('7', new PageRequest(2)))->imports);
        self::assertSame([], $repo->page(new ImportPageQueryData('7', new PageRequest(3)))->imports);
    }
}
