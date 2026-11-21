<?php

namespace App\Http\Requests\DriverPortal;

use Illuminate\Foundation\Http\FormRequest;

class ClockInRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Il faut le droit ET un compte lié à une fiche chauffeur (le Super Administrateur a tous les droits mais pas de fiche).
        return $this->user()->can('driver_portal.use') && $this->user()->driver_id !== null;
    }

    public function rules(): array
    {
        return ['vehicle_id' => ['required', 'integer', 'exists:vehicles,id'], 'gps_device_id' => ['required', 'integer', 'exists:gps_devices,id']];
    }

    public function attributes(): array
    {
        return ['vehicle_id' => 'camion', 'gps_device_id' => 'carte GPS'];
    }
}
