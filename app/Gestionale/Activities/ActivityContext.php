<?php

namespace App\Gestionale\Activities;

use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Contact;
use App\Models\Parcel;
use App\Models\Property;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The record an activity block belongs to (activity.ts ActivityContext): client, request, property,
 * owner, parcel and units. Only the keys that are set filter the activities.
 *
 * @phpstan-type Context array{contact_id?: int, property_request_id?: int, property_id?: int, owner_contact_id?: int, parcel_id?: int, unit_ids?: list<int>}
 */
final class ActivityContext
{
    public const KEYS = ['contact_id', 'property_request_id', 'property_id', 'owner_contact_id', 'parcel_id'];

    /**
     * Context of a record. A request links its client too (RequestRow: {clientId, requestId}).
     * A contact is the client when it has a client profile, the owner otherwise (or when $asOwner).
     *
     * @return Context
     */
    public static function fromSubject(Model $subject, bool $asOwner = false, array $units = []): array
    {
        $context = match (true) {
            $subject instanceof PropertyRequest => ['contact_id' => (int) $subject->contact_id, 'property_request_id' => (int) $subject->getKey()],
            $subject instanceof Property => ['property_id' => (int) $subject->getKey()],
            $subject instanceof Parcel => ['parcel_id' => (int) $subject->getKey()],
            $subject instanceof Contact => $asOwner || ! $subject->clientProfile()->exists()
                ? ['owner_contact_id' => (int) $subject->getKey()]
                : ['contact_id' => (int) $subject->getKey()],
            default => [],
        };
        if ($units !== []) {
            $context['unit_ids'] = array_values(array_map('intval', $units));
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    public static function normalize(array $context): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (! empty($context[$key])) {
                $out[$key] = (int) $context[$key];
            }
        }
        if (! empty($context['unit_ids'])) {
            $out['unit_ids'] = array_values(array_map('intval', $context['unit_ids']));
        }

        return $out;
    }

    /**
     * activity.ts activitiesForContext(), limited to what the actor can see.
     *
     * @param  Context  $context
     * @return Collection<int, Activity>
     */
    public static function activities(AgencyMembership $actor, array $context): Collection
    {
        $context = self::normalize($context);
        if (array_diff_key($context, ['unit_ids' => 1]) === []) {
            return new Collection;
        }

        return app(ActivityAccess::class)->visible($actor, function (Builder $query) use ($context) {
            foreach (self::KEYS as $key) {
                if (isset($context[$key])) {
                    $query->where($key, $context[$key]);
                }
            }
            foreach ($context['unit_ids'] ?? [] as $unitId) {
                $query->whereHas('units', fn (Builder $u) => $u->where('cadastral_unit_id', $unitId));
            }
        });
    }

    /**
     * activity.ts activityContextSummary(): last done activity and next open one.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array{last: ?Activity, lastAt: ?\Carbon\CarbonInterface, next: ?Activity}
     */
    public static function summary(Collection $activities): array
    {
        $linked = $activities->filter(fn (Activity $a) => $a->status !== 'Annullata');
        $completedAt = fn (Activity $a) => $a->completed_at ?? $a->outcome_confirmed_at;
        $last = $linked->filter(fn (Activity $a) => $a->isDone())
            ->sort(fn (Activity $a, Activity $b) => (($completedAt($b)?->getTimestamp() ?? 0) <=> ($completedAt($a)?->getTimestamp() ?? 0)) ?: $a->id <=> $b->id)->first();
        $next = $linked->filter(fn (Activity $a) => ! ActivityCatalog::suspendedAcquisition($a) && ! $a->isDone() && $a->scheduled_at !== null)
            ->sort(fn (Activity $a, Activity $b) => ($a->scheduled_at->getTimestamp() <=> $b->scheduled_at->getTimestamp()) ?: $a->id <=> $b->id)->first();

        return ['last' => $last, 'lastAt' => $last ? $completedAt($last) : null, 'next' => $next];
    }

    /**
     * activity-form.tsx banner "Collegamento automatico": what the form is linked to.
     *
     * @param  Context  $context
     * @return array{heading: string, text: ?string, address: ?string, client: ?string}|null
     */
    public static function banner(array $context): ?array
    {
        $context = self::normalize($context);
        $property = ! empty($context['property_id']) ? Property::query()->find($context['property_id']) : null;
        $parcel = ! empty($context['parcel_id']) ? Parcel::query()->find($context['parcel_id']) : null;
        $request = ! empty($context['property_request_id']) ? PropertyRequest::query()->with('contact')->find($context['property_request_id']) : null;
        $owner = ! empty($context['owner_contact_id']) ? Contact::query()->find($context['owner_contact_id']) : null;
        $client = ! empty($context['contact_id']) ? Contact::query()->find($context['contact_id']) : $request?->contact;

        $units = $parcel && ! empty($context['unit_ids']) ? CadastralUnit::query()->where('parcel_id', $parcel->id)->whereIn('id', $context['unit_ids'])->get() : collect();
        $text = $property ? $property->title
            : ($parcel ? ($units->isNotEmpty() ? $units->map(fn ($u) => self::parcelLabel($parcel, $u))->implode(' · ') : self::parcelLabel($parcel))
            : ($request ? $request->title : ($owner ? $owner->display_name : $client?->display_name)));
        if ($text === null || $text === '') {
            return null;
        }

        return [
            'heading' => $property || $parcel ? 'Immobile collegato' : ($request ? 'Richiesta collegata' : ($owner ? 'Proprietario collegato' : 'Cliente collegato')),
            'text' => $text,
            'address' => null,
            'client' => $request && $client ? $client->display_name : null,
        ];
    }

    /** census-model.ts cadastralReference() */
    public static function parcelLabel(Parcel $parcel, ?CadastralUnit $unit = null): string
    {
        $label = ($parcel->section !== '' && $parcel->section !== '_' ? 'Sez. '.$parcel->section.' · ' : '').'F. '.$parcel->sheet.' · P. '.$parcel->number;

        return $unit ? $label.' · Sub. '.($unit->subalterno ?: '—') : $label;
    }
}
