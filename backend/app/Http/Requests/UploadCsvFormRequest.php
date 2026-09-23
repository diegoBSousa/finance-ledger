<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Imports\ImportLimits;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadTooLarge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UploadCsvFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    protected function prepareForValidation(): void
    {
        if (array_keys($this->allFiles()) !== ['file'] || $this->request->count() !== 0) {
            throw new InvalidImportFile('one_csv_required');
        }
        $file = $this->file('file');
        if (! $file instanceof UploadedFile) {
            throw new InvalidImportFile('one_csv_required');
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new UploadTooLarge;
        }
        if (in_array($file->getError(), [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            throw new ImportUnavailable;
        }
        if (! $file->isValid()) {
            throw new InvalidImportFile('incomplete_upload');
        }
        if ($file->getSize() > ImportLimits::MAX_BYTES) {
            throw new UploadTooLarge;
        }
    }
}
