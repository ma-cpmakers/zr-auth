<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** La regola del backoffice (App\Rules\SenzaCarattereNullo): un testo non porta il carattere nullo. */
final class SenzaCarattereNullo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('regole.carattere_nullo')->translate();
        }
    }
}
