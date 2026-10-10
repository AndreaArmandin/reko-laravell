<?php

namespace App\Gestionale\Properties;

use App\Gestionale\CommandRejected;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Gestionale\Questionnaire\Tags;

/**
 * Campi della scheda immobile (properties.tsx: labels, PropertyValue, base / extra / commercial).
 * Una sola fonte per il form, la scheda e la whitelist di SaveProperty: `features` arriva dal client
 * (Livewire permette di alterare gli array), quindi solo queste chiavi, con questi tipi, vengono salvate.
 */
final class PropertyFields
{
    /** properties.tsx labels */
    public const LABELS = [
        'operation' => 'Contratto', 'purpose' => 'Finalità compatibili', 'typology' => 'Tipologia',
        'price' => 'Prezzo / canone mensile (€)', 'area' => 'Superficie commerciale (m²)', 'walkableArea' => 'Superficie calpestabile (m²)',
        'fees' => 'Spese mensili (€)', 'rooms' => 'Locali', 'bedrooms' => 'Camere', 'bathrooms' => 'Bagni', 'floor' => 'Piano',
        'totalFloors' => 'Piani dell’edificio', 'year' => 'Anno di costruzione', 'availableFrom' => 'Disponibilità dal',
        'energyIndex' => 'Indice energetico', 'energy' => 'Classe energetica', 'occupancy' => 'Stato di occupazione',
        'yieldEstimate' => 'Rendimento indicativo (%)', 'cadastralCategory' => 'Categoria catastale', 'elevator' => 'Ascensore',
        'garage' => 'Garage / box', 'garden' => 'Giardino', 'balcony' => 'Balcone', 'terrace' => 'Terrazzo', 'parking' => 'Posto auto',
        'cellar' => 'Cantina', 'accessible' => 'Accesso senza barriere', 'furnished' => 'Arredato', 'heating' => 'Riscaldamento',
        'airConditioning' => 'Climatizzazione', 'pets' => 'Animali ammessi (locazione)', 'condition' => 'Stato manutentivo',
    ];

    public const BASE = ['purpose', 'typology', 'price', 'area', 'walkableArea', 'fees', 'availableFrom', 'occupancy'];

    public const EXTRA = ['rooms', 'bedrooms', 'bathrooms', 'floor', 'totalFloors', 'year', 'energy', 'energyIndex', 'condition', 'heating',
        'elevator', 'balcony', 'terrace', 'garden', 'garage', 'parking', 'cellar', 'furnished', 'airConditioning', 'accessible', 'pets', 'services'];

    public const COMMERCIAL = ['activity', 'catchment', 'visibility', 'traffic', 'windows', 'streetEntrance', 'loading', 'height', 'warehouse',
        'systems', 'flue', 'cadastralCategory', 'yieldEstimate', 'investmentStrategy', 'management', 'landUse', 'roadAccess', 'utilities',
        'coveredParking', 'charging'];

    /** Campi numerici (PropertyValue `numeric`). */
    public const NUMERIC = ['price', 'area', 'walkableArea', 'fees', 'rooms', 'bedrooms', 'bathrooms', 'floor', 'totalFloors', 'year',
        'energyIndex', 'yieldEstimate', 'windows', 'height'];

    /** Numeri che l'engine valida come non negativi (engine.ts property.save). */
    public const NON_NEGATIVE = ['price', 'area', 'walkableArea', 'fees', 'rooms', 'bedrooms', 'bathrooms'];

    /** Scelte multiple a caselle (PropertyValue). */
    public const CHECKBOXES = ['purpose', 'services', 'activity', 'catchment', 'traffic', 'systems', 'investmentStrategy', 'management'];

    /** Campi sì / no / da verificare (domande di tipo boolean). */
    public const BOOLEAN = ['elevator', 'balcony', 'terrace', 'garden', 'garage', 'parking', 'cellar', 'furnished', 'accessible', 'pets',
        'airConditioning', 'streetEntrance', 'loading', 'warehouse', 'flue', 'roadAccess', 'coveredParking', 'charging'];

