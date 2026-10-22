<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Guards against showing another person's CHIS record because of a wrong or
 * mistyped CLSU ID: the CHIS record's first AND last name must equal the
 * Telemed patient's, ignoring case, accents, punctuation and spacing.
 *
 * Limitation: Telemed stores no birth date, so names are the only check in v1.
 */
class ChisIdentity
{
    public static function matches(User $patient, array $chisRecord): bool
    {
        $pairs = [
            [$patient->first_name, $chisRecord['first_name'] ?? null],
            [$patient->last_name, $chisRecord['last_name'] ?? null],
        ];

        foreach ($pairs as [$ours, $theirs]) {
            $ours = self::normalize($ours);

            if ($ours === '' || $ours !== self::normalize($theirs)) {
                return false;
            }
        }

        return true;
    }

    /** "Peña" / "dela-cruz" / "Dela  Cruz." all reduce to a comparable form. */
    private static function normalize(mixed $name): string
    {
        $name = Str::lower(Str::ascii((string) $name));

        // Punctuation and all whitespace removed, so "Dela Cruz" == "dela-cruz".
        return preg_replace('/[\s.,\'’`-]+/u', '', $name);
    }
}
