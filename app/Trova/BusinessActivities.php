<?php

namespace App\Trova;

/**
 * Trova lib/crm/business-activities.ts: search presets, not a declaration of lawful use.
 * Sources: Agenzia del Territorio, circolare 4/2006 §§3–3.1.2; Agenzia Entrate,
 * Statistiche catastali 2016, quadro categorie pp. 52–53.
 */
final class BusinessActivities
{
    /** @var array<string, array{label: string, description: string, categories: list<string>, related: list<string>, measure: string, note: string}> */
    public const ALL = [
        'office' => ['label' => 'Ufficio o studio professionale', 'description' => 'Lavoro d’ufficio, consulenza e servizi professionali.', 'categories' => ['A/10'], 'related' => [], 'measure' => 'vani', 'note' => 'La fonte riporta i vani catastali, non i m² interni. Non li convertiamo in superficie.'],
        'shop' => ['label' => 'Negozio e vendita al dettaglio', 'description' => 'Un punto vendita per prodotti e servizi.', 'categories' => ['C/1'], 'related' => ['C/3', 'D/8'], 'measure' => 'm²', 'note' => 'La consistenza catastale non coincide necessariamente con la superficie di vendita.'],
        'food' => ['label' => 'Bar, ristorante o caffetteria', 'description' => 'Uno spazio per la somministrazione.', 'categories' => ['C/1'], 'related' => ['D/8'], 'measure' => 'm²', 'note' => 'C/1 è una preselezione di locali, non prova di un ristorante esistente. Impianti, destinazione e requisiti sanitari vanno verificati.'],
        'workshop' => ['label' => 'Laboratorio artigianale', 'description' => 'Produzione artigianale, lavorazioni e riparazioni.', 'categories' => ['C/3'], 'related' => ['D/1', 'D/7'], 'measure' => 'm²', 'note' => 'Gli spazi industriali affini non sono automaticamente laboratori già utilizzabili.'],
        'storage' => ['label' => 'Magazzino o deposito', 'description' => 'Deposito merci e supporto alla logistica.', 'categories' => ['C/2'], 'related' => ['D/7', 'D/8'], 'measure' => 'm²', 'note' => 'Accessi, carico e scarico e uso logistico non sono dimostrati dalla categoria.'],
        'production' => ['label' => 'Produzione e industria', 'description' => 'Opifici e immobili a destinazione industriale.', 'categories' => ['D/1', 'D/7'], 'related' => [], 'measure' => '', 'note' => 'I dati acquisiti non riportano una superficie interna confrontabile. Non usiamo il lotto o l’impronta a terra al suo posto.'],
        'hotel' => ['label' => 'Albergo o pensione', 'description' => 'Strutture a destinazione alberghiera.', 'categories' => ['D/2'], 'related' => [], 'measure' => '', 'note' => 'Questo percorso non identifica B&B o appartamenti turistici. La superficie interna non è disponibile nella fonte.'],
        'sports' => ['label' => 'Palestra o attività sportiva', 'description' => 'Spazi e impianti a destinazione sportiva.', 'categories' => ['C/4', 'D/6'], 'related' => [], 'measure' => 'm²', 'note' => 'Il filtro superficie si applica solo alle categorie C. Le categorie D restano incluse anche senza superficie disponibile. Non classifichiamo in base alla natura del gestore.'],
        'parking' => ['label' => 'Autorimessa o parcheggio', 'description' => 'Spazi per un’attività di autorimessa o parcheggio.', 'categories' => ['C/6'], 'related' => ['D/8'], 'measure' => 'm²', 'note' => 'La categoria non dimostra l’autorizzazione a gestire un parcheggio aperto al pubblico.'],
        'entertainment' => ['label' => 'Cinema, teatro o spettacolo', 'description' => 'Immobili a destinazione di spettacolo.', 'categories' => ['D/3'], 'related' => [], 'measure' => '', 'note' => 'Capienza, agibilità e autorizzazioni non sono ricavabili dalla sola categoria.'],
        'healthcare' => ['label' => 'Struttura sanitaria', 'description' => 'Case di cura e strutture sanitarie dedicate.', 'categories' => ['D/4'], 'related' => [], 'measure' => '', 'note' => 'Per uno studio professionale scegli Ufficio o studio. D/4 non identifica qualsiasi studio medico.'],
        'bank' => ['label' => 'Struttura bancaria o assicurativa', 'description' => 'Immobili specificamente destinati a queste funzioni.', 'categories' => ['D/5'], 'related' => [], 'measure' => '', 'note' => 'Per una normale sede di consulenza scegli Ufficio o studio professionale.'],
        'agriculture' => ['label' => 'Produzione agricola', 'description' => 'Fabbricati per funzioni produttive agricole.', 'categories' => ['D/10'], 'related' => [], 'measure' => '', 'note' => 'Non comprende automaticamente terreni o capannoni industriali.'],
        'special' => ['label' => 'Altri usi speciali', 'description' => 'Strutture speciali censite in D/9.', 'categories' => ['D/9'], 'related' => [], 'measure' => '', 'note' => 'Percorso specifico per strutture speciali, non per normali locali commerciali. Uso e caratteristiche vanno verificati sul singolo bene.'],
        'development' => ['label' => 'Spazi e immobili da sviluppare', 'description' => 'Tettoie e categorie catastali provvisorie o senza rendita.', 'categories' => ['C/7', 'F/1', 'F/2', 'F/3', 'F/4', 'F/5', 'F/6', 'F/7'], 'related' => [], 'measure' => '', 'note' => 'Non sono spazi pronti all’uso. Servono verifiche sul singolo immobile e sul progetto.'],
    ];

    /** Groups of the activity picker (business-activity-picker.tsx). */
    public const GROUPS = [
        'Uffici / Commercio' => ['office', 'shop', 'bank'],
        'Ristorazione / Ricettivo' => ['food', 'hotel'],
        'Produzione / Logistica' => ['workshop', 'storage', 'production', 'agriculture'],
        'Altro' => ['sports', 'parking', 'entertainment', 'healthcare', 'special', 'development'],
    ];

    /**
     * @return array{label: string, description: string, categories: list<string>, related: list<string>, measure: string, note: string}|null
     */
    public static function find(mixed $id): ?array
    {
        return is_string($id) ? (self::ALL[$id] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    public static function categories(string $id, bool $related = false): array
    {
        $rule = self::ALL[$id];

        return [...$rule['categories'], ...($related ? $rule['related'] : [])];
    }

    /** "Categoria principale" / "Categoria affine · uso da verificare" */
    public static function match(string $id, string $category): string
    {
        $rule = self::find($id);

        return match (true) {
            $rule === null => '',
            in_array($category, $rule['categories'], true) => 'Categoria principale',
            in_array($category, $rule['related'], true) => 'Categoria affine · uso da verificare',
            default => '',
        };
    }
}
