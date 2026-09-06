<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePhysicianScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            // Absent on a plain edit (day/time only); present when a
            // Deactivate/Activate action resubmits the window with the flag
            // flipped. physician_id is deliberately not a field here — it is
            // never accepted from the browser.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
