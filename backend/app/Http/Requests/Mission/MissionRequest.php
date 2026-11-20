<?php

namespace App\Http\Requests\Mission;

use App\Models\Mission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('mission');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', Mission::class);
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')->where('status', 'active')],
            'driver_id' => ['required', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')->whereNot('status', 'inactive')],
            'origin_geofence_id' => ['required', 'integer', Rule::exists('geofences', 'id')->whereNull('deleted_at')],
            'destination_geofence_id' => ['required', 'integer', 'different:origin_geofence_id', Rule::exists('geofences', 'id')->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'vehicle_id' => 'camion', 'driver_id' => 'chauffeur', 'origin_geofence_id' => 'départ', 'destination_geofence_id' => 'destination',
            'customer_id' => 'client', 'planned_start_at' => 'début prévu', 'planned_end_at' => 'fin prévue', 'cargo' => 'chargement',
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.exists' => 'Ce camion n’est pas disponible (inactif ou en maintenance).',
            'driver_id.exists' => 'Ce chauffeur est inactif.',
            'destination_geofence_id.different' => 'La destination doit être différente du départ.',
        ];
    }
}
