<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ClientProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client role of a contact (gestionale Client): referent, status, preferred channel,
 * consents and the reversible archive/remove lifecycle (record-lifecycle.ts).
 */
class ClientProfile extends Model
{
    use BelongsToAgency;

    /** updated_at is the optimistic revision of client.save: keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @use HasFactory<ClientProfileFactory> */
    use HasFactory;

    /** types.ts clientStatuses */
    public const STATUSES = ['Nuovo', 'Da contattare', 'Da profilare', 'Profilato', 'Attivo', 'In trattativa', 'Concluso', 'Sospeso', 'Non interessato'];

    /** clients.tsx "Canale preferito" */
    public const CHANNELS = ['Telefono', 'Email', 'Messaggio', 'In presenza'];

    public const LIFECYCLE_STATES = ['archived', 'removed'];

    /** cadastral-search.ts cadastralGroups (investor criteria) */
    public const INVESTOR_GROUPS = [
        'A' => 'Abitazioni · A/1–A/9',
        'A10' => 'Uffici direzionali · A/10',
        'D' => 'Commerciale, industriale e produttivo · D',
        'C1' => 'Negozi e botteghe · C/1',
        'C2' => 'Magazzini · C/2',
        'C3' => 'Laboratori · C/3',
        'C4' => 'Categoria C/4',
        'C6' => 'Categoria C/6',
        'C7' => 'Categoria C/7',
        'B' => 'Categorie B',
        'E' => 'Categorie E',
        'F' => 'Categorie F',
    ];

    protected $table = 'client_profiles';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'Nuovo',
        'consent_practice' => false,
        'consent_marketing' => false,
        'is_investor' => false,
        'investor_groups' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_practice' => 'boolean',
            'consent_marketing' => 'boolean',
            'consents_updated_at' => 'datetime',
            'lifecycle_at' => 'datetime',
            'is_investor' => 'boolean',
            'investor_budget_min' => 'decimal:2',
            'investor_budget_max' => 'decimal:2',
            'investor_groups' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lifecycleBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifecycle_by_user_id');
    }

    public function isActiveRecord(): bool
    {
        return $this->lifecycle_state === null;
    }

    /**
     * Not archived and not removed (TS recordIsActive).
     *
     * @param  Builder<ClientProfile>  $query
     */
    public function scopeActiveRecords(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('lifecycle_state'));
    }

    /** client-card.ts: never call back these statuses. */
    public const NO_CALLBACK_STATUSES = ['Concluso', 'Sospeso', 'Non interessato'];

    /**
     * "Da richiamare" (client-card.ts clientNeedsCallback) without activities yet: status
     * 'Da contattare', or created more than contact_days ago, never Concluso/Sospeso/Non interessato.
     * Completed with the last activity and pending calls in the Activities phase.
     *
     * @param  Builder<ClientProfile>  $query
     */
    public function scopeNeedsCallback(Builder $query, ?int $contactDays = null): void
    {
        $days = $contactDays ?? (int) config('gestionale.contact_days', 7);
        $query->whereNotIn($query->qualifyColumn('status'), self::NO_CALLBACK_STATUSES)
            ->where(fn (Builder $q) => $q->where($q->qualifyColumn('status'), 'Da contattare')
                ->orWhere($q->qualifyColumn('created_at'), '<', now()->subDays($days)));
    }

    public function needsCallback(?int $contactDays = null): bool
    {
        $days = $contactDays ?? (int) config('gestionale.contact_days', 7);

        return ! in_array($this->status, self::NO_CALLBACK_STATUSES, true)
            && ($this->status === 'Da contattare' || $this->created_at?->lt(now()->subDays($days)));
    }
}
