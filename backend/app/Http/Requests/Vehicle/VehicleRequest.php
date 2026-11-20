<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle ? $this->user()->can('update', $vehicle) : $this->user()->can('create', Vehicle::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'plate_number' => mb_strtoupper(trim((string) $this->plate_number)),
            'name' => mb_strtoupper(trim((string) $this->name)),
            'imei' => $this->imei ? preg_replace('/\D/', '', (string) $this->imei) : null,
        ]);
    }

    public function rules(): array
    {
        $vehicle = $this->route('vehicle');
        $deviceId = $vehicle?->device?->id;

        return [
            'plate_number' => ['required', 'string', 'max:20', 'regex:/^\d{1,6}-[A-Z]-\d{1,2}$/u', Rule::unique('vehicles')->ignore($vehicle?->id)],
            'name' => ['required', 'string', 'max:30', Rule::unique('vehicles')->ignore($vehicle?->id)],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'type' => ['required', Rule::enum(VehicleType::class)],
            'year' => ['nullable', 'integer', 'between:1990,'.(now()->year + 1)],
            'status' => ['required', Rule::enum(VehicleStatus::class)],
            'speed_limit_kmh' => ['required', 'integer', 'between:30,130'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'current_driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'imei' => ['nullable', 'digits:15', Rule::unique('gps_devices', 'imei')->ignore($deviceId)],
            'device_model' => ['nullable', 'string', 'max:60'],
            'sim_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'plate_number' => 'immatriculation', 'name' => 'nom', 'brand' => 'marque', 'model' => 'modèle', 'type' => 'type',
            'year' => 'année', 'status' => 'statut', 'speed_limit_kmh' => 'vitesse maximale', 'odometer_km' => 'kilométrage',
            'current_driver_id' => 'chauffeur', 'imei' => 'IMEI', 'device_model' => 'modèle de boîtier', 'sim_number' => 'numéro de SIM',
        ];
    }

    public function messages(): array
    {
        return ['plate_number.regex' => 'L’immatriculation doit être au format 12345-A-1.'];
    }
}
