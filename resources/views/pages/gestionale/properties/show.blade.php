<?php

use App\Gestionale\Actions\Properties\SetPropertyLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Document;
use App\Models\Property;
use App\Models\PropertyMatch;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale'), Title('Scheda immobile')] class extends Component {
    use HandlesCommands;

    public Property $property;
    public string $activeTab = 'Scheda immobile';
    public ?array $mapPoint = null;

    public ?string $documentDialog = null;
    public ?int $previewDocumentId = null;
    public string $documentTitle = '';
    public string $documentKind = 'Documento';
    public string $documentStatus = 'Da richiedere';
    public string $documentContent = '';
    public bool $documentInternal = true;

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);
        $this->property = $property->load([
            'municipality', 'agent',
            'units.cadastralUnit.parcel.municipality',
            'units.cadastralUnit.versions' => fn ($query) => $query->orderByDesc('catalog_release_id')->orderByDesc('id'),
            'contacts.contact.primaryPhone', 'contacts.contact.primaryEmail',
            'matches' => fn ($query) => $query->whereHas('propertyRequest', fn ($request) => $request->visibleTo($this->actor()))
                ->orderByRaw('score DESC NULLS LAST')->orderByDesc('id'),
            'matches.propertyRequest.contact',
        ]);

        $point = DB::selectOne('SELECT ST_Y(location) AS lat, ST_X(location) AS lng FROM properties WHERE id = ? AND agency_id = ?', [$property->id, $property->agency_id]);
        if ($point?->lat !== null && $point?->lng !== null) {
            $this->mapPoint = ['lat' => (float) $point->lat, 'lng' => (float) $point->lng];
        }
    }

    public function selectTab(string $tab): void
    {
        $allowed = ['Scheda immobile', 'Cronologia attività immobile'];
        if (in_array($this->actor()->role, ['admin', 'crm'], true)) {
            $allowed[] = 'Clienti compatibili';
        }

        if (in_array($tab, $allowed, true)) {
            $this->activeTab = $tab;
        }
    }

    public function restore(): void
    {
        $this->authorize('archive', $this->property);
        if ($this->property->lifecycle_state === null) return;
        $this->property = $this->command(fn () => app(SetPropertyLifecycle::class)->handle($this->actor(), $this->property, 'restore'), 'Immobile ripristinato.') ?? $this->property;
    }

    public function archive(): void
    {
        $this->authorize('archive', $this->property);
        $this->property = $this->command(fn () => app(SetPropertyLifecycle::class)->handle($this->actor(), $this->property, 'archive'), 'Immobile archiviato.') ?? $this->property;
    }

    #[Computed]
    public function featureLabels(): array
    {
        $labels = [
            'operation' => 'Contratto', 'purpose' => 'Finalità compatibili', 'typology' => 'Tipologia',
            'price' => 'Prezzo / canone mensile (€)', 'area' => 'Superficie commerciale (m²)', 'walkableArea' => 'Superficie calpestabile (m²)',
            'fees' => 'Spese mensili (€)', 'rooms' => 'Locali', 'bedrooms' => 'Camere', 'bathrooms' => 'Bagni',
            'floor' => 'Piano', 'totalFloors' => 'Piani dell’edificio', 'year' => 'Anno di costruzione',
            'availableFrom' => 'Disponibilità dal', 'energyIndex' => 'Indice energetico', 'energy' => 'Classe energetica',
            'occupancy' => 'Stato di occupazione', 'yieldEstimate' => 'Rendimento indicativo (%)',
            'cadastralCategory' => 'Categoria catastale', 'elevator' => 'Ascensore', 'garage' => 'Garage / box',
            'garden' => 'Giardino', 'balcony' => 'Balcone', 'terrace' => 'Terrazzo', 'parking' => 'Posto auto',
            'cellar' => 'Cantina', 'accessible' => 'Accesso senza barriere', 'furnished' => 'Arredato',
            'heating' => 'Riscaldamento', 'airConditioning' => 'Climatizzazione', 'pets' => 'Animali ammessi (locazione)',
            'condition' => 'Stato manutentivo',
        ];
        foreach (Questionnaire::forAgency((int) $this->property->agency_id)->questions() as $question) {
            if (! empty($question['field']) && empty($question['sensitive'])) {
                $labels[$question['field']] ??= rtrim((string) $question['text'], '?');
            }
        }

        return $labels;
    }

    #[Computed]
    public function matches()
    {
        return PropertyMatch::query()->with('propertyRequest.contact')
            ->where('agency_id', $this->property->agency_id)
            ->where('property_id', $this->property->id)
            ->whereHas('propertyRequest', fn ($request) => $request->visibleTo($this->actor()))
            ->orderByRaw('score DESC NULLS LAST')->orderByDesc('id')->get();
    }

    #[Computed]
    public function documents()
    {
        return Document::query()->where('agency_id', $this->property->agency_id)
            ->whereIn('documentable_type', [Property::class, 'property'])
            ->where('documentable_id', $this->property->id)
            ->orderByDesc('updated_at')->orderByDesc('id')->get();
    }

    #[Computed]
    public function previewDocument(): ?Document
    {
        return $this->previewDocumentId === null ? null : $this->documents->firstWhere('id', $this->previewDocumentId);
    }

    public function openDocumentForm(): void
    {
        $this->authorize('update', $this->property);
        $this->resetDocumentFields();
        $this->documentDialog = 'create';
    }

    public function openDocumentPreview(int $id): void
    {
        abort_unless($this->documents->contains('id', $id), 404);
        $this->previewDocumentId = $id;
        $this->documentDialog = 'preview';
        unset($this->previewDocument);
    }

    public function closeDocumentDialog(): void
    {
        $this->documentDialog = null;
        $this->previewDocumentId = null;
        unset($this->previewDocument);
    }

    public function saveDocument(): void
    {
        $this->authorize('update', $this->property);
        $validated = $this->validate([
            'documentTitle' => ['required', 'string', 'max:160'],
            'documentKind' => ['required', 'in:Documento,Fotografia,Planimetria,Video'],
            'documentStatus' => ['required', 'in:Da richiedere,Richiesto,Ricevuto'],
            'documentContent' => ['nullable', 'string', 'max:5000'],
            'documentInternal' => ['boolean'],
        ]);

        Document::query()->create([
            'agency_id' => $this->property->agency_id,
            'uploaded_by_user_id' => $this->actor()->user_id,
            'title' => trim($validated['documentTitle']),
            'storage_path' => null,
            'mime_type' => null,
            'byte_size' => null,
            'documentable_type' => Property::class,
            'documentable_id' => $this->property->id,
            'metadata' => [
                'kind' => $validated['documentKind'],
                'status' => $validated['documentStatus'],
                'content' => trim((string) $validated['documentContent']),
                'internal' => (bool) $validated['documentInternal'],
            ],
        ]);

        unset($this->documents);
        $this->closeDocumentDialog();
        $this->dispatch('crm-notice', type: 'success', text: 'Voce documento registrata. Non è stato caricato alcun allegato.');
    }

    private function resetDocumentFields(): void
    {
        $this->resetValidation();
        $this->documentTitle = '';
        $this->documentKind = 'Documento';
        $this->documentStatus = 'Da richiedere';
        $this->documentContent = '';
        $this->documentInternal = true;
    }
}; ?>

