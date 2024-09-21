<?php

namespace App\Models\Validators;

use App\Models\Office;
use Illuminate\Validation\Rule;

class OfficeValidator
{
    public function validate(Office $office, array $attributes)
    {
        return validator(
            $attributes,
            [
                'title' => ['sometimes', 'required', 'string'],
                'description' => ['sometimes', 'required', 'string'],
                'lat' => ['sometimes', 'required', 'numeric'],
                'lng' => ['sometimes', 'required', 'numeric'],
                'address_line1' => ['sometimes', 'required', 'string'],
                'hidden' => ['bool'],
                'price_per_day' => ['sometimes', 'required', 'integer', 'min:100'],
                'monthly_discount' => ['integer', 'min:0'],

                'tags' => ['array'],
                'tags.*' => ['integer', Rule::exists('tags', 'id')]
            ]
        )->validate();
    }
}