<?php

namespace App\Trova;

/**
 * Cadastral category rules ported from Trova (lib/crm/cadastral-search.ts,
 * lib/corporate-categories.ts). Search visibility is separate from archive
 * eligibility: B and E records are kept but never shown in a search.
 */
final class Categories
{
    /**
     * Search groups and the measure their consistency is expressed in.
     *
     * @var array<string, array{label: string, unit: string|null}>
     */
    public const GROUPS = [
        'A' => ['label' => 'Abitazioni · A/1–A/9', 'unit' => 'vani'],
        'A10' => ['label' => 'Uffici direzionali · A/10', 'unit' => 'vani'],
        'D' => ['label' => 'Commerciale, industriale e produttivo · D', 'unit' => null],
        'C1' => ['label' => 'Negozi e botteghe · C/1', 'unit' => 'm²'],
        'C2' => ['label' => 'Magazzini · C/2', 'unit' => 'm²'],
        'C3' => ['label' => 'Laboratori · C/3', 'unit' => 'm²'],
        'C4' => ['label' => 'Categoria C/4', 'unit' => 'm²'],
        'C6' => ['label' => 'Categoria C/6', 'unit' => 'm²'],
        'C7' => ['label' => 'Categoria C/7', 'unit' => 'm²'],
        'B' => ['label' => 'Categorie B', 'unit' => 'm³'],
        'E' => ['label' => 'Categorie E', 'unit' => null],
        'F' => ['label' => 'Categorie F', 'unit' => null],
    ];

    /** @var list<string> */
    public const CATALOG = [
        'A/1', 'A/2', 'A/3', 'A/4', 'A/5', 'A/6', 'A/7', 'A/8', 'A/9', 'A/10',
        'B/1', 'B/2', 'B/4', 'B/5', 'B/6', 'B/7',
        'C/1', 'C/2', 'C/3', 'C/4', 'C/6', 'C/7',
        'D/1', 'D/2', 'D/3', 'D/4', 'D/5', 'D/6', 'D/7', 'D/8', 'D/10',
        'E/0', 'E/1', 'E/3', 'E/7', 'E/8', 'E/9',
        'F/1', 'F/2', 'F/3', 'F/4', 'F/5', 'F/6', 'F/7',
    ];

    /**
     * Presentation only: names from Trova (lib/property-type-labels.ts) and, for A/1–A/9,
     * the official cadastral definitions (gazzettaufficiale.it/catasto/help/categoria).
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'A/1' => 'Abitazione signorile', 'A/2' => 'Abitazione civile', 'A/3' => 'Abitazione economica',
        'A/4' => 'Abitazione popolare', 'A/5' => 'Abitazione ultrapopolare', 'A/6' => 'Abitazione rurale',
        'A/7' => 'Villino', 'A/8' => 'Villa', 'A/9' => 'Castello o palazzo storico',
        'A/10' => 'Ufficio o studio', 'C/1' => 'Negozio o punto vendita', 'C/2' => 'Magazzino o deposito',
        'C/3' => 'Laboratorio', 'C/4' => 'Spazio sportivo senza fine di lucro',
        'C/5' => 'Stabilimento balneare o termale senza fine di lucro',
        'C/6' => 'Box, rimessa o autorimessa', 'C/7' => 'Tettoia',
        'D/1' => 'Opificio', 'D/2' => 'Albergo o pensione', 'D/3' => 'Cinema, teatro o sala spettacoli',
        'D/4' => 'Casa di cura o ospedale con fine di lucro', 'D/5' => 'Istituto bancario o assicurativo',
        'D/6' => 'Impianto sportivo con fine di lucro', 'D/7' => 'Fabbricato per attività industriali',
        'D/8' => 'Fabbricato per attività commerciali', 'D/9' => 'Struttura galleggiante, sospesa o ponte privato',
        'D/10' => 'Fabbricato produttivo agricolo',
        'B/1' => 'Collegio, convitto o struttura collettiva', 'B/2' => 'Casa di cura o ospedale senza fine di lucro',
        'B/3' => 'Istituto penitenziario', 'B/4' => 'Ufficio pubblico', 'B/5' => 'Scuola o laboratorio scientifico',
        'B/6' => 'Biblioteca, museo o spazio culturale', 'B/7' => 'Cappella o oratorio non aperto al culto pubblico',
        'B/8' => 'Deposito sotterraneo di derrate',
        'F/1' => 'Area urbana', 'F/2' => 'Immobile collabente', 'F/3' => 'Immobile in costruzione',
        'F/4' => 'Immobile in corso di definizione', 'F/5' => 'Lastrico solare',
        'F/6' => 'Fabbricato in attesa di dichiarazione', 'F/7' => 'Infrastruttura di comunicazione pubblica',
    ];

    /** @var list<string> */
    public const BUSINESS_GROUPS = ['A10', 'C1', 'C2', 'C3', 'C4', 'C6', 'C7', 'D', 'F'];

