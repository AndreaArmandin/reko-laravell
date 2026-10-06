<?php

namespace App\Models;

use App\Gestionale\ContactKeys;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Person or company known to an agency: a client (has a ClientProfile), an owner
 * (ownerships, later phase) or both. No automatic merge between the two: clients are
 * deduplicated by phone/email, owners by tax code, and only an admin merges with audit.
 * Never physically deleted: removed_at is the logical delete.
 */
class Contact extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    public const ORIGINS = ['manual', 'sister', 'legacy_import'];

    protected $table = 'contacts';

    protected $guarded = ['id'];

    /** Same defaults as the database, so a new model is complete before refresh(). */
    protected $attributes = [
        'tags' => '[]',
        'origin' => 'manual',
    ];

    protected static function booted(): void
    {
        static::saving(function (Contact $contact): void {
            $contact->display_name = trim((string) $contact->display_name);
            $contact->name_key = ContactKeys::name($contact->display_name);
            $contact->tax_code = ContactKeys::taxCode($contact->tax_code);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ContactChannel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class);
    }

    /**
     * @return HasMany<PropertyRequest, $this>
     */
    public function propertyRequests(): HasMany
    {
        return $this->hasMany(PropertyRequest::class);
    }

    /**
     * @return HasOne<ClientProfile, $this>
     */
    public function clientProfile(): HasOne
    {
        return $this->hasOne(ClientProfile::class);
    }

    /**
     * Main phone (the gestionale client card has one phone and one email).
     *
     * @return HasOne<ContactChannel, $this>
     */
    public function primaryPhone(): HasOne
    {
        return $this->hasOne(ContactChannel::class)->where('kind', 'phone')->where('is_primary', true);
    }

    /**
     * @return HasOne<ContactChannel, $this>
     */
    public function primaryEmail(): HasOne
    {
        return $this->hasOne(ContactChannel::class)->where('kind', 'email')->where('is_primary', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * Contacts a membership may see (port of permissions.ts visibleEntities for clients):
     * admin all, crm only clients it is the referent of, scout none (owners come with the
     * scouting phase, through assigned parcels).
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeVisibleTo(Builder $query, ?AgencyMembership $membership): void
    {
        if ($membership === null || ! $membership->isActive()) {
            $query->whereRaw('false');

            return;
        }

        $query->where($query->qualifyColumn('agency_id'), $membership->agency_id);

        match ($membership->role) {
            'admin' => null,
            'crm' => $query->whereHas('clientProfile', fn (Builder $profile) => $profile->where('agent_user_id', $membership->user_id)),
            default => $query->whereRaw('false'),
        };
    }

    /**
     * Clients that might be the same person (identity.ts contactMatches): same primary phone,
     * primary email or name. Only contacts WITH a client profile (archived and removed included),
     * across the whole current agency, never limited to what the operator can see.
     * An empty key never matches.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopePossibleDuplicates(Builder $query, ?string $name, ?string $phone, ?string $email): void
    {
        $phoneKey = ContactKeys::phone($phone);
        $emailKey = ContactKeys::email($email);
        $nameKey = ContactKeys::name($name);

        $query->whereHas('clientProfile')->where(function (Builder $match) use ($phoneKey, $emailKey, $nameKey) {
            $match->whereRaw('false');

            if ($nameKey !== '') {
                $match->orWhere($match->qualifyColumn('name_key'), $nameKey);
            }

            foreach (['phone' => $phoneKey, 'email' => $emailKey] as $kind => $key) {
                if ($key !== '') {
                    $match->orWhereHas('channels', fn (Builder $c) => $c->where('kind', $kind)->where('is_primary', true)->where('normalized_value', $key));
                }
            }
        });
    }
}
