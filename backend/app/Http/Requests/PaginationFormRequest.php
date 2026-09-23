<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\DomainViolation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PaginationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'per_page' => ['sometimes', 'required', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'between:1,10'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                try {
                    new PageRequest((int) $this->query('page', 1), (int) $this->query('per_page', 10));
                } catch (DomainViolation) {
                    $validator->errors()->add('page', 'The requested page exceeds the supported range.');
                }
            }
        });
    }
}
