<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The single definition of a valid CLSU ID, shared by registration, the
 * profile form and admin user management so the accepted formats can never
 * drift between them.
 *
 * Registrar formats: students YY-NNNN, staff/faculty YY-NNNNNN, long-tenured
 * staff YYYY-NNNN. Digit counts are not certain, so this validates loosely and
 * never cross-checks against user_type. Always a string: leading zeros and the
 * dash are significant (23-0001 is not 23-1).
 */
class ClsuId implements ValidationRule
{
    // D: without it, $ would also match before a trailing "\n".
    public const PATTERN = '/^(\d{2}|\d{4})-\d{4,6}$/D';

    public const HINT = 'e.g. 23-0001 for students, 26-000001 for staff and faculty (long-time staff: 1996-0042).';

    public const TAKEN = 'This CLSU ID is already registered to another account. Contact the clinic administrator if it is yours.';

    /**
     * Format plus uniqueness, ignoring $ignore's own row when editing a user.
     */
    public static function rules(?User $ignore = null): array
    {
        return ['string', 'max:50', new self, Rule::unique('users', 'clsu_id')->ignore($ignore)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value)) {
            $fail('Enter a valid CLSU ID, '.self::HINT);
        }
    }
}
