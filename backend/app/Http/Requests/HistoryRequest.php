<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('history.view');
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'start' => ['nullable', 'date_format:H:i'],
            'end' => ['nullable', 'date_format:H:i', 'after:start'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'after:from'],
        ];
    }

    public function attributes(): array
    {
        return ['vehicle_id' => 'camion', 'date' => 'date', 'start' => 'heure de début', 'end' => 'heure de fin', 'from' => 'début', 'to' => 'fin'];
    }

    /** Période maximale : 3 jours. */
    public function after(): array
    {
        return [function ($v) {
            if ($this->filled('from') && $this->filled('to') && strtotime($this->to) - strtotime($this->from) > 3 * 86400) {
                $v->errors()->add('to', 'La période ne peut pas dépasser 3 jours.');
            }
        }];
    }
}
