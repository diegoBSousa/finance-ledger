<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Normalizer;

final class Utf8Text
{
    public static function canonical(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new DomainViolation('invalid_utf8', 'Text must be valid UTF-8.');
        }

        return preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $normalized)
            ?? throw new DomainViolation('invalid_utf8', 'Text must be valid UTF-8.');
    }
}
