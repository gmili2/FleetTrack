<?php

namespace App\Http\Requests\Geofence;

use App\Enums\GeofenceShape;
use App\Enums\GeofenceType;
use App\Models\Geofence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $zone = $this->route('geofence');

        return $zone ? $this->user()->can('update', $zone) : $this->user()->can('create', Geofence::class);
    }

    public function rules(): array
    {
        $circle = $this->input('shape') === GeofenceShape::Circle->value;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('geofences')->ignore($this->route('geofence')?->id)->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(GeofenceType::class)],
            'shape' => ['required', Rule::enum(GeofenceShape::class)],
            'center_lat' => [Rule::requiredIf($circle), 'nullable', 'numeric', 'between:-90,90'],
            'center_lng' => [Rule::requiredIf($circle), 'nullable', 'numeric', 'between:-180,180'],
            'radius_m' => [Rule::requiredIf($circle), 'nullable', 'integer', 'between:20,5000'],
            'coordinates' => [Rule::requiredIf(! $circle), 'nullable', 'array', 'min:3', 'max:200'],
            'coordinates.*' => ['array', 'size:2'],
            'coordinates.*.0' => ['numeric', 'between:-90,90'],
            'coordinates.*.1' => ['numeric', 'between:-180,180'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'customer_id' => [Rule::requiredIf($this->input('type') === GeofenceType::Customer->value), 'nullable', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'min_visit_seconds' => ['required', 'integer', 'between:0,7200'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom', 'type' => 'type de zone', 'shape' => 'forme', 'center_lat' => 'latitude du centre', 'center_lng' => 'longitude du centre',
            'radius_m' => 'rayon', 'coordinates' => 'polygone', 'color' => 'couleur', 'customer_id' => 'client associé',
            'min_visit_seconds' => 'durée minimale', 'address' => 'adresse',
        ];
    }

    public function messages(): array
    {
        return [
            'coordinates.required' => 'Dessinez le polygone sur la carte (au moins 3 points).',
            'coordinates.min' => 'Le polygone doit avoir au moins 3 points.',
            'center_lat.required' => 'Placez le cercle sur la carte.',
            'customer_id.required' => 'Une zone client doit être associée à un client.',
        ];
    }
}
