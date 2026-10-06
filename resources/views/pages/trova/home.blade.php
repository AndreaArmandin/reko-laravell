<?php

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Home di REKO: stesso markup e stessi testi di Trova (components/prototype/home.tsx,
 * home-examples.tsx, demo-coverage.tsx, shared.tsx). I numeri della copertura arrivano dal nostro archivio.
 */
new #[Layout('layouts::trova')] class extends Component {
    /**
     * Copertura dell'archivio per Comune con edizione attiva.
     *
     * @return list<array{code: string, name: string, units: int, buildings: int, parcels: int, zones: int, omi: int}>
     */
    #[Computed]
    public function coverage(): array
    {
        return Municipality::query()->whereHas('catalog')->with('catalog')->orderBy('name')->get()
            ->map(function (Municipality $m) {
                $release = $m->catalog->catalog_release_id;
                $units = DB::table('cadastral_unit_versions as v')->join('cadastral_units as u', 'u.id', '=', 'v.cadastral_unit_id')
                    ->where('v.catalog_release_id', $release)->where('v.status', 'eligible')
                    ->where('v.category', 'not like', 'B/%')->where('v.category', 'not like', 'E/%');

                return [
                    'code' => $m->cadastral_code,
                    'name' => $m->name,
                    'units' => (clone $units)->count(),
                    'buildings' => DB::table('building_versions')->where('catalog_release_id', $release)->distinct()->count('building_id'),
                    'parcels' => (clone $units)->distinct()->count('u.parcel_id'),
                    'zones' => DB::table('geographic_zones')->where('municipality_id', $m->id)->count(),
                    'omi' => DB::table('omi_zones')->where('municipality_id', $m->id)->count(),
                ];
            })->all();
    }
}; ?>

@php
    $coverage = $this->coverage;
    $totals = ['units' => 0, 'buildings' => 0, 'parcels' => 0, 'zones' => 0, 'omi' => 0];
    foreach ($coverage as $row) {
        foreach ($totals as $key => $value) {
            $totals[$key] += $row[$key];
        }
    }
    $stats = ['units' => 'Unità catastali', 'buildings' => 'Sagome di fabbricati', 'parcels' => 'Particelle catastali', 'zones' => 'Quartieri, frazioni e zone di ricerca', 'omi' => 'Zone OMI · 2025/2'];
    $n = fn ($v) => number_format($v, 0, ',', '.');
    $names = array_column($coverage, 'name');
    $nameList = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' e '.end($names) : implode('', $names);
@endphp

