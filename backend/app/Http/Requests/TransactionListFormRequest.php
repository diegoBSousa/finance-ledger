<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransactionListFormRequest extends PaginatedReadFormRequest
{
    /** @return array<string,mixed> */
    public function validationData(): array
    {
        $data = parent::validationData();
        if ($this->route('accountNumber') !== null) {
            $data['account_number'] = $this->route('accountNumber');
        }

        return $data;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        $date = ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'];

        return parent::rules() + ['account_number' => ['sometimes', ...$this->identifierRules()],
            'date_from' => $date, 'date_to' => [...$date, ...($this->query->has('date_from') ? ['after_or_equal:date_from'] : [])],
            'type' => ['sometimes', 'required', 'string', 'in:income,expense']];
    }
}
