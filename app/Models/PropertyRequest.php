<?php

namespace App\Models;

use App\Casts\JsonObject;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\PropertyRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's search request (gestionale Request): one per client, profiled with the questionnaire.
 */
class PropertyRequest extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<PropertyRequestFactory> */
    use HasFactory;

    /** engine.ts requestStatuses */
    public const STATUSES = ['Nuova', 'Da completare', 'Profilata', 'Ricerca attiva', 'Immobili individuati', 'Immobili proposti',
        'Visita programmata', 'In trattativa', 'Sospesa', 'Conclusa', 'Annullata'];

    /** Statuses that request.finish recomputes. */
    public const PROFILING_STATUSES = ['Nuova', 'Da completare', 'Profilata', 'Ricerca attiva'];

    protected $table = 'property_requests';

    /** updated_at is the optimistic revision: keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'lifecycle_state', 'lifecycle_at', 'lifecycle_by_user_id', 'lifecycle_reason'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'Nuova',
        'priority' => 'Normale',
        'step_id' => 'operation',
        'finished' => false,
        'title_auto' => false,
        'criteria' => '{}',
        'classifications' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => JsonObject::class,
            'classifications' => JsonObject::class,
            'title_auto' => 'boolean',
            'finished' => 'boolean',
            'due_date' => 'date',
            'lifecycle_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<ClientProfile, $this>
     */
    public function clientProfile(): BelongsTo
    {
        return $this->belongsTo(ClientProfile::class, 'contact_id', 'contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lifecycleBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifecycle_by_user_id');
    }

    /** @return HasMany<PropertyMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(PropertyMatch::class);
    }

    public function isActiveRecord(): bool
    {
        return $this->lifecycle_state === null;
    }

    /**
     * @param  Builder<PropertyRequest>  $query
     */
    public function scopeActiveRecords(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('lifecycle_state'));
    }

    /**
     * permissions.ts visibleEntities(): admin all, crm only its own requests, scout none.
     *
     * @param  Builder<PropertyRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, ?AgencyMembership $membership): void
    {
        match ($membership?->isActive() ? $membership->role : null) {
            'admin' => $query->where($query->qualifyColumn('agency_id'), $membership->agency_id),
            // crm: own requests whose client is visible too (permissions.ts visibleEntities).
            'crm' => $query->where($query->qualifyColumn('agency_id'), $membership->agency_id)
                ->where($query->qualifyColumn('agent_user_id'), $membership->user_id)
                ->whereHas('clientProfile', fn (Builder $p) => $p->where('agent_user_id', $membership->user_id)),
            default => $query->whereRaw('false'),
        };
    }
}
