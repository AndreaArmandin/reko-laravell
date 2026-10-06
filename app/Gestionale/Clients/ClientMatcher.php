<?php

namespace App\Gestionale\Clients;

use App\Gestionale\ContactKeys;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * identity.ts contactMatches(): clients of the WHOLE current agency (archived and removed included,
 * not only those visible to the operator) with the same primary phone, primary email or name.
 * Callers never show data of clients the operator cannot see.
 */
final class ClientMatcher
{
    /** @return Collection<int, ClientMatch> */
    public static function matches(?string $name, ?string $phone, ?string $email, ?int $exceptId = null): Collection
    {
        $phoneKey = ContactKeys::phone($phone);
        $emailKey = ContactKeys::email($email);
        $nameKey = ContactKeys::name($name);

        return Contact::query()
            ->possibleDuplicates($name, $phone, $email)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->with(['primaryPhone', 'primaryEmail', 'clientProfile'])
            ->orderBy('id')
            ->get()
            ->map(fn (Contact $c) => new ClientMatch(
                $c,
                $phoneKey !== '' && $c->primaryPhone?->normalized_value === $phoneKey,
                $emailKey !== '' && $c->primaryEmail?->normalized_value === $emailKey,
                $nameKey !== '' && $c->name_key === $nameKey,
            ))
            ->values();
    }
}
