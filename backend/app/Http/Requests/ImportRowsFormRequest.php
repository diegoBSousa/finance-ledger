<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ImportRowsFormRequest extends PaginatedReadFormRequest
{
    /** @return array<string,mixed> */
    public function validationData(): array
    {
        $data = parent::validationData();
        $data['import_id'] = $this->route('importId');
        // The errors resource can never be widened to successful records through a query parameter.
        if ($this->routeIs('imports.errors')) {
            $data['status'] = 'rejected';
        }

        return $data;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return parent::rules() + ['import_id' => $this->identifierRules(), 'status' => ['sometimes', 'required', 'string', 'in:inserted,duplicate,rejected']];
    }
}
