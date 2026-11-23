<?php

namespace App\Modules\POS\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class WholeQuantity implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value))
            || ! preg_match('/^[0-9]*[1-9][0-9]*(?:\.0{1,4})?$/D', (string) $value)) {
            $fail('pos.validation.whole_quantity')->translate();
        }
    }
}
