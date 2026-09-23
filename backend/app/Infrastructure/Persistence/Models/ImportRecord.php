<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Application\Imports\Data\ImportData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $uploaded_by_user_id
 * @property string $original_name
 * @property int $file_size_bytes
 * @property string $status
 * @property string $processed_rows
 * @property string $inserted_rows
 * @property string $duplicate_rows
 * @property string $rejected_rows
 * @property Carbon $created_at
 * @property Carbon|null $prepared_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $last_error
 */
final class ImportRecord extends Model
{
    protected $table = 'imports';

    protected function casts(): array
    {
        return [
            'id' => 'string', 'uploaded_by_user_id' => 'string', 'file_size_bytes' => 'integer',
            'processed_rows' => 'string', 'inserted_rows' => 'string', 'duplicate_rows' => 'string', 'rejected_rows' => 'string',
            'created_at' => 'datetime', 'prepared_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function toData(): ImportData
    {
        return new ImportData($this->id, $this->uploaded_by_user_id, $this->original_name, $this->file_size_bytes,
            $this->status, $this->processed_rows, $this->inserted_rows, $this->duplicate_rows, $this->rejected_rows,
            $this->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'), $this->prepared_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            $this->started_at?->utc()->format('Y-m-d\TH:i:s.u\Z'), $this->finished_at?->utc()->format('Y-m-d\TH:i:s.u\Z'), $this->last_error);
    }
}
