<?php

namespace App\Gestionale\Census;

use App\Models\AgencyMembership;
use Collator;

/**
 * queryCensus() di census-query.ts: filtri, ricerca libera, ordinamento e pagine dell'Archivio catastale,
 * per Immobili e per Proprietari. Il Comune (o un riferimento preciso) è sempre obbligatorio per non caricare l'intero archivio.
 */
final class CensusQuery
{
    public const OUTCOMES_NEW = ['Interessato', 'Non interessato', 'Informazione', 'Informatore', 'Fissato appuntamento', 'Non risponde', 'Numero sbagliato/inesistente', 'Trovare sul posto', 'Preso incarico', 'Sceso prezzo'];

    public const MODES = ['catastale', 'frazione', 'indirizzo', 'proprietario'];

    /** Filtri rimossi su richiesta del cliente; anche le vecchie chiamate non devono applicarli. */
    public const REMOVED_FILTERS = ['section', 'zone', 'geometry', 'source', 'state', 'operator'];

    public const MODE_FIELDS = [
        'catastale' => ['sheet', 'parcel', 'sub', 'withoutSub', 'category'],
        'frazione' => ['locality'],
        'indirizzo' => ['address', 'civic', 'floor'],
        'proprietario' => ['name', 'lastName', 'cf'],
    ];

    /** censusOutcomes di census-filter-options.ts */
    public static function outcomes(): array
    {
        return array_values(array_unique([...self::OUTCOMES_NEW, 'Nessuna risposta', 'Numero non valido', 'Non è il proprietario', 'Da richiamare', 'Non interessato',
            'Informazione ricevuta', 'Potenziale', 'Appuntamento fissato', 'Appuntamento di acquisizione fissato', 'Incarico acquisito']));
    }

    /** filtersForCensusMode(): i campi delle altre modalità vengono tolti. */
    public static function filtersForMode(array $filters, string $mode): array
    {
        $next = $filters;
        $next['_mode'] = [$mode];
        foreach (self::MODE_FIELDS as $key => $fields) {
            if ($key !== $mode) {
                foreach ($fields as $field) {
                    unset($next[$field]);
                }
            }
        }

        return $next;
    }

    public function __construct(private readonly AgencyMembership $m) {}

    /**
     * @param  array{view?:string,search?:string,filters?:array<string,list<string>>,page?:int,size?:int,order?:string,firstOutcome?:string,after?:?array<string,mixed>,parcelId?:?int,ownerId?:?int,unitId?:?int}  $q
     * @return array{page:int,size:int,total:int,items:list<array<string,mixed>>,people:list<array<string,mixed>>,options:array<string,list<string>>,nextCursor:?array<string,mixed>}
     */
    public function run(array $q): array
    {
        return (new ArchiveListing($this->m))->run($q);
    }

    public static function active(array $unit): bool
    {
        return ($unit['state'] ?: 'Attivo') === 'Attivo' && empty($unit['removed']);
    }

    public static function reference(array $c, ?string $sub = null): string
    {
        return ($c['section'] !== '' && $c['section'] !== '_' ? 'Sez. '.$c['section'].' · ' : '').'F. '.$c['sheet'].' · P. '.$c['parcel'].($sub !== null ? ' · Sub. '.($sub !== '' ? $sub : '—') : '');
    }

    public static function collate(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(Collator::class) ? tap(new Collator('it'), fn ($c) => $c->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON)) : false;

        return $collator ? (int) $collator->compare($a, $b) : strnatcasecmp($a, $b);
    }
}
