<?php

namespace App\Gestionale\Properties;

use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collegamenti manuali ed espliciti di un immobile a portafoglio (SearchableLinks in properties.tsx):
 * unità catastali e proprietari che chi salva può vedere (permissions.ts visibleEntities).
 * - Responsabile: tutte le unità del catalogo e tutti i proprietari dell'agenzia;
 * - Segreteria: soltanto ciò che è già collegato ai propri immobili.
 */
final class PropertyLinks
{
    public const OWNER_ROLE = 'Proprietario';

    /** Righe massime mostrate per ricerca: i collegamenti selezionati si vedono sempre. */
    public const LIMIT = 50;

    public const CAP = 1000;

    /** @param Builder<CadastralUnit> $query */
    private static function unitSearchable(Builder $query): Builder
    {
        return $query->join('parcels', 'parcels.id', '=', 'cadastral_units.parcel_id')
            ->join('municipalities', 'municipalities.id', '=', 'parcels.municipality_id')
            ->leftJoin(DB::raw('(SELECT DISTINCT ON (cadastral_unit_id) cadastral_unit_id, category, address_raw
                FROM cadastral_unit_versions ORDER BY cadastral_unit_id, catalog_release_id DESC, id DESC) uv'), 'uv.cadastral_unit_id', '=', 'cadastral_units.id')
            ->select(['cadastral_units.id', 'cadastral_units.subalterno', 'parcels.sheet', 'parcels.number', 'municipalities.name as municipality',
                'uv.category', 'uv.address_raw']);
    }

    /** Ids delle unità che la Segreteria può già vedere: quelle dei propri immobili. */
    private static function ownUnitIds(AgencyMembership $actor): array
    {
        return DB::table('property_units')->join('properties', 'properties.id', '=', 'property_units.property_id')
            ->where('properties.agency_id', $actor->agency_id)->where('properties.agent_user_id', $actor->user_id)
            ->whereNotNull('property_units.cadastral_unit_id')->pluck('property_units.cadastral_unit_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private static function ownOwnerIds(AgencyMembership $actor): array
    {
        return PropertyContact::query()->withoutGlobalScopes()->where('property_contacts.agency_id', $actor->agency_id)
            ->where('role', self::OWNER_ROLE)
            ->whereIn('property_id', Property::query()->withoutGlobalScopes()->where('agency_id', $actor->agency_id)->where('agent_user_id', $actor->user_id)->select('id'))
            ->pluck('contact_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> gli id che la ricerca non può usare ("Unità catastale non accessibile.")
     */
    public static function inaccessibleUnits(AgencyMembership $actor, array $ids, ?Property $property): array
    {
        if ($ids === []) {
            return [];
        }
        $existing = CadastralUnit::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_diff($ids, $existing);
        if ($actor->isAdmin()) {
            return array_values($missing);
        }
        $allowed = array_merge(self::ownUnitIds($actor), $property ? self::currentUnitIds($property) : []);

        return array_values(array_unique([...$missing, ...array_diff($ids, $allowed)]));
    }

    /** @return list<int> */
    public static function currentUnitIds(Property $property): array
    {
        return $property->units()->whereNotNull('cadastral_unit_id')->pluck('cadastral_unit_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    public static function currentOwnerIds(Property $property): array
    {
        return $property->contacts()->where('role', self::OWNER_ROLE)->pluck('contact_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function inaccessibleOwners(AgencyMembership $actor, array $ids, ?Property $property): array
    {
        if ($ids === []) {
            return [];
        }
        $known = Contact::query()->where('agency_id', $actor->agency_id)->whereNull('removed_at')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $keep = $property ? self::currentOwnerIds($property) : [];
        $missing = array_diff($ids, $known, $keep);
        if ($actor->isAdmin()) {
            return array_values($missing);
        }

        return array_values(array_unique([...$missing, ...array_diff($ids, self::ownOwnerIds($actor), $keep)]));
    }

    /**
     * Opzioni di "Unità catastali": le selezionate, poi le corrispondenze della ricerca (ogni termine deve comparire).
     *
     * @param  list<int>  $selected
     * @return array{options: Collection<int, array{value: string, label: string}>, matching: int, unavailable: int}
     */
    public static function unitOptions(AgencyMembership $actor, array $selected, string $query, bool $onlySelected, ?Property $property): array
    {
        $terms = self::terms($query);
        $base = fn () => self::unitSearchable(CadastralUnit::query())
            ->when(! $actor->isAdmin(), fn (Builder $q) => $q->whereIn('cadastral_units.id', array_unique([...self::ownUnitIds($actor), ...($property ? self::currentUnitIds($property) : []), ...array_map('intval', $selected)])))
            ->when($terms !== [], function (Builder $q) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                    $q->whereRaw("concat_ws(' ', municipalities.name, parcels.sheet, parcels.number, cadastral_units.subalterno, uv.category, uv.address_raw) ilike ?", [$like]);
                }
            });

        $ids = array_map('intval', $selected);
        $chosen = $ids === [] ? collect() : $base()->whereIn('cadastral_units.id', $ids)->get();
        $others = $onlySelected ? collect() : $base()->when($ids !== [], fn (Builder $q) => $q->whereNotIn('cadastral_units.id', $ids))
            ->orderBy('municipalities.name')->orderBy('parcels.sheet')->orderBy('parcels.number')->limit(self::LIMIT)->get();
        $matching = $onlySelected ? $chosen->count() : self::capped($base()->select('cadastral_units.id'));
        $found = $ids === [] ? 0 : CadastralUnit::query()->whereIn('id', $ids)->count();

        return [
            'options' => $chosen->concat($others)->map(fn ($u) => ['value' => (string) $u->id, 'label' => self::unitLabel($u)])->values(),
            'matching' => $matching, 'unavailable' => max(0, count($ids) - $found),
        ];
    }

    public static function unitLabel(object $unit): string
    {
        $address = trim((string) ($unit->address_raw ?? ''));
        $parts = [$unit->municipality, $address !== '' ? $address : null,
            'Foglio '.$unit->sheet.' · Particella '.$unit->number.($unit->subalterno ? ' · Sub '.$unit->subalterno : '').($unit->category ? ' · '.$unit->category : '')];

        return implode(' · ', array_filter($parts));
    }

    /**
     * @param  list<int>  $selected
     * @return array{options: Collection<int, array{value: string, label: string}>, matching: int, unavailable: int}
     */
    public static function ownerOptions(AgencyMembership $actor, array $selected, string $query, bool $onlySelected, ?Property $property): array
    {
        $terms = self::terms($query);
        $own = $actor->isAdmin() ? null : array_unique([...self::ownOwnerIds($actor), ...($property ? self::currentOwnerIds($property) : []), ...array_map('intval', $selected)]);
        $base = fn () => Contact::query()->where('contacts.agency_id', $actor->agency_id)->whereNull('removed_at')
            ->where(fn (Builder $q) => $q->whereIn('contacts.id', DB::table('ownerships')->where('agency_id', $actor->agency_id)->whereNotNull('contact_id')->select('contact_id'))
                ->orWhereIn('contacts.id', DB::table('property_contacts')->where('agency_id', $actor->agency_id)->where('role', self::OWNER_ROLE)->select('contact_id')))
            ->when($own !== null, fn (Builder $q) => $q->whereIn('contacts.id', $own))
            ->when($terms !== [], function (Builder $q) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                    $q->whereRaw("concat_ws(' ', contacts.display_name, contacts.tax_code) ilike ?", [$like]);
                }
            });

        $ids = array_map('intval', $selected);
        $chosen = $ids === [] ? collect() : $base()->whereIn('contacts.id', $ids)->get();
        $others = $onlySelected ? collect() : $base()->when($ids !== [], fn (Builder $q) => $q->whereNotIn('contacts.id', $ids))
            ->orderBy('contacts.display_name')->limit(self::LIMIT)->get();
        $matching = $onlySelected ? $chosen->count() : self::capped($base()->select('contacts.id'));
        $found = $ids === [] ? 0 : Contact::query()->where('agency_id', $actor->agency_id)->whereIn('id', $ids)->count();

        return [
            'options' => $chosen->concat($others)->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->display_name])->values(),
            'matching' => $matching, 'unavailable' => max(0, count($ids) - $found),
        ];
    }

    /** Conta fino a CAP + 1 righe: oltre CAP si mostra "oltre 1.000", senza contare un catalogo intero. */
    private static function capped(Builder $query): int
    {
        return DB::query()->fromSub($query->limit(self::CAP + 1)->toBase(), 'capped')->count();
    }

    /** @return list<string> */
    private static function terms(string $query): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], fn ($t) => $t !== ''));
    }
}
