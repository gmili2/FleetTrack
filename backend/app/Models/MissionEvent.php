<?php

namespace App\Models;

use App\Enums\MissionEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal d'une mission (création, démarrage, zones, commentaires…). */
class MissionEvent extends Model
{
    protected $fillable = ['mission_id', 'type', 'message', 'user_id', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return ['type' => MissionEventType::class, 'payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