    /**
     * Business search types offered by Trova.
     *
     * @var array<string, array{label: string, groups: list<string>}>
     */
    public const BUSINESS_TYPES = [
        'all' => ['label' => 'Tutti gli immobili per il lavoro', 'groups' => self::BUSINESS_GROUPS],
        'office' => ['label' => 'Uffici', 'groups' => ['A10']],
        'shop' => ['label' => 'Negozi e botteghe', 'groups' => ['C1']],
        'storage' => ['label' => 'Magazzini', 'groups' => ['C2']],
        'workshop' => ['label' => 'Laboratori', 'groups' => ['C3']],
        'production' => ['label' => 'Commerciale, industriale e produttivo', 'groups' => ['D']],
        'other' => ['label' => 'Altri spazi e immobili da sviluppare', 'groups' => ['C4', 'C6', 'C7', 'F']],
    ];

    /**
     * "a 02", "A/2" and "A2" all become "A/2". Anything else becomes "".
     */
    public static function normalize(string $value): string
    {
        if (! preg_match('/^([A-F])\s*\/?\s*0*(\d{1,2})$/', strtoupper(trim($value)), $match)) {
            return '';
        }

        return $match[1].'/'.(int) $match[2];
    }

    /**
     * Readable name of a category, e.g. "A/2" → "Abitazione civile".
     */
    public static function label(string $value): string
    {
        return self::LABELS[self::normalize($value)] ?? 'Immobile · tipologia non specificata';
    }

    public static function group(string $value): string
    {
        $category = self::normalize($value);

        return match (true) {
            (bool) preg_match('/^A\/[1-9]$/', $category) => 'A',
            $category === 'A/10' => 'A10',
            (bool) preg_match('/^D\/[1-9]\d?$/', $category) => 'D',
            (bool) preg_match('/^C\/\d+$/', $category) => str_replace('/', '', $category),
            (bool) preg_match('/^[BEF]\/\d+$/', $category) => $category[0],
            default => '',
        };
    }

    /**
     * Measure of a single category: m², vani, m³ or null when it has none.
     */
    public static function dimensionUnit(string $category): ?string
    {
        return match (true) {
            str_starts_with($category, 'C/') || in_array($category, ['F/1', 'F/5'], true) => 'm²',
            str_starts_with($category, 'A/') => 'vani',
            str_starts_with($category, 'B/') => 'm³',
            default => null,
        };
    }

    /**
     * Groups a search path may ever show. Null means no fixed scope.
     *
     * @return list<string>|null
     */
    public static function scopeGroups(?string $segment, ?string $housing = null): ?array
    {
        return match ($segment) {
            'private' => [$housing === 'garage' ? 'C6' : 'A'],
            'corporate' => ['A'],
            'business' => self::BUSINESS_GROUPS,
            default => null,
        };
    }

    public static function isVisible(string $category, ?string $segment = null, ?string $housing = null): bool
    {
        $normalized = self::normalize($category);
        $scope = self::scopeGroups($segment, $housing);

        return $normalized !== ''
            && ! preg_match('/^[BE]\//', $normalized)
            && ($scope === null || in_array(self::group($normalized), $scope, true));
    }

    /**
     * Catalogue categories a user can pick for these groups (B and E never).
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    public static function selectable(array $groups): array
    {
        return array_values(array_filter(
            self::CATALOG,
            fn (string $category) => ! preg_match('/^[BE]\//', $category) && in_array(self::group($category), $groups, true),
        ));
    }
}
