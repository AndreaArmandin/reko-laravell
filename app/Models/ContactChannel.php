<?php

namespace App\Models;

use App\Gestionale\ContactKeys;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ContactChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phone or email of a contact, with the gestionale phone status and a normalized key
 * (TS phoneKey/emailKey) used for duplicate warnings.
 */
class ContactChannel extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<ContactChannelFactory> */
    use HasFactory;

    public const KINDS = ['phone', 'email'];

    public const STATUSES = ['Da verificare', 'Verificato', 'Errato'];

    protected $table = 'contact_channels';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'Da verificare',
        'is_primary' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (ContactChannel $channel): void {
            $channel->value = trim((string) $channel->value);
            $channel->normalized_value = ContactKeys::channel((string) $channel->kind, $channel->value);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
