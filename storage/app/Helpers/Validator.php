<?php
declare(strict_types=1);

namespace App\Helpers;

final class Validator
{
    public static function isValidICCID(string $iccid): bool
    {
        $clean = preg_replace('/\s+/', '', $iccid);
        return preg_match('/^[0-9]{19,20}$/', $clean) === 1;
    }
}
