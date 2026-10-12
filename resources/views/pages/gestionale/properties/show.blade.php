<?php

use App\Gestionale\Actions\Census\SaveCensusProposal;
use App\Gestionale\Actions\Census\SaveOwnerContact;
use App\Gestionale\Actions\Documents\SaveDocument;
use App\Gestionale\Actions\Properties\SaveProperty;
use App\Gestionale\Activities\ActivityContext;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Livewire\ManagesPropertyLifecycle;
use App\Gestionale\Properties\PropertyFields;
use App\Gestionale\Properties\PropertyLinks;
use App\Gestionale\Properties\TestRecords;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyUnitObservation;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale'), Title('Scheda immobile')] class extends Component {
    use HandlesCommands, ManagesPropertyLifecycle;

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

    /** Recapito del proprietario in modifica (census-contact-editor) e proposta di correzione catastale. */
    public ?int $contactId = null;
    public string $contactDraft = '';
    public string $contactNotice = '';
    public ?int $proposalOwnerId = null;
    public string $proposalNote = '';

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);
        $this->property = $property->load('agent');

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

    /** Dopo archivia / ripristina / rimuovi la scheda si rilegge dall'archivio. */
    public function afterLifecycle(int $id): void
    {
        $this->property->refresh();
    }

    /** Domande con un campo, non riservate: il testo è l'etichetta dei campi senza una propria (PropertyFields::label). */
    #[Computed]
    public function questions(): array
    {
        $map = [];
        foreach (Questionnaire::forAgency((int) $this->property->agency_id)->questions() as $question) {
            if (! empty($question['field']) && empty($question['sensitive'])) {
                $map[$question['field']] = $question;
            }
        }

        return $map;
    }

    /** Righe di «Collegamenti all'archivio»: le unità collegate, con i dati di censimento dell'agenzia se ci sono. */
    #[Computed]
    public function unitLines(): array
    {
        $units = $this->property->units()->with([
            'cadastralUnit.parcel.municipality',
            'cadastralUnit.versions' => fn ($query) => $query->orderByDesc('catalog_release_id')->orderByDesc('id'),
        ])->get();
        $census = AgencyUnitObservation::query()->whereIn('cadastral_unit_id', $units->pluck('cadastral_unit_id')->filter()->all())
            ->get()->keyBy('cadastral_unit_id');

        return $units
            ->map(fn ($link) => PropertyLinks::unitLine($link->cadastralUnit, $census->get($link->cadastral_unit_id)))
            ->filter()->values()->all();
    }

    /** Proprietari collegati (non rimossi), nella forma dell'archivio catastale. */
    #[Computed]
    public function owners(): array
    {
        $ids = PropertyLinks::currentOwnerIds($this->property);
        if ($ids === []) {
            return [];
        }
        $phones = DB::table('contact_channels')->whereIn('contact_id', $ids)->where('kind', 'phone')->orderByDesc('is_primary')->orderBy('id')->get()->groupBy('contact_id');

        return Contact::query()->where('agency_id', $this->property->agency_id)->whereNull('removed_at')->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (Contact $contact) => CensusReader::owner((object) $contact->getAttributes(), $phones->get($contact->id, collect())))->all();
    }

    public function editOwnerContact(int $ownerId, ?int $unitId = null): void
    {
        $owner = collect($this->owners)->firstWhere('id', $ownerId) ?? abort(404);
        $this->contactId = $ownerId;
        $this->contactDraft = CensusReader::contactText($owner);
        $this->contactNotice = '';
    }

    public function saveOwnerContact(): void
    {
        if ($this->contactId === null) {
            return;
        }
        $saved = $this->command(fn () => app(SaveOwnerContact::class)->handle($this->actor(), $this->contactId, ['recapito' => $this->contactDraft]), 'Recapito aggiornato in tutte le unità del proprietario');
        if ($saved === null) {
            $this->contactNotice = (string) $this->getErrorBag()->first();

            return;
        }
        $this->closeContact();
        unset($this->owners);
    }

    public function closeContact(): void
    {
        $this->contactId = null;
        $this->contactDraft = '';
        $this->contactNotice = '';
    }

    public function openProposal(int $ownerId): void
    {
        abort_unless(collect($this->owners)->contains('id', $ownerId), 404);
        $this->resetValidation();
        $this->proposalOwnerId = $ownerId;
        $this->proposalNote = '';
    }

    public function closeProposal(): void
    {
        $this->proposalOwnerId = null;
        $this->proposalNote = '';
    }

    public function submitProposal(): void
    {
        if ($this->proposalOwnerId === null) {
            return;
        }
        $done = $this->command(fn () => app(SaveCensusProposal::class)->handle($this->actor(), null, $this->proposalOwnerId, ['note' => $this->proposalNote]), 'Proposta inviata all’Amministratore');
        if ($done !== null) {
            $this->closeProposal();
        }
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

        $document = $this->command(fn () => app(SaveDocument::class)->handle($this->actor(), $this->property, [
            'title' => $validated['documentTitle'], 'kind' => $validated['documentKind'], 'status' => $validated['documentStatus'],
            'content' => $validated['documentContent'], 'internal' => $validated['documentInternal'],
        ]));
        if ($document === null) {
            return;
        }
        $this->property->refresh();

        unset($this->documents);
        $this->closeDocumentDialog();
        $this->dispatch('activities-changed');
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
    // properties.tsx: fatti principali nell'ordine dell'originale, poi le altre caratteristiche confrontabili.
    $features = $property->features ?? [];
    $primaryKeys = ['operation', 'occupancy', 'typology', 'area', 'walkableArea', 'rooms', 'bedrooms', 'bathrooms', 'floor', 'energy', 'availableFrom'];
    $primaryFacts = collect($primaryKeys)->filter(fn ($key) => Questionnaire::hasAnswer($features[$key] ?? null));
    $otherFacts = collect($features)->filter(fn ($value, $key) => ! in_array($key, [...$primaryKeys, 'price', 'tags', 'purpose'], true) && Questionnaire::hasAnswer($value));
    $price = $features['price'] ?? null;
    $publication = $property->publication ?? [];
    $actor = $this->actor();
@endphp

<div class="flex flex-col gap-6">
    <a class="crm-back" href="{{ route('gestionale.properties.index') }}" wire:navigate><x-gestionale.lucide name="arrow-left" :size="16" />Torna al portafoglio</a>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>{{ $property->title }}</h1>
            <p class="crm-muted">{{ $property->code }} · {{ $property->address }} {{ $property->civic }}, {{ $property->city }} · {{ $property->zone }}</p>
        </div>
        @can('update', $property)
            <div class="crm-actions"><a class="crm-btn secondary" href="{{ route('gestionale.properties.edit', $property) }}" wire:navigate><x-gestionale.lucide name="pencil" :size="16" />Modifica immobile</a></div>
        @endcan
    </div>

    @if (TestRecords::isTestProperty($property))
        <p class="crm-contact-warning" role="note">TEST · Scheda di prova, non un immobile reale.</p>
    @endif
    <x-gestionale.crm-lifecycle kind="property" :id="$property->id" :state="$property->lifecycle_state" :at="$property->lifecycle_at" :admin="$actor->isAdmin()" />
    <x-gestionale.record-activities :context="['property_id' => $property->id]" />

    <nav class="crm-tabs" aria-label="Scheda immobile">
        <button type="button" aria-current="{{ $activeTab === 'Scheda immobile' ? 'page' : 'false' }}" wire:click="selectTab('Scheda immobile')">Scheda immobile</button>
        @if (in_array($actor->role, ['admin', 'crm'], true))<button type="button" aria-current="{{ $activeTab === 'Clienti compatibili' ? 'page' : 'false' }}" wire:click="selectTab('Clienti compatibili')">Clienti compatibili</button>@endif
        <button type="button" aria-current="{{ $activeTab === 'Cronologia attività immobile' ? 'page' : 'false' }}" wire:click="selectTab('Cronologia attività immobile')">Cronologia attività immobile</button>
    </nav>

    @if ($activeTab === 'Scheda immobile')
        <div class="crm-detail-grid">
            <section class="crm-panel">
                <div class="crm-between"><x-gestionale.crm-pill>Stato scheda: {{ $property->status }}</x-gestionale.crm-pill>
                    @if (Questionnaire::hasAnswer($price))<span class="crm-price">{{ AnswerPresenter::money($price) }}{{ ($features['operation'] ?? null) === 'Locazione' ? '/mese' : '' }}</span>@endif
                </div>
                <dl class="crm-facts">
                    @foreach ($primaryFacts as $key)
                        <div><dt>{{ PropertyFields::label($key, $this->questions[$key] ?? null) }}</dt><dd>{{ $key === 'operation' ? PropertyFields::operationLabel($features[$key]) : AnswerPresenter::answerLabel($key, $features[$key]) }}</dd></div>
                    @endforeach
                </dl>
                <x-gestionale.crm-tags :value="$features['tags'] ?? []" />
                @if ($property->description)<p style="white-space:pre-wrap">{{ $property->description }}</p>@endif
                @if ($property->strengths)<p class="crm-muted">{{ $property->strengths }}</p>@endif
                @if (filter_var($publication['url'] ?? null, FILTER_VALIDATE_URL) && in_array(parse_url($publication['url'], PHP_URL_SCHEME), ['http', 'https'], true))
                    <a class="crm-btn" href="{{ $publication['url'] }}" target="_blank" rel="noopener noreferrer">Apri annuncio <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
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
                    <div><dt>Referente</dt><dd>{{ $property->agent?->name }}</dd></div>
                    <div><dt>Pubblicazione</dt><dd>{{ $publication['status'] ?? SaveProperty::DEFAULT_PUBLICATION_STATUS }}</dd></div>
                </dl>
            </section>
        </div>

        <section class="crm-panel">
            <h2>Caratteristiche confrontabili</h2>
            <dl class="crm-facts">@foreach ($otherFacts as $key => $value)<div><dt>{{ PropertyFields::label($key, $this->questions[$key] ?? null) }}</dt><dd>{{ AnswerPresenter::label($value) }}</dd></div>@endforeach</dl>
            <details><summary>Note interne</summary><p>{{ $property->internal_notes ?: 'Nessuna nota.' }}</p></details>
        </section>

        <section class="crm-panel">
            <h2>Collegamenti all’archivio</h2>
            @forelse ($this->unitLines as $line)
                <p>{{ $line }}</p>
            @empty
                <p class="crm-muted">Nessuna unità catastale collegata. Puoi associarla dalla modifica dell’immobile.</p>
            @endforelse
            <div class="crm-inline-links">
                @foreach ($this->owners as $owner)
                    @if ($actor->role === 'crm')
                        <span>{{ $owner['name'] }}</span>
                    @else
                        <button type="button" onclick="Livewire.navigate(@js(route('gestionale.archive.index', ['owner' => $owner['id']])))">{{ $owner['name'] }}</button>
                    @endif
                @endforeach
            </div>
        </section>

        @if ($this->owners !== [] || $property->cadastral_warning)
            <section class="crm-panel">
                <h2>Recapiti dei proprietari collegati</h2>
                @if ($property->cadastral_warning)<p role="status">{{ $property->cadastral_warning }}</p>@endif
                @foreach ($this->owners as $owner)
                    <article class="census-history-row" wire:key="property-owner-{{ $owner['id'] }}">
                        <h3>{{ $owner['name'] }}</h3>
                        <small>{{ $owner['pid'] }}</small>
                        <x-gestionale.census-contact-editor :owner-id="$owner['id']" :text="CensusReader::contactText($owner)" :editing-id="$contactId" :editing-unit="null" :notice-text="$contactNotice" />
                        <button type="button" class="crm-link" wire:click="openProposal({{ $owner['id'] }})">Segnala una correzione catastale</button>
                    </article>
                @endforeach
            </section>
        @endif

        <section class="crm-panel">
            <div class="crm-section-head"><h2>Documenti e stato</h2>
                @can('update', $property)<button class="crm-btn secondary" type="button" wire:click="openDocumentForm"><x-gestionale.lucide name="plus" :size="16" />Registra documento</button>@endcan
            </div>
            <div class="crm-document-grid">
                @foreach ($this->documents as $document)
                    @php($metadata = $document->metadata ?? [])
                    <button type="button" class="crm-document" wire:click="openDocumentPreview({{ $document->id }})"><x-gestionale.lucide name="file-text" :size="22" /><span><strong>{{ $document->title }}</strong><small>{{ $metadata['kind'] ?? 'Documento' }} · {{ $metadata['status'] ?? 'Da richiedere' }} · {{ ($metadata['internal'] ?? true) ? 'Interno' : 'Condivisibile' }}</small></span></button>
                @endforeach
            </div>
            @if ($this->documents->isEmpty())<p class="crm-muted">Registra una richiesta o una nota sul documento. Qui non si caricano allegati.</p>@endif
        </section>
    @elseif ($activeTab === 'Clienti compatibili')
        <section class="crm-panel"><h2>Richieste compatibili con questo immobile</h2><livewire:gestionale.crm-matches :property-id="$property->id" :key="'property-matches-'.$property->id" /></section>
    @else
        <section class="crm-panel"><x-gestionale.activity-timeline :activities="ActivityContext::activities($actor, ['property_id' => $property->id])" :scope="ActivityContext::scope(['property_id' => $property->id])" order="recent" /></section>
    @endif

    @if ($proposalOwnerId !== null)
        <x-gestionale.crm-dialog title="Proponi correzione" :close="'$wire.closeProposal()'">
            <form class="crm-form" wire:submit="submitProposal">
                <label class="crm-field"><span>Dati da verificare</span><textarea wire:model="proposalNote" required></textarea>@error('note')<small class="crm-error">{{ $message }}</small>@enderror</label>
                <button class="crm-btn" type="submit" wire:loading.attr="disabled" wire:target="submitProposal">Invia proposta</button>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($documentDialog === 'create')
        <x-gestionale.crm-dialog title="Registra documento" :close="'$wire.closeDocumentDialog()'">
            <form class="crm-form" wire:submit="saveDocument">
                <label class="crm-field"><span>Titolo</span><input type="text" wire:model="documentTitle" maxlength="160" required>@error('documentTitle')<small class="crm-error">{{ $message }}</small>@enderror</label>
                <label class="crm-field"><span>Tipo</span><select wire:model="documentKind"><option>Documento</option><option>Fotografia</option><option>Planimetria</option><option>Video</option></select></label>
                <label class="crm-field"><span>Stato</span><select wire:model="documentStatus"><option>Da richiedere</option><option>Richiesto</option><option>Ricevuto</option></select></label>
                <label class="crm-field"><span>Nota sul documento</span><textarea wire:model="documentContent" maxlength="5000"></textarea></label>
                <label class="crm-check"><input type="checkbox" wire:model="documentInternal">Materiale interno</label>
                <p class="crm-muted">Registra solo contenuti di fantasia. Foto, video e planimetrie reali non vengono caricati in questa demo.</p>
                <button class="crm-btn" type="submit">Salva documento</button>
            </form>
        </x-gestionale.crm-dialog>
    @elseif ($documentDialog === 'preview' && $this->previewDocument)
        <x-gestionale.crm-dialog :title="$this->previewDocument->title" :close="'$wire.closeDocumentDialog()'">
            @php($metadata = $this->previewDocument->metadata ?? [])
            <x-gestionale.crm-pill>{{ $metadata['status'] ?? 'Da richiedere' }}</x-gestionale.crm-pill>
            <p class="crm-copy">{{ ($metadata['content'] ?? '') ?: 'Nessuna nota inserita. Questa voce non contiene un allegato.' }}</p>
        </x-gestionale.crm-dialog>
    @endif
</div>
