<?php

namespace App\Http\Requests\DriverPortal;

use Illuminate\Foundation\Http\FormRequest;

class ClockInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('driver_portal.use');
    }

    public function rules(): array
    {
        return ['vehicle_id' => ['required', 'integer', 'exists:vehicles,id']];
    }

    public function attributes(): array
    {
        return ['vehicle_id' => 'camion'];
    }
}
