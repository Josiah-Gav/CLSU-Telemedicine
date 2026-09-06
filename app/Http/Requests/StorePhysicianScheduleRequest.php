<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePhysicianScheduleRequest extends FormRequest
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
            // Rejects a midnight-crossing window (e.g. 22:00-02:00) for free:
            // 'after' with both fields carrying the same date_format compares
            // them as times on the same day, so 02:00 is never "after" 22:00.
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }
}
