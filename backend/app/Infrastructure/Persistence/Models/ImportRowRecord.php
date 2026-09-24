<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Application\Imports\Data\ImportRowData;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $import_id
 * @property string $source_record_number
 * @property string $status
 * @property string|null $source_row_hash
 * @property string|null $journal_entry_id
 * @property string|null $error_code
 */
final class ImportRowRecord extends Model
{
    protected $table = 'import_rows';

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['id' => 'string', 'import_id' => 'string', 'source_record_number' => 'string', 'journal_entry_id' => 'string'];
    }

    public function toData(): ImportRowData
    {
        return new ImportRowData($this->id, $this->import_id, $this->source_record_number, $this->status,
            $this->source_row_hash, $this->journal_entry_id, $this->error_code);
    }
}