@php
    $features = $property->features ?? [];
    $primaryKeys = ['operation', 'occupancy', 'typology', 'area', 'walkableArea', 'rooms', 'bedrooms', 'bathrooms', 'floor', 'energy', 'availableFrom'];
    $primaryFacts = collect($primaryKeys)->filter(fn ($key) => \App\Gestionale\Questionnaire\Questionnaire::hasAnswer($features[$key] ?? null));
    $otherFacts = collect($features)->filter(fn ($value, $key) => ! in_array($key, [...$primaryKeys, 'price', 'tags', 'purpose'], true)
        && \App\Gestionale\Questionnaire\Questionnaire::hasAnswer($value));
    $price = $features['price'] ?? $property->asking_price;
    $publication = $property->publication ?? [];
    $mandate = $property->mandate ?? [];
    $addressLine = implode(' · ', array_unique(array_filter([$property->address, $property->civic, $property->city ?: $property->municipality?->name, $property->zone])));
@endphp

<div class="flex flex-col gap-6">
    <x-gestionale.record-activities :context="['property_id' => $property->id]" />

    <a class="crm-back" href="{{ route('gestionale.properties.index') }}" wire:navigate>← Torna al portafoglio</a>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">{{ $property->code ?: 'Scheda immobile' }} · {{ $addressLine }}</p>
            <h1>{{ $property->title }}</h1>
        </div>
        <div class="crm-actions">
            @can('update', $property)<a class="crm-btn secondary" href="{{ route('gestionale.properties.edit', $property) }}" wire:navigate>Modifica immobile</a>@endcan
            @if ($property->lifecycle_state === null)
                @can('archive', $property)<button class="crm-btn secondary" wire:click="archive" wire:confirm="Archiviare questo immobile?">Archivia</button>@endcan
            @else
                @can('archive', $property)<button class="crm-btn secondary" wire:click="restore">Ripristina</button>@endcan
            @endif
        </div>
    </div>

    @if (\App\Gestionale\Properties\TestRecords::isTestProperty($property))
        <p class="crm-contact-warning" role="note">TEST · Scheda di prova, non un immobile reale.</p>
    @endif
    @if ($property->lifecycle_state)<p class="crm-contact-warning" role="status">Scheda {{ $property->lifecycle_state === 'removed' ? 'rimossa dalle liste' : 'archiviata' }}. I dati e i collegamenti sono conservati.</p>@endif
    @if ($property->cadastral_warning)<p class="crm-contact-warning" role="status">{{ $property->cadastral_warning }}</p>@endif

    <nav class="crm-tabs" aria-label="Scheda immobile">
        <button type="button" aria-current="{{ $activeTab === 'Scheda immobile' ? 'page' : 'false' }}" wire:click="selectTab('Scheda immobile')">Scheda immobile</button>
        @if (in_array($this->actor()->role, ['admin', 'crm'], true))<button type="button" aria-current="{{ $activeTab === 'Clienti compatibili' ? 'page' : 'false' }}" wire:click="selectTab('Clienti compatibili')">Clienti compatibili · {{ $this->matches->count() }}</button>@endif
        <button type="button" aria-current="{{ $activeTab === 'Cronologia attività immobile' ? 'page' : 'false' }}" wire:click="selectTab('Cronologia attività immobile')">Cronologia attività immobile</button>
    </nav>

    @if ($activeTab === 'Scheda immobile')
        <div class="crm-detail-grid">
            <section class="crm-panel">
                <div class="crm-between"><x-gestionale.crm-pill>Stato scheda: {{ $property->status }}</x-gestionale.crm-pill>
                    @if (\App\Gestionale\Questionnaire\Questionnaire::hasAnswer($price))<strong class="crm-price">{{ AnswerPresenter::money($price) }}@if (($features['operation'] ?? null) === 'Locazione')/mese @endif</strong>@endif
                </div>
                <dl class="crm-facts">
                    @foreach ($primaryFacts as $key)
                        <div><dt>{{ $this->featureLabels[$key] ?? ucfirst($key) }}</dt><dd>{{ AnswerPresenter::label($features[$key]) }}@if ($key === 'area' || $key === 'walkableArea') m² @endif</dd></div>
                    @endforeach
                </dl>
                <x-gestionale.crm-tags :value="$features['tags'] ?? []" />
                @if ($property->description)<h2>Descrizione</h2><p class="crm-copy">{{ $property->description }}</p>@endif
                @if ($property->strengths)<h2>Punti di forza</h2><p class="crm-copy">{{ $property->strengths }}</p>@endif
                @if (filter_var($publication['url'] ?? null, FILTER_VALIDATE_URL) && in_array(parse_url($publication['url'], PHP_URL_SCHEME), ['http', 'https'], true))
                    <a class="crm-btn secondary" href="{{ $publication['url'] }}" target="_blank" rel="noopener noreferrer">Apri annuncio</a>
                @endif
            </section>

            <section class="crm-panel">
                @if ($mapPoint)
                    <div class="crm-location-map"><x-gestionale.map key="property-detail-{{ $property->id }}" label="Posizione dell’immobile" :config="['point' => [...$mapPoint, 'radius' => 0.1], 'center' => $mapPoint, 'zoom' => 13]" :sync="['point' => [...$mapPoint, 'radius' => 0.1]]" /></div>
                @else
                    <div class="crm-empty">Posizione non inserita nella scheda.</div>
                @endif
                <h2>Referente e pubblicazione</h2>
                <dl class="crm-facts">
                    <div><dt>Referente</dt><dd>{{ $property->agent?->name ?? 'Non assegnato' }}</dd></div>
                    <div><dt>Pubblicazione</dt><dd>{{ $publication['status'] ?? 'Non pubblicato' }}</dd></div>
                    <div><dt>Portali</dt><dd>{{ ($publication['portals'] ?? null) ?: 'Non indicati' }}</dd></div>
                    @if ($publication['date'] ?? null)<div><dt>Data pubblicazione</dt><dd>{{ $publication['date'] }}</dd></div>@endif
                    <div><dt>Incarico</dt><dd>{{ $mandate['type'] ?? 'Non indicato' }}</dd></div>
                    @if ($mandate['start'] ?? null)<div><dt>Incarico dal</dt><dd>{{ $mandate['start'] }}</dd></div>@endif
                    @if ($mandate['end'] ?? null)<div><dt>Scadenza incarico</dt><dd>{{ $mandate['end'] }}</dd></div>@endif
                </dl>
                <p class="crm-muted">Le azioni del Gestionale non pubblicano automaticamente sui portali.</p>
            </section>
        </div>

        <section class="crm-panel">
            <h2>Caratteristiche confrontabili</h2>
            @if ($otherFacts->isNotEmpty())
                <dl class="crm-facts">@foreach ($otherFacts as $key => $value)<div><dt>{{ $this->featureLabels[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key)) }}</dt><dd>{{ AnswerPresenter::label($value) }}</dd></div>@endforeach</dl>
            @else<p class="crm-muted">Nessun’altra caratteristica registrata.</p>@endif
            <details><summary>Note interne</summary><p class="crm-copy">{{ $property->internal_notes ?: 'Nessuna nota interna.' }}</p></details>
        </section>

        <section class="crm-panel">
            <h2>Collegamenti all’archivio</h2>
            @forelse ($property->units as $link)
                @php($unit = $link->cadastralUnit)
                @php($parcel = $unit?->parcel)
                @php($version = $unit?->versions?->first())
                <article class="crm-record-row">
                    <span class="crm-grow"><strong>{{ $version?->address_raw ?: 'Indirizzo non indicato' }}</strong><small>{{ $parcel?->municipality?->name ?? 'Comune non indicato' }} · Foglio {{ $parcel?->sheet ?? '—' }} · Particella {{ $parcel?->number ?? '—' }} · Sub. {{ $unit?->subalterno ?: '—' }} · {{ $version?->category ?: 'Categoria non indicata' }}@if ($version?->consistency) · {{ $version->consistency }}@endif</small></span>
                    <x-gestionale.crm-pill>{{ $link->role ?: 'Unità collegata' }}</x-gestionale.crm-pill>
                </article>
            @empty
                <p class="crm-muted">Nessuna unità catastale collegata. Puoi associarla dalla modifica dell’immobile.</p>
            @endforelse
            @if ($property->contacts->where('role', \App\Gestionale\Properties\PropertyLinks::OWNER_ROLE)->isNotEmpty())
                <h3>Proprietari collegati</h3>
                <div class="crm-inline-links">@foreach ($property->contacts->where('role', \App\Gestionale\Properties\PropertyLinks::OWNER_ROLE) as $ownerLink)<span>{{ $ownerLink->contact?->display_name ?? 'Proprietario' }}</span>@endforeach</div>
            @endif
        </section>

        @if ($property->contacts->isNotEmpty())
            <section class="crm-panel">
                <h2>Recapiti collegati</h2>
                @foreach ($property->contacts as $contactLink)
                    @if ($contactLink->contact)
                        <article class="crm-record-row"><span class="crm-grow"><strong>{{ $contactLink->contact->display_name }}</strong><small>{{ $contactLink->role }}</small><x-gestionale.crm-contact-links :name="$contactLink->contact->display_name" :phone="$contactLink->contact->primaryPhone?->value" :email="$contactLink->contact->primaryEmail?->value" /></span></article>
                    @endif
                @endforeach
            </section>
        @endif

        <section class="crm-panel">
            <div class="crm-section-head"><h2>Documenti e stato</h2>
                @can('update', $property)<button class="crm-btn secondary" type="button" wire:click="openDocumentForm">Registra documento</button>@endcan
            </div>
            <div class="crm-document-grid">
                @foreach ($this->documents as $document)
                    @php($metadata = $document->metadata ?? [])
                    <button type="button" class="crm-document" wire:click="openDocumentPreview({{ $document->id }})"><span aria-hidden="true">▤</span><span><strong>{{ $document->title }}</strong><small>{{ $metadata['kind'] ?? 'Documento' }} · {{ $metadata['status'] ?? 'Da richiedere' }} · {{ ($metadata['internal'] ?? true) ? 'Interno' : 'Condivisibile' }}</small></span></button>
                @endforeach
            </div>
            @if ($this->documents->isEmpty())<p class="crm-muted">Registra una richiesta o una nota sul documento. Qui non si caricano allegati.</p>@endif
        </section>
    @elseif ($activeTab === 'Clienti compatibili')
        <section class="crm-panel">
            <div class="crm-section-head"><div><h2>Richieste compatibili con questo immobile</h2><p class="crm-muted">Compatibilità indicativa. Verifica requisiti e dati mancanti prima di proporre l’immobile.</p></div><x-gestionale.crm-pill>{{ $this->matches->count() }} abbinamenti</x-gestionale.crm-pill></div>
            <div class="crm-match-list">
                @forelse ($this->matches as $match)
                    @php($request = $match->propertyRequest)
                    <article class="crm-match-card"><div class="crm-score {{ $match->score !== null && $match->score < 60 ? 'conditional' : '' }}"><strong>{{ $match->score !== null ? number_format((float) $match->score, 0) : '—' }}</strong>@if ($match->score !== null)<span>/ 100</span>@endif<small>Compatibilità</small></div>
                        <div class="crm-grow"><div class="crm-between"><a class="crm-title-link" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>{{ $request?->title_auto ? 'Richiesta immobiliare' : ($request?->title ?? 'Richiesta non disponibile') }}</a><x-gestionale.crm-pill>{{ $match->status }}</x-gestionale.crm-pill></div>
                            <p>{{ $request?->contact?->display_name ?? 'Cliente non disponibile' }} · {{ $request?->status ?? '' }}</p>
                            @if ($match->next_action)<p class="crm-muted">Prossima azione: {{ $match->next_action }}</p>@endif
                            @if ($match->visit_at)<small>Visita: {{ $match->visit_at->format('d/m/Y H:i') }}</small>@endif
                        </div><a class="crm-btn secondary" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>Apri richiesta</a>
                    </article>
                @empty<p class="crm-empty">Non ci sono abbinamenti accessibili per questo immobile.</p>@endforelse
            </div>
        </section>
    @else
        <section class="crm-panel"><h2>Cronologia attività immobile</h2><x-gestionale.activity-timeline :activities="\App\Gestionale\Activities\ActivityContext::activities($this->actor(), ['property_id' => $property->id])" scope="property-{{ $property->id }}" order="recent" /></section>
    @endif

    @if ($documentDialog === 'create')
        <x-gestionale.crm-dialog title="Registra documento" :close="'$wire.closeDocumentDialog()'">
            <form class="crm-form" wire:submit="saveDocument">
                <label class="crm-field"><span>Titolo</span><input type="text" wire:model="documentTitle" maxlength="160" required>@error('documentTitle')<small class="crm-error">{{ $message }}</small>@enderror</label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Tipo</span><select wire:model="documentKind"><option>Documento</option><option>Fotografia</option><option>Planimetria</option><option>Video</option></select></label>
                    <label class="crm-field"><span>Stato</span><select wire:model="documentStatus"><option>Da richiedere</option><option>Richiesto</option><option>Ricevuto</option></select></label>
                </div>
                <label class="crm-field"><span>Nota sul documento</span><textarea wire:model="documentContent" maxlength="5000"></textarea></label>
                <label class="crm-check"><input type="checkbox" wire:model="documentInternal">Materiale interno</label>
                <p class="crm-muted">Questa scheda registra titolo, stato e note. Non carica foto, video o altri allegati.</p>
                <button class="crm-btn" type="submit">Salva documento</button>
            </form>
        </x-gestionale.crm-dialog>
    @elseif ($documentDialog === 'preview' && $this->previewDocument)
        <x-gestionale.crm-dialog :title="$this->previewDocument->title" :close="'$wire.closeDocumentDialog()'">
            @php($metadata = $this->previewDocument->metadata ?? [])
            <p class="crm-contact-warning">{{ $metadata['status'] ?? 'Da richiedere' }} · {{ $metadata['kind'] ?? 'Documento' }}</p>
            <p class="crm-copy">{{ $metadata['content'] ?: 'Nessuna nota inserita. Questa voce non contiene un allegato.' }}</p>
        </x-gestionale.crm-dialog>
    @endif
</div>
