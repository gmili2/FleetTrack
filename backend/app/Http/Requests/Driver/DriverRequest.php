<?php

namespace App\Http\Requests\Driver;

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $driver = $this->route('driver');

        return $driver ? $this->user()->can('update', $driver) : $this->user()->can('create', Driver::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_number' => mb_strtoupper(trim((string) $this->employee_number)),
            'badge_number' => mb_strtoupper(trim((string) $this->badge_number)),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('driver')?->id;

        return [
            'employee_number' => ['required', 'regex:/^CH-\d{4}$/', Rule::unique('drivers')->ignore($id)],
            'badge_number' => ['required', 'regex:/^B-\d{4}$/', Rule::unique('drivers')->ignore($id)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ().-]{8,}$/'],
            'license_number' => ['nullable', 'string', 'max:40'],
            'license_category' => ['nullable', Rule::in(['B', 'C', 'CE', 'D', 'EC'])],
            'license_expires_at' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(DriverStatus::class)],
            'hired_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_number' => 'matricule', 'badge_number' => 'n° de badge', 'first_name' => 'prénom', 'last_name' => 'nom',
            'phone' => 'téléphone', 'license_number' => 'n° de permis', 'license_category' => 'catégorie de permis',
            'license_expires_at' => 'date d’expiration du permis', 'status' => 'statut', 'hired_at' => 'date d’embauche',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_number.regex' => 'Le matricule doit être au format CH-1234.',
            'badge_number.regex' => 'Le badge doit être au format B-1234.',
        ];
    }
}
