<?php

namespace App\Gestionale\Activities;

use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Contact;
use App\Models\Ownership;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\PropertyUnit;
use App\Models\ScoutingAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * permissions.ts linksVisible() for ONE person: which clients, requests, properties,
 * parcels, units and owners that person can reach. Answers are memoized per instance.
 *
 * admin: everything of the agency; crm: own clients/requests/properties, and the parcels
 * of its properties; scout: no clients/requests/properties, only the parcels assigned to it.
 * Owners (contacts) are reachable through the ownerships of a reachable parcel or unit.
 */
final class ActivityLinks
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly AgencyMembership $person) {}

    /**
     * @param  array{contact_id?: ?int, property_request_id?: ?int, property_id?: ?int, owner_contact_id?: ?int, parcel_id?: ?int, unit_ids?: list<int>}  $links
     */
    public function visible(array $links): bool
    {
        if (! $this->person->isActive()) {
            return false;
        }
        if (! empty($links['contact_id']) && ! $this->client((int) $links['contact_id'])) {
            return false;
        }
        if (! empty($links['property_request_id']) && ! $this->request((int) $links['property_request_id'])) {
            return false;
        }
        if (! empty($links['property_id']) && ! $this->property((int) $links['property_id'])) {
            return false;
        }
        if (! empty($links['parcel_id']) && ! $this->parcel((int) $links['parcel_id'])) {
            return false;
        }
        if (! empty($links['owner_contact_id']) && ! $this->owner((int) $links['owner_contact_id'], ! empty($links['parcel_id']) ? (int) $links['parcel_id'] : null)) {
            return false;
        }
        $units = array_map('intval', $links['unit_ids'] ?? []);
        if ($units !== [] && (empty($links['parcel_id']) || array_filter($units, fn (int $id) => ! $this->unit($id, (int) $links['parcel_id'])) !== [])) {
            return false;
        }

        return true;
    }

    public function client(int $id): bool
    {
        return $this->memo['c'.$id] ??= Contact::query()->visibleTo($this->person)->whereKey($id)->whereHas('clientProfile')->exists();
    }

    public function request(int $id): bool
    {
        return $this->memo['r'.$id] ??= PropertyRequest::query()->visibleTo($this->person)->whereKey($id)->exists();
    }

    public function property(int $id): bool
    {
        return $this->memo['p'.$id] ??= Property::query()->visibleTo($this->person)->whereKey($id)->exists();
    }

    public function parcel(int $id): bool
    {
        return $this->memo['a'.$id] ??= match ($this->person->role) {
            'admin' => true,
            'scout' => $this->assignedParcels()->contains($id),
            default => $this->propertyParcels()->contains($id),
        };
    }

    public function unit(int $unitId, int $parcelId): bool
    {
        if (isset($this->memo["u{$unitId}:{$parcelId}"])) {
            return $this->memo["u{$unitId}:{$parcelId}"];
        }
        $belongs = CadastralUnit::query()->whereKey($unitId)->where('parcel_id', $parcelId)->exists();
        $reachable = $belongs && match ($this->person->role) {
            'admin' => true,
            'scout' => ScoutingAssignment::query()->where('user_id', $this->person->user_id)
                ->where(fn (Builder $q) => $q->where('cadastral_unit_id', $unitId)->orWhere('parcel_id', $parcelId))->exists(),
            default => PropertyUnit::query()->where('cadastral_unit_id', $unitId)
                ->whereHas('property', fn (Builder $p) => $p->visibleTo($this->person))->exists(),
        };

        return $this->memo["u{$unitId}:{$parcelId}"] = $reachable;
    }

    public function owner(int $contactId, ?int $parcelId): bool
    {
        $key = "o{$contactId}:{$parcelId}";
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $exists = Contact::query()->where('agency_id', $this->person->agency_id)->whereKey($contactId)->exists();
        $reachable = $exists && ($this->person->role === 'admin'
            || Ownership::query()->where('contact_id', $contactId)
                ->when($parcelId, fn (Builder $q) => $q->where('parcel_id', $parcelId))
                ->where(function (Builder $q) {
                    $parcels = $this->person->role === 'scout' ? $this->assignedParcels()->all() : $this->propertyParcels()->all();
                    $q->whereIn('parcel_id', $parcels)
                        ->orWhereHas('cadastralUnit', fn (Builder $u) => $u->whereIn('parcel_id', $parcels));
                })->exists());

        return $this->memo[$key] = $reachable;
    }

    /** @return Collection<int, int> */
    private function assignedParcels(): Collection
    {
        return $this->memo['__assigned'] ??= ScoutingAssignment::query()->where('user_id', $this->person->user_id)->get(['parcel_id', 'cadastral_unit_id'])
            ->flatMap(fn ($a) => [$a->parcel_id, $a->cadastral_unit_id ? CadastralUnit::query()->whereKey($a->cadastral_unit_id)->value('parcel_id') : null])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** @return Collection<int, int> */
    private function propertyParcels(): Collection
    {
        return $this->memo['__property'] ??= PropertyUnit::query()
            ->whereHas('property', fn (Builder $p) => $p->visibleTo($this->person))
            ->with('cadastralUnit:id,parcel_id')->get()
            ->map(fn ($u) => $u->cadastralUnit?->parcel_id)->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }
}