<div class="reko-prototype reko-home" x-data="{ topic: null }">
    {{-- PrototypeHeader (shared.tsx) --}}
    <header class="proto-header" x-data="{ menu: false }">
        <div class="proto-header-inner">
            <a href="{{ route('home') }}" aria-label="REKO — home" class="reko-brand-logo"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a>
            <nav aria-label="Navigazione principale" class="proto-nav" :class="{ 'is-open': menu }">
                <a href="{{ route('home') }}#come-funziona" x-on:click="menu = false">Come funziona</a><a href="#esempi" x-on:click="menu = false">Esempi demo</a>
            </nav>
            <div class="proto-header-actions">
                @auth
                    <a class="proto-login" href="{{ auth()->user()->isAdmin() ? route('admin.dashboard') : route('dashboard') }}">{{ auth()->user()->isAdmin() ? 'Pannello admin' : 'Il mio account' }}</a>
                @else
                    <a class="proto-login" href="{{ route('login') }}">Accedi</a>
                @endauth
                <button type="button" class="group/button inline-flex shrink-0 items-center justify-center rounded-lg border border-transparent text-sm font-medium hover:bg-muted h-8 gap-1.5 px-2.5 proto-menu" :aria-label="menu ? 'Chiudi menu' : 'Apri menu'" :aria-expanded="menu" x-on:click="menu = ! menu">
                    <span x-show="! menu"><x-trova.icon name="menu" /></span><span x-show="menu" x-cloak><x-trova.icon name="x" /></span>
                </button>
            </div>
        </div>
    </header>

    <main>
        <section class="reko-home-hero proto-container" id="come-funziona">
            <div>
                <div class="reko-slogan">
                    <h1><span>Cerca immobili.</span><span>Gestisci contatti.</span><span>Crea opportunità.</span></h1>
                </div>
                <p class="reko-home-lead">Parti dalla richiesta del cliente, anche quando l’immobile giusto non è nel tuo portafoglio.</p>
                <div class="reko-home-actions">
                    <a class="reko-action-primary" href="{{ route('trova') }}"><x-trova.icon name="search" size="22" />Trova immobili<x-trova.icon name="arrow-right" size="20" /></a>
                    <a class="reko-action-secondary" href="{{ route('gestionale.home') }}">Apri Gestionale</a>
                </div>
                <p class="reko-home-note">Accesso riservato agli account autorizzati.</p>
            </div>
            <aside class="reko-case reko-slogan-meaning" aria-label="Che cosa puoi fare con REKO">
                <ol>
                    <li><x-trova.icon name="search" /><div><strong>La ricerca</strong><p>Scegli una zona e cerca immobili nei dati disponibili, anche senza un annuncio. La disponibilità resta da verificare.</p></div></li>
                    <li><x-trova.icon name="contact-round" class="lucide-contact-2" /><div><strong>Le relazioni</strong><p>Raccogli le esigenze del cliente. Collega immobili, proprietari e contatti, senza perdere note e attività da fare.</p></div></li>
                    <li><x-trova.icon name="lightbulb" /><div><strong>Le opportunità</strong><p>Segui anche le ricerche residenziali e commerciali più complesse: esplora nuove possibilità e organizza gli approfondimenti. Il lavoro raccolto può servire a un nuovo cliente.</p></div></li>
                </ol>
            </aside>
        </section>

        {{-- HomeExamples (home-examples.tsx) --}}
        <section id="esempi" class="proto-container reko-home-section" aria-labelledby="esempi-title" x-data="{ selected: 'trova' }">
            <p class="reko-kicker">ESEMPI DEMO · PERSONE E IMMOBILI DI FANTASIA</p>
            <h2 id="esempi-title">Guarda come si presenta REKO.</h2>
            <div class="reko-demo-switch" role="group" aria-label="Scegli un esempio">
                @foreach (['trova' => ['Trova', 'search'], 'crm' => ['CRM', 'contact-round'], 'gestionale' => ['Gestionale', 'map']] as $id => [$label, $icon])
                    <button type="button" :aria-pressed="selected === '{{ $id }}'" aria-controls="reko-demo-panel" x-on:click="selected = '{{ $id }}'"><x-trova.icon :name="$icon" size="20" />{{ $label }}</button>
                @endforeach
            </div>
            <div id="reko-demo-panel" class="reko-demo-panel" role="region" :aria-label="{ trova: 'Trova', crm: 'CRM', gestionale: 'Gestionale' }[selected] + ' · esempio demo'">
                <template x-if="selected === 'trova'"><div>
                    <div class="reko-demo-heading"><div><span class="reko-example-label">TROVA · ESEMPIO DI RICERCA</span><h3>Immobili da approfondire</h3></div><span class="reko-demo-count">2 risultati demo</span></div>
                    <p class="reko-demo-filter">Appartamenti nella zona scelta · dati catastali disponibili</p>
                    <x-trova.demo-map :three-d="true" />
                    <div class="reko-demo-results">
                        @foreach ([['Via Esempio 12', 'A/2', '2', '5'], ['Via Esempio 24', 'A/3', '1', '4,5']] as $i => [$address, $category, $floor, $rooms])
                            <article class="reko-demo-result">
                                <div class="reko-demo-card-top"><span><b class="reko-demo-result-number">{{ $i + 1 }}</b><x-trova.icon name="building-2" size="20" />Appartamento</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="{{ $i === 0 ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star" aria-label="{{ $i === 0 ? 'Immobile salvato nell’esempio' : 'Immobile non salvato nell’esempio' }}"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>
                                </div>
                                <h4>{{ $address }}</h4>
                                <dl class="reko-demo-facts"><div><dt>Categoria catastale</dt><dd>{{ $category }}</dd></div><div><dt>Piano</dt><dd>{{ $floor }}</dd></div><div><dt>Vani catastali</dt><dd>{{ $rooms }}</dd></div></dl>
                                <p class="reko-home-note">Camere, m² interni e disponibilità da verificare.</p>
                            </article>
                        @endforeach
                    </div>
                    <p class="reko-home-note">La richiesta del cliente usa m² e camere da letto. I vani catastali sono un dato diverso, non il numero di camere.</p>
                </div></template>
                <template x-if="selected === 'crm'"><div>
                    <div class="reko-demo-heading"><div><span class="reko-example-label">CRM · ESEMPIO DI RICHIESTA</span><h3>Cliente Demo</h3></div><span class="reko-demo-count">Ricerca attiva</span></div>
                    <div class="reko-demo-columns">
                        <section class="reko-demo-result"><h4>Che cosa cerca</h4><dl class="reko-demo-contact"><div><dt>Immobile</dt><dd>Appartamento a Milano</dd></div><div><dt>Superficie</dt><dd>Da 80 a 110 m²</dd></div><div><dt>Camere da letto</dt><dd>Da 2 a 3</dd></div><div><dt>Budget</dt><dd>Fino a 500.000 €</dd></div></dl></section>
                        <section class="reko-demo-result"><h4>Attività collegate</h4><dl class="reko-demo-contact"><div><dt>Ultima attività</dt><dd>Telefonata registrata</dd></div><div><dt>Esito</dt><dd>Richiesta confermata</dd></div><div><dt>Prossimo impegno</dt><dd>Approfondire gli immobili individuati</dd></div></dl>
                            <div class="reko-demo-actions" aria-label="Comandi disponibili nella scheda reale"><span><x-trova.icon name="plus" size="17" />Aggiungi attività</span><span><x-trova.icon name="rotate-ccw-clock" size="17" class="lucide-history" />Storico attività</span></div></section>
                    </div>
                </div></template>
                <template x-if="selected === 'gestionale'"><div>
                    <div class="reko-demo-heading"><div><span class="reko-example-label">GESTIONALE · ZONA DI RICERCA E CENSIMENTO</span><h3>Conosci gli immobili della tua zona</h3></div><span class="reko-demo-count">Vista 2D</span></div>
                    <div class="reko-demo-census">
                        <x-trova.demo-map :three-d="false" />
                        <section class="reko-demo-result"><div class="reko-demo-card-top"><span><x-trova.icon name="layers" size="20" />Particella selezionata</span></div><h4>Via Esempio 12</h4><p>Foglio 12 · Particella 89 · dati demo</p>
                            <dl class="reko-demo-contact"><div><dt>Unità censite</dt><dd>6 immobili</dd></div><div><dt>Categorie presenti</dt><dd>4 abitazioni A/2<br>1 deposito C/2 · 1 box C/6</dd></div><div><dt>Superfici sulla mappa</dt><dd>Lotto 400 m²<br>Fabbricato 270 m² · esterno 130 m²</dd></div></dl>
                            <p class="reko-home-note">Dalla zona agli immobili: consulta le unità e collega le informazioni raccolte su proprietari e proprietà.</p></section>
                    </div>
                </div></template>
            </div>
        </section>

        {{-- DemoCoverage (demo-coverage.tsx), con i numeri del nostro archivio --}}
        <section class="proto-container reko-home-section">
            <section class="rounded-3xl border border-border bg-card p-6 sm:p-8" aria-labelledby="demo-coverage-heading" x-data="{ code: 'all' }">
                <p class="ui-kicker">Il territorio di REKO</p>
                <h2 id="demo-coverage-heading" class="mt-3 text-3xl font-black">Copertura dell’archivio REKO</h2>
                <p class="mt-4 text-muted-foreground">Dati acquisiti per {{ $nameList }}. Scegli un Comune per consultarli separatamente.</p>
                <div class="my-6">
                    <label for="coverage-municipality" class="block text-base font-bold">Consulta l’archivio per Comune</label>
                    <select id="coverage-municipality" class="field-input mt-2 max-w-md" x-model="code">
                        <option value="all">Tutti i Comuni · totale REKO</option>
                        @foreach ($coverage as $row)
                            <option value="{{ $row['code'] }}">{{ $row['name'] }}</option>
                        @endforeach
                    </select>
                    <div aria-live="polite" aria-atomic="true">
                        @foreach ([['code' => 'all', 'name' => 'Tutti i Comuni · totale REKO', ...$totals], ...$coverage] as $row)
                            <div x-show="code === '{{ $row['code'] }}'" @if ($row['code'] !== 'all') x-cloak @endif>
                                <h3 class="mt-5 text-xl font-bold">{{ $row['name'] }}</h3>
                                <dl class="my-4 grid grid-cols-1 gap-3 min-[520px]:grid-cols-2 md:grid-cols-3">
                                    @foreach ($stats as $key => $label)
                                        <div class="coverage-stat flex min-w-0 flex-col rounded-2xl border border-primary/25 bg-background/70 p-4 sm:p-5"><dt class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $label }}</dt><dd class="order-first text-3xl font-black tabular-nums text-primary">{{ $n($row[$key]) }}</dd></div>
                                    @endforeach
                                </dl>
                                @if ($row['code'] === 'all')
                                    <p class="text-sm text-muted-foreground">Ogni totale somma i dati della stessa voce nei {{ count($coverage) }} Comuni.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <details class="reko-disclosure">
                    <summary>Vedi dettagli e fonti</summary>
                    <div class="mt-6 space-y-4">
                        <h3 class="text-2xl font-black">Dati e fonti</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <caption class="mb-3 text-left">Composizione dei totali per Comune</caption>
                                <thead><tr><th class="p-2">Comune</th><th class="p-2">Fabbricati</th><th class="p-2">Particelle</th><th class="p-2">Quartieri / zone</th><th class="p-2">Zone OMI</th><th class="p-2">Unità catastali</th></tr></thead>
                                <tbody>
                                    @foreach ($coverage as $row)
                                        <tr class="border-t border-border"><th scope="row" class="p-2">{{ $row['name'] }}</th><td class="p-2 tabular-nums">{{ $n($row['buildings']) }}</td><td class="p-2 tabular-nums">{{ $n($row['parcels']) }}</td><td class="p-2 tabular-nums">{{ $n($row['zones']) }}</td><td class="p-2 tabular-nums">{{ $n($row['omi']) }}</td><td class="p-2 tabular-nums">{{ $n($row['units']) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-sm text-muted-foreground">Le sagome sono conteggiate una sola volta anche quando attraversano più particelle. Fabbricati, particelle e unità descrivono livelli diversi: non si sommano fra loro e non indicano immobili in vendita.</p>
                        <p class="text-sm text-muted-foreground">Gli archivi X001 e X002 contengono unità dimostrative. Il campione D205 di Cuneo comprende geometrie e zone importate da Trova; la copertura è parziale e non indica immobili in vendita.</p>
                    </div>
                </details>
            </section>
        </section>

        <section class="proto-container reko-home-section reko-home-faq">
            <h2>Prima di iniziare</h2>
            <details><summary>Gli immobili sono in vendita?</summary><p>Non necessariamente. REKO cerca nei dati territoriali disponibili, anche oltre gli annunci. La disponibilità, i proprietari attuali e le condizioni vanno verificati.</p></details>
            <details><summary>Posso cercare senza creare un cliente?</summary><p>Sì. Apri Trova, scegli il Comune e inserisci i criteri. Nello stesso sito trovi il Gestionale per organizzare il lavoro.</p></details>
            <details><summary>Dove ritrovo gli immobili interessanti?</summary><p>In Immobili salvati, nel tuo account. Le Ricerche recenti conservano invece le ricerche concluse degli ultimi 3 giorni.</p></details>
            <details><summary>Che cosa succede se un dato non è disponibile?</summary><p>È indicato come mancante o da verificare. Non viene inventato né considerato una corrispondenza positiva.</p></details>
        </section>
    </main>

    {{-- PrototypeFooter (shared.tsx) + PrivacyCookies --}}
    <footer class="proto-footer">
        <div class="proto-container" style="padding:20px 16px">
            <div class="reko-privacy-links" style="display:flex;flex-wrap:wrap;gap:12px 20px;font-size:.875rem">
                @foreach (['Privacy Policy', 'Cookie Policy', 'Termini e condizioni', 'Preferenze cookie'] as $item)
                    <button type="button" x-on:click="topic = @js($item); $refs.topic.showModal()">{{ $item }}</button>
                @endforeach
            </div>
            <details style="margin-top:16px">
                <summary>Privacy e servizi esterni</summary>
                <p>Informative e preferenze cookie saranno gestite con iubenda. Google Analytics e i cookie pubblicitari sono disattivati.</p>
                <p>Le mappe richiedono dati ai fornitori indicati nelle attribuzioni. Il 3D usa MapLibre e OpenFreeMap, con dati OpenStreetMap: le richieste trasmettono al fornitore informazioni tecniche, tra cui l’indirizzo IP. Non inviamo dati dei proprietari, contatti del cliente o contenuti del censimento a OpenFreeMap. La sessione del Gestionale utilizza strumenti tecnici per l’accesso; la disposizione della dashboard è una preferenza salvata sul dispositivo.</p>
                <p>Configurazione iubenda in attesa dei documenti del titolare. Questa nota tecnica non sostituisce la Privacy Policy.</p>
            </details>
        </div>
        <div class="proto-container">
            <div class="proto-footer-top"><a href="{{ route('home') }}" class="reko-brand-logo" aria-label="REKO — home"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a><span class="proto-badge"><span></span>Anteprima REKO · accesso riservato</span></div>
            <div class="proto-footer-groups">
                @foreach (['Dati e fonti' => ['Fonti dei dati', 'Licenze dei dataset', 'Attribuzioni cartografiche', 'OpenStreetMap e contributori', 'Informazioni catastali', 'Informazioni urbanistiche', 'Limiti dei dati', 'Data di aggiornamento'], 'Assistenza' => ['Segnala un dato inesatto', 'Gestione account', 'Eliminazione dati', 'Contatti']] as $title => $items)
                    <details><summary>{{ $title }}</summary><div>
                        @foreach ($items as $item)<button x-on:click="topic = @js($item); $refs.topic.showModal()">{{ $item }}</button>@endforeach
                    </div></details>
                @endforeach
            </div>
            <p class="proto-muted proto-small">Cartografia © OpenStreetMap contributors. Le persone e le attività di esempio sono di fantasia; la mappa del Gestionale mostra particelle reali.</p>
        </div>
    </footer>

    <x-trova.guide title="Cerca immobili. Gestisci contatti. Crea opportunità." text="Scegli il Comune e il percorso, poi compila zona e caratteristiche. Premi i punti interrogativi per chiarire i campi. Compila i valori obbligatori prima di proseguire." />

    {{-- DemoDialog dei temi del piè di pagina --}}
    <dialog x-ref="topic" class="proto-dialog reko-prototype" aria-labelledby="topic-title" x-on:click="if ($event.target === $el) $el.close()">
        <div class="proto-dialog-heading">
            <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="topic-title" x-text="topic"></h2></div>
            <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg hover:bg-muted" aria-label="Chiudi" x-on:click="$refs.topic.close()"><x-trova.icon name="x" /></button>
        </div>
        <p class="proto-dialog-copy">
            <span x-show="['OpenStreetMap e contributori', 'Attribuzioni cartografiche'].includes(topic)">La mappa utilizza i dati OpenStreetMap con i livelli territoriali disponibili per ciascun Comune. <a class="proto-text-link" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">Consulta l’attribuzione</a>.</span>
            <span x-show="['Fonti dei dati', 'Licenze dei dataset', 'Informazioni catastali', 'Informazioni urbanistiche', 'Limiti dei dati', 'Data di aggiornamento'].includes(topic)">I dati e le fonti della ricerca restano disponibili nella ricerca REKO. Il catalogo di ricerca contiene righe catastali separate dai dati operativi del Gestionale e dagli scenari illustrativi. La copertura è parziale; non è una visura aggiornata né un elenco di immobili in vendita.</span>
            <span x-show="! ['OpenStreetMap e contributori', 'Attribuzioni cartografiche', 'Fonti dei dati', 'Licenze dei dataset', 'Informazioni catastali', 'Informazioni urbanistiche', 'Limiti dei dati', 'Data di aggiornamento'].includes(topic)">Questa sezione informativa è in preparazione. Gli accessi della piattaforma online sono riservati agli account autorizzati; non sono attivi Google Analytics o cookie pubblicitari. Consulta la descrizione tecnica Privacy e cookie nel fondo pagina. Informative, condizioni e contatti definitivi richiedono approvazione prima del lancio.</span>
        </p>
        <button type="button" class="proto-button" x-on:click="$refs.topic.close()">Ho capito</button>
    </dialog>
</div>
