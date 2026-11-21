<?php

namespace App\Http\Requests\GpsDevice;

use App\Models\GpsDevice;
use App\Support\GpsConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GpsDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('gps_device');

        return $device ? $this->user()->can('update', $device) : $this->user()->can('create', GpsDevice::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'imei' => preg_replace('/\D/', '', (string) $this->imei),
            'label' => $this->label !== null && trim((string) $this->label) !== '' ? mb_strtoupper(trim((string) $this->label)) : null,
        ]);
    }

    public function rules(): array
    {
        $device = $this->route('gps_device');

        return [
            'label' => ['nullable', 'string', 'max:40', Rule::unique('gps_devices', 'label')->ignore($device?->id)],
            'imei' => ['required', 'digits:15', Rule::unique('gps_devices', 'imei')->ignore($device?->id)],
            'model' => ['nullable', 'string', 'max:60'],
            'sim_number' => ['nullable', 'string', 'max:30'],
            'operator' => ['nullable', Rule::in(array_keys(GpsConfig::OPERATORS))],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...GpsConfig::rules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'nom de la carte', 'imei' => 'IMEI', 'model' => 'modèle', 'sim_number' => 'numéro de SIM', 'operator' => 'opérateur',
            'vehicle_id' => 'camion', 'is_active' => 'statut', ...GpsConfig::attributes(),
        ];
    }
}
