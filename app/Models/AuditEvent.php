<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only audit row (the database trigger refuses UPDATE/DELETE as well).
 * agency_id is null only for platform events written outside an agency.
 * Write it through App\Gestionale\Audit.
 */
class AuditEvent extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $table = 'audit_events';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Il registro attività non si modifica.'));
        static::deleting(fn () => throw new LogicException('Il registro attività non si cancella.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function agencyIsOptional(): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
