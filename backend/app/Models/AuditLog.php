<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Journal d'audit des actions des utilisateurs. */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'auditable_type', 'auditable_id', 'description', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Enregistre une action de l'utilisateur connecté. */
    public static function record(string $action, ?Model $subject = null, ?string $description = null, ?array $changes = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => request()?->ip(),
        ]);
    }
}