    public const OCCUPANCY = ['Libero', 'Locato', 'Occupato dal proprietario'];

    /** @return list<string> */
    public static function allKeys(): array
    {
        return array_values(array_unique([...['operation', 'tags'], ...self::BASE, ...self::EXTRA, ...self::COMMERCIAL]));
    }

    /** Elenco unico delle tipologie (Object.values(typologies).flat()). */
    public static function typologies(): array
    {
        return Questionnaire::quickTypologies();
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        return array_keys(Questionnaire::PURPOSE_PATHS);
    }

    /**
     * Come si compila un campo (PropertyValue): label, tipo di controllo e opzioni.
     *
     * @param  array<string, mixed>|null  $question  domanda del questionario con `field` = $id
     * @return array{id: string, label: string, kind: string, options: list<string>}
     */
    public static function spec(string $id, ?array $question): array
    {
        $label = self::LABELS[$id] ?? (isset($question['text']) ? preg_replace('/\?$/', '', (string) $question['text']) : $id);
        $numeric = in_array($id, self::NUMERIC, true) || in_array($question['type'] ?? null, ['amount', 'range'], true);
        $options = $id === 'typology' ? self::typologies()
            : ($id === 'purpose' ? self::purposes()
            : ($id === 'occupancy' ? self::OCCUPANCY
            : ($question['options'] ?? null)));

        $kind = match (true) {
            ($question['type'] ?? null) === 'boolean' || ($question === null && in_array($id, self::BOOLEAN, true)) => 'boolean',
            $options !== null && in_array($id, self::CHECKBOXES, true) => 'checks',
            $options !== null => 'select',
            $numeric => 'number',
            $id === 'availableFrom' => 'date',
            default => 'text',
        };

        return ['id' => $id, 'label' => (string) $label, 'kind' => $kind, 'options' => array_values($options ?? [])];
    }

    /** Etichetta di un campo in scheda: labels, poi il testo della domanda, poi la chiave. */
    public static function label(string $id, ?array $question = null): string
    {
        return self::LABELS[$id] ?? ($question['text'] ?? $id);
    }

    /**
     * Whitelist e tipi di `features` (non fidarsi dell'array che arriva dal client).
     * Le chiavi non previste sono scartate; i valori vuoti diventano "da verificare" (chiave assente).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::allKeys() as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'operation') {
                $out[$key] = is_string($value) ? $value : '';
            } elseif ($key === 'tags') {
                $out[$key] = Tags::validate($value);
            } elseif (in_array($key, self::NUMERIC, true)) {
                if (is_string($value)) {
                    $value = str_replace(',', '.', trim($value));
                }
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    throw new CommandRejected('Controlla il campo '.$key.': inserisci un numero valido.', 400, 'features.'.$key);
                }
                $value = $value + 0;
                if (in_array($key, self::NON_NEGATIVE, true) && $value < 0) {
                    throw new CommandRejected('Controlla il campo '.$key.': inserisci un numero valido.', 400, 'features.'.$key);
                }
                $out[$key] = $value;
            } elseif (in_array($key, self::BOOLEAN, true)) {
                $bool = match (true) {
                    $value === true, $value === 'yes', $value === '1', $value === 1 => true,
                    $value === false, $value === 'no', $value === '0', $value === 0 => false,
                    default => null,
                };
                if ($bool !== null) {
                    $out[$key] = $bool;
                }
            } elseif (in_array($key, self::CHECKBOXES, true)) {
                $list = is_array($value) ? $value : [$value];
                $list = array_values(array_unique(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), array_filter($list, 'is_scalar'))));
                $list = array_values(array_filter($list, fn ($v) => $v !== ''));
                if ($list !== []) {
                    $out[$key] = array_slice($list, 0, 30);
                }
            } else {
                $text = is_scalar($value) ? mb_substr(trim((string) $value), 0, 200) : '';
                if ($text !== '') {
                    $out[$key] = $text;
                }
            }
        }

        return $out;
    }
}
