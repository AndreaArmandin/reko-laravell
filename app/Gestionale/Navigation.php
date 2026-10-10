<?php

namespace App\Gestionale;

use App\Models\AgencyMembership;

/**
 * Gestionale navigation (permissions.ts sectionNames / sectionsFor / sectionLabel):
 * same sections, order, Italian labels and role visibility as the old gestionale.
 * - scout: no Clienti, Investitori, Ricerche dei clienti, Immobili a portafoglio;
 * - crm: no Zona di ricerca, Proprietari;
 * - Impostazioni: admin only.
 * Investitori stays hidden as in the current gestionale UI (data kept on the client card);
 * Pratiche di acquisizione is suspended (engine.ts 410) and excluded by Andrea.
 */
final class Navigation
{
    /** key => [label, icon, route|null] ; null is reserved for sections suspended in the source app. */
    public const SECTIONS = [
        'Panoramica' => ['Oggi', 'layout-dashboard', 'gestionale.home'],
        'Scout zone' => ['Mappa e zone', 'map', 'gestionale.scouting.index'],
        'Scouting Area' => ['Archivio catastale', 'building-2', 'gestionale.archive.index'],
        'Immobili a portafoglio' => ['Immobili a portafoglio', 'building-2', 'gestionale.properties.index'],
        'Clienti' => ['Clienti', 'users-round', 'gestionale.clients.index'],
        'Investitori' => ['Investitori', 'users-round', null],
        'Richieste' => ['Richieste', 'clipboard-list', 'gestionale.requests.index'],
        'Attività' => ['Agenda', 'calendar-days', 'gestionale.activities.index'],
        'Pratiche di acquisizione' => ['Pratiche di acquisizione', 'file-up', null],
        'Obiettivi' => ['Obiettivi', 'target', 'gestionale.goals.index'],
        'Impostazioni CRM' => ['Impostazioni', 'settings-2', 'gestionale.settings.index'],
    ];

    /** Hidden in the current gestionale UI (context.tsx / navigation.ts) or excluded. */
    public const HIDDEN = ['Investitori', 'Pratiche di acquisizione'];

    /** @return list<string> permissions.ts sectionsFor() */
    public static function sectionsFor(?AgencyMembership $membership): array
    {
        $role = $membership?->isActive() ? $membership->role : null;
        if ($role === null) {
            return [];
        }

        return array_values(array_filter(array_keys(self::SECTIONS), fn (string $s) => $role === 'admin'
            || ($s !== 'Impostazioni CRM' && ($role === 'scout'
                ? ! in_array($s, ['Clienti', 'Investitori', 'Richieste', 'Immobili a portafoglio'], true)
                : ! in_array($s, ['Scout zone', 'Scouting Area'], true)))));
    }

    /**
     * Visible sidebar items.
     *
     * @return list<array{key: string, label: string, icon: string, href: string, pattern: string}>
     */
    public static function items(?AgencyMembership $membership): array
    {
        $items = [];
        foreach (self::sectionsFor($membership) as $key) {
            if (in_array($key, self::HIDDEN, true)) {
                continue;
            }
            [$label, $icon, $route] = self::SECTIONS[$key];
            $slug = self::slug($key);
            $items[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'href' => $route ? route($route) : route('gestionale.section', $slug),
                'pattern' => $route ? str_replace('.index', '.*', $route) : 'gestionale.section',
                'slug' => $slug,
            ];
        }

        return $items;
    }

    /** @return list<array{label:string,items:list<array{key:string,label:string,icon:string,href:string,pattern:string,slug:string}>}> */
    public static function groupedItems(?AgencyMembership $membership): array
    {
        $groups = [
            'Oggi' => ['Panoramica'],
            'Persone' => ['Clienti', 'Richieste'],
            'Immobili' => ['Immobili a portafoglio', 'Scouting Area', 'Scout zone'],
            'Agenda' => ['Attività'],
            'Altri strumenti' => ['Obiettivi'],
        ];
        $items = collect(self::items($membership))->keyBy('key');
        $result = [];
        foreach ($groups as $label => $keys) {
            $entries = collect($keys)->map(fn (string $key) => $items->get($key))->filter()->values()->all();
            if ($entries !== []) {
                $result[] = ['label' => $label, 'items' => $entries];
            }
        }

        return $result;
    }

    /**
     * Section a gestionale route belongs to (breadcrumb and role gate); null for pages
     * outside the sections, like the profile choice.
     */
    public static function sectionForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        foreach (self::SECTIONS as $key => [, , $route]) {
            if ($route !== null && \Illuminate\Support\Str::is(str_replace('.index', '.*', $route), $routeName)) {
                return $key;
            }
        }

        return null;
    }

    /** Breadcrumb / sidebar label of a section (navigation.ts navigationLabel). */
    public static function label(string $key): string
    {
        return self::SECTIONS[$key][0] ?? $key;
    }

    public static function slug(string $key): string
    {
        return str(self::SECTIONS[$key][0])->slug()->toString();
    }

    /** Section key from its slug (placeholder page), only among the visible ones. */
    public static function fromSlug(?AgencyMembership $membership, string $slug): ?string
    {
        foreach (self::sectionsFor($membership) as $key) {
            if (! in_array($key, self::HIDDEN, true) && self::slug($key) === $slug) {
                return $key;
            }
        }

        return null;
    }
}
