<?php

namespace App\Models;

use App\Gestionale\AgencySettings;
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
     * "Da richiamare" (client-card.ts clientNeedsCallback), never for Concluso / Sospeso / Non interessato:
     * 1. a pending "Telefonata" of the client decides alone: the earliest one, only if it is overdue
     *    (a call already set for the future excludes the client, even when "Da contattare");
     * 2. otherwise "Da contattare" always needs a callback;
     * 3. otherwise the last completed activity of the client (or the creation date when there is none)
     *    is older than the agency's contact_days.
     * contact_days is the value saved for the agency (AgencySettings), never the env default alone.
     *
     * @param  Builder<ClientProfile>  $query
     */
    public function scopeNeedsCallback(Builder $query, ?int $contactDays = null): void
    {
        $days = max(1, $contactDays ?? AgencySettings::contactDays());
        $table = $query->getModel()->getTable();
        $placeholders = implode(', ', array_fill(0, count(self::NO_CALLBACK_STATUSES), '?'));
        $link = "a.agency_id = {$table}.agency_id AND a.contact_id = {$table}.contact_id";
        $pending = "SELECT 1 FROM activities a WHERE {$link} AND a.kind = 'Telefonata' AND a.status NOT IN ('Completata', 'Annullata')";
        $earliest = "SELECT a.scheduled_at FROM activities a WHERE {$link} AND a.kind = 'Telefonata' AND a.status NOT IN ('Completata', 'Annullata') ORDER BY a.scheduled_at ASC NULLS LAST, a.id ASC LIMIT 1";
        $last = "SELECT MAX(COALESCE(a.completed_at, a.outcome_confirmed_at)) FROM activities a WHERE {$link} AND a.status = 'Completata'";

        $query->whereRaw("{$table}.status NOT IN ({$placeholders})", self::NO_CALLBACK_STATUSES)
            ->whereRaw("CASE
                WHEN EXISTS ({$pending}) THEN ({$earliest}) < NOW()
                WHEN {$table}.status = 'Da contattare' THEN TRUE
                ELSE COALESCE(({$last}), {$table}.created_at) < NOW() - (?::int * INTERVAL '1 day')
            END", [$days]);
    }

    public function needsCallback(?int $contactDays = null): bool
    {
        return static::query()->withoutGlobalScopes()->whereKey($this->getKey())->needsCallback($contactDays)->exists();
    }
}
