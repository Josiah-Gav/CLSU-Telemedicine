<?php

namespace App\Http\Requests;

use App\Rules\ClsuId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Same caps as registration, so the two forms agree.
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // Write-once: a user may set their CLSU ID only while it is empty.
            // Once set, 'exclude' drops the field from validated(), so even a
            // crafted request cannot change or blank it; only an admin can.
            'clsu_id' => $this->user()->clsu_id === null
                ? ['nullable', ...ClsuId::rules()]
                : ['exclude'],
            'department' => ['nullable', 'string', 'max:255'],
            'contact_num' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['clsu_id.unique' => ClsuId::TAKEN];
    }
}
