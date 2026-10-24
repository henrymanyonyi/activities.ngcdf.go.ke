<?php

namespace App\Rules;

use App\Enums\EmploymentType;
use App\Models\DsaRate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/** A job grade the DSA rate table knows: a scale from the HR register (1 to 10), or INTERN for casuals and attachees. */
class DsaGrade implements ValidationRule
{
    public function __construct(private EmploymentType $type = EmploymentType::Permanent) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $grade = DsaRate::normaliseGrade((string) $value);

        if ($grade === null) {
            $fail('The :attribute must be a scale from 1 to 10, or INTERN for casuals and attachees.');
        } elseif ($grade === DsaRate::INTERN && $this->type->requiresStaffNumber()) {
            $fail('INTERN is only for casuals and attachees.');
        }
    }
}
