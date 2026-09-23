<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class LoginFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'email:filter', 'max:254'],
            'password' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 1024 || str_contains($value, "\0"))) {
                    $fail('The password must be at most 1024 bytes and contain no null bytes.');
                }
            }],
        ];
    }
}
