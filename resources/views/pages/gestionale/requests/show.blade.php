<?php

use App\Gestionale\Actions\Requests\AnswerPropertyRequestQuestion;
use App\Gestionale\Actions\Requests\FinishPropertyRequest;
use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Actions\Requests\StepPropertyRequest;
use App\Gestionale\Actions\Matches\UpdateMatchState;
use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Richiesta: profilazione guidata in 5 passi (profile.tsx), salvataggio automatico di ogni risposta
 * (request.answer), punto di ripresa (request.step), conferma (request.finish), riepilogo,
 * approfondimento facoltativo e specifiche manuali; "Gestisci richiesta" (request.save);
 * archiviazione reversibile e risultati di compatibilità con gli immobili.
 */
new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;
    use WithPagination;

    public PropertyRequest $request;

    public ?string $revision = null;

    /** @var array<string, mixed> form fields keyed by question id */
    public array $answers = [];

    public string $group = 'operation';

    public bool $review = false;

    public string $activeTab = 'Profilazione';

    public ?int $manageContactId = null;

    public string $manageStatus = '';

    public bool $manageRequestOpen = false;

    public bool $optionalQuestionsOpen = false;

    public bool $specificsOpen = false;

    public bool $removeRequestOpen = false;

    public bool $confirmRemove = false;

    public string $matchMinimum = '0';

    public string $matchStatus = 'Tutti';

    public ?int $matchEvidenceId = null;

    public ?int $matchStateId = null;

    public array $matchStateForm = [
        'state' => '', 'feedback' => '', 'rejection_reason' => '', 'note' => '', 'next_action' => '', 'visit_at' => '', 'confirmed' => false,
    ];

    public string $matchStateError = '';

    #[Computed]
    public function matches()
    {
        $minimum = in_array($this->matchMinimum, ['0', '60', '80'], true) ? (int) $this->matchMinimum : 0;
        $status = in_array($this->matchStatus, PropertyMatch::STATUSES, true) ? $this->matchStatus : 'Tutti';

        return PropertyMatch::query()->with('property')->where('agency_id', $this->request->agency_id)
            ->where('property_request_id', $this->request->id)
            ->whereHas('property', fn ($q) => $q->visibleTo($this->actor())->whereNull('lifecycle_state'))
            ->when($minimum > 0, fn ($q) => $q->where('score', '>=', $minimum))
            ->when($status !== 'Tutti', fn ($q) => $q->where('status', $status))
            ->orderByRaw('score DESC NULLS LAST')->orderByDesc('id')->paginate(10);
    }

    public function updatedMatchMinimum(): void
    {
        if (! in_array($this->matchMinimum, ['0', '60', '80'], true)) {
            $this->matchMinimum = '0';
        }
        $this->resetPage();
        unset($this->matches);
    }

    public function updatedMatchStatus(): void
    {
        if ($this->matchStatus !== 'Tutti' && ! in_array($this->matchStatus, PropertyMatch::STATUSES, true)) {
            $this->matchStatus = 'Tutti';
        }
        $this->resetPage();
        unset($this->matches);
    }

    public function openMatchEvidence(int $matchId): void
    {
        $this->authorize('view', $this->request);
        $match = PropertyMatch::query()->where('agency_id', $this->request->agency_id)
            ->where('property_request_id', $this->request->id)
            ->whereHas('property', fn ($q) => $q->visibleTo($this->actor())->whereNull('lifecycle_state'))
            ->findOrFail($matchId);
        $this->matchEvidenceId = $match->id;
        $this->matchStateId = $this->editable ? $match->id : null;
        $this->matchStateForm = [
            'state' => $match->status,
            'feedback' => (string) $match->feedback,
            'rejection_reason' => (string) $match->rejection_reason,
            'note' => (string) $match->note,
            'next_action' => (string) $match->next_action,
            'visit_at' => $match->visit_at?->format('Y-m-d\\TH:i') ?? '',
            'confirmed' => false,
        ];
        $this->matchStateError = '';
        unset($this->matchEvidence);
    }

    #[Computed]
    public function matchEvidence(): ?PropertyMatch
    {
        if ($this->matchEvidenceId === null) {
            return null;
        }

        return PropertyMatch::query()->with('property')->where('agency_id', $this->request->agency_id)
            ->where('property_request_id', $this->request->id)
            ->whereHas('property', fn ($q) => $q->visibleTo($this->actor())->whereNull('lifecycle_state'))
            ->find($this->matchEvidenceId);
    }

    public function closeMatchEvidence(): void
    {
        $this->matchEvidenceId = null;
        $this->matchStateId = null;
        $this->matchStateError = '';
        unset($this->matchEvidence);
    }

    public function saveMatchState(): void
    {
        $this->authorize('update', $this->request);
        if ($this->matchStateId === null) {
            return;
        }
        $match = PropertyMatch::query()->where('agency_id', $this->request->agency_id)
            ->where('property_request_id', $this->request->id)
            ->whereHas('property', fn ($q) => $q->visibleTo($this->actor())->whereNull('lifecycle_state'))
            ->findOrFail($this->matchStateId);
        try {
            app(UpdateMatchState::class)->handle($this->actor(), $match, $this->matchStateForm);
        } catch (CommandRejected $e) {
            $this->matchStateError = $e->getMessage();

            return;
        }
        $this->matchStateId = null;
        $this->matchEvidenceId = null;
        $this->matchStateError = '';
        unset($this->matchEvidence);
        unset($this->matches);
        $this->dispatch('crm-notice', type: 'success', text: 'Stato dell’abbinamento aggiornato e registrato nell’Agenda.');
    }

    public function mount(PropertyRequest $propertyRequest): void
    {
        $this->authorize('view', $propertyRequest);
        $this->request = $propertyRequest;
        $this->sync();
        $this->group = ProfileFlow::groupId($this->questionnaire, $propertyRequest->criteria, $propertyRequest->step_id);
        $this->review = Questionnaire::hasAnswer($propertyRequest->criteria['operation'] ?? null) && ($propertyRequest->finished || $propertyRequest->step_id === 'review');
        $this->activeTab = $propertyRequest->finished ? 'Abbinamenti' : 'Profilazione';
    }

    public function selectTab(string $tab): void
    {
        if (! in_array($tab, ['Profilazione', 'Abbinamenti', 'Cronologia'], true)) {
            return;
        }

        if ($tab === 'Abbinamenti' && ! Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function openManageRequest(): void
    {
        abort_unless($this->editable, 403);
        $this->manageRequestOpen = true;
    }

    public function closeManageRequest(): void
    {
        $this->manageRequestOpen = false;
    }

    public function openOptionalQuestions(): void
    {
        abort_unless($this->editable && Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null), 403);
        $this->optionalQuestionsOpen = true;
    }

    public function closeOptionalQuestions(): void
    {
        $this->optionalQuestionsOpen = false;
    }

    public function openSpecifics(): void
    {
        abort_unless($this->editable && Questionnaire::hasAnswer($this->request->criteria['operation'] ?? null), 403);
        $this->specificsOpen = true;
    }

    public function closeSpecifics(): void
    {
        $this->specificsOpen = false;
    }

    public function openRemoveRequest(): void
    {
        abort_unless($this->actor()->isAdmin() && $this->request->lifecycle_state !== 'removed', 403);
        $this->confirmRemove = false;
        $this->removeRequestOpen = true;
    }

    public function closeRemoveRequest(): void
    {
        $this->removeRequestOpen = false;
        $this->confirmRemove = false;
    }

    private function sync(?PropertyRequest $fresh = null): void
    {
        if ($fresh) {
            $this->request = $fresh;
        }
        $this->revision = Commands::revision($this->request);
        $this->answers = [];
        foreach ($this->questionnaire->questions() as $q) {
            $this->answers[$q['id']] = AnswerPresenter::toField($q, $this->request->criteria[$q['id']] ?? null);
        }
        $this->manageContactId = $this->request->contact_id;
        $this->manageStatus = $this->request->status;
        unset($this->groups, $this->progress);
    }

    #[Computed]
    public function questionnaire(): Questionnaire
    {
        return Questionnaire::forAgency((int) $this->request->agency_id);
    }

    #[Computed]
    public function groups(): array
    {
        return ProfileFlow::groups($this->questionnaire, $this->request->criteria);
    }

    #[Computed]
    public function progress(): array
    {
        return ProfileFlow::baseCompleteness($this->questionnaire, $this->request->criteria);
    }

    #[Computed]
    public function editable(): bool
    {
        return auth()->user()->can('update', $this->request);
    }

    #[Computed]
    public function clients()
    {
        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')->orderBy('display_name')->get(['id', 'display_name']);
    }

    /** Autosave: one request.answer per changed answer (650 ms debounce in the inputs). */
    public function updatedAnswers(mixed $value, string $key): void
    {
        $this->save(explode('.', $key)[0]);
    }

    public function clearAnswer(string $id): void
    {
        $question = $this->questionnaire->question($id);
        if ($question) {
            $this->answers[$id] = AnswerPresenter::toField($question, null);
            $this->save($id);
        }
    }

    public function classify(string $id, string $classification): void
    {
        $this->save($id, $classification);
    }

    private function save(string $id, ?string $classification = null): void
    {
        $question = $this->questionnaire->question($id);
        if (! $question || ! $this->editable) {
            return;
        }
        $value = AnswerPresenter::fromField($question, $this->answers[$id] ?? null);
        if ($classification === null && $value === ($this->request->criteria[$id] ?? null)) {
            return;
        }
        $this->resetErrorBag();
        try {
            $fresh = app(AnswerPropertyRequestQuestion::class)->handle($this->actor(), $this->request, array_filter([
                'question_id' => $id, 'value' => $value, 'classification' => $classification,
                'next_step' => $this->review ? 'review' : $this->group, 'expected_updated_at' => $this->revision,
            ], fn ($v, $k) => $k === 'value' || $v !== null, ARRAY_FILTER_USE_BOTH));
            $this->sync($fresh);
        } catch (CommandRejected $e) {
            $this->addError('answers.'.$id, $e->getMessage());
            if ($e->status() === 409) {
                $this->dispatch('crm-notice', type: 'error', text: $e->getMessage());
            }
        }
    }

    public function goStep(string $id): void
    {
        $target = collect($this->groups)->first(fn ($g) => $g['id'] === $id || in_array($id, array_column($g['questions'], 'id'), true))['id']
            ?? ProfileFlow::groupId($this->questionnaire, $this->request->criteria, $id);
        $fresh = $this->command(fn () => app(StepPropertyRequest::class)->handle($this->actor(), $this->request, $target, $this->revision));
        if ($fresh) {
            $this->sync($fresh);
            $this->group = $target;
            $this->review = false;
        }
    }

    public function advance(): void
    {
        $ids = array_column($this->groups, 'id');
        $next = $ids[array_search($this->group, $ids, true) + 1] ?? null;
        $next ? $this->goStep($next) : $this->finish();
    }

    public function back(): void
    {
        $ids = array_column($this->groups, 'id');
        $index = array_search($this->group, $ids, true);
        if ($index > 0) {
            $this->goStep($ids[$index - 1]);
        }
    }

    public function openReview(): void
    {
        $fresh = $this->command(fn () => app(StepPropertyRequest::class)->handle($this->actor(), $this->request, 'review', $this->revision), 'Riepilogo salvato');
        if ($fresh) {
            $this->sync($fresh);
            $this->review = true;
        }
    }

    public function finish(): void
    {
        $fresh = $this->command(fn () => app(FinishPropertyRequest::class)->handle($this->actor(), $this->request, $this->revision),
            'Preferenze registrate. Puoi aggiornarle quando vuoi.');
        if ($fresh) {
            $this->sync($fresh);
            $this->review = true;
        }
    }

    public function manage(): void
    {
        $fresh = $this->command(fn () => app(SavePropertyRequest::class)->handle($this->actor(), [
            'contact_id' => $this->manageContactId, 'status' => $this->manageStatus, 'expected_updated_at' => $this->revision,
        ], $this->request), 'Richiesta aggiornata.');
        if ($fresh) {
            $this->sync($fresh);
            $this->manageRequestOpen = false;
        }
    }

    public function lifecycle(string $mode): void
    {
        $done = $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $this->request, $mode, $this->confirmRemove),
            ['archive' => 'Richiesta archiviata.', 'remove' => 'Richiesta rimossa dalle liste.', 'restore' => 'Richiesta ripristinata.'][$mode] ?? null);
        if ($done) {
            $this->closeRemoveRequest();
            $this->sync($this->request->fresh());
        }
    }
}; ?>

@php
    $criteria = $request->criteria;
    $operationKnown = App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria['operation'] ?? null);
    $groups = $this->groups;
    $index = max(0, array_search($group, array_column($groups, 'id'), true) ?: 0);
    $current = $groups[$index];
    $progress = $this->progress;
    $optional = App\Gestionale\Questionnaire\ProfileFlow::optionalQuestions($this->questionnaire, $criteria);
    $guidedIds = array_merge(...array_map(fn ($g) => array_column($g['questions'], 'id'), $groups));
    $specifics = array_values(array_filter($this->questionnaire->manual($criteria),
        fn ($q) => $q['id'] !== 'tags' && ! in_array($q['id'], App\Gestionale\Questionnaire\ProfileFlow::OPTIONAL_IDS, true) && ! in_array($q['id'], $guidedIds, true)));
    $archived = array_values(array_filter($this->questionnaire->questions(),
        fn ($q) => ($q['collection'] ?? '') === 'archived' && App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)));
    $ready = collect($current['questions'])->every(fn ($q) => $q['skippable'] && $q['id'] !== 'operation' || App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null));
    $editable = $this->editable;
@endphp

<div>
    <a class="crm-back" href="{{ route('gestionale.requests.index') }}" wire:navigate>← Tutte le richieste</a>
    <header class="crm-page-head">
        <div>
            <p class="proto-eyebrow">Richiesta del cliente</p>
            <h1>{{ $request->title }}</h1>
            <div class="crm-inline-links">
                <x-gestionale.crm-pill>{{ $request->status }}</x-gestionale.crm-pill>
                @if ($request->lifecycle_state)<x-gestionale.crm-pill>{{ $request->lifecycle_state === 'removed' ? 'Rimossa dalle liste' : 'Archiviata' }}</x-gestionale.crm-pill>@endif
                @if (! $request->finished)<span>Completa dopo · il profilo resta salvato e riprende dal punto lasciato.</span>@endif
            </div>
        </div>
        @if ($editable)
            <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="openManageRequest">Gestisci richiesta</button></div>
        @endif
    </header>

    @if (App\Gestionale\Properties\TestRecords::isTestClient($request->contact))
        <div class="crm-contact-warning" role="note">TEST · Richiesta collegata a una scheda cliente di prova.</div>
    @endif

    <details class="crm-record-management">
        <summary>Archiviazione della richiesta</summary>
        <p class="crm-muted">Operazioni reversibili: risposte e storico restano conservati.</p>
        <div class="crm-actions">
            @if ($request->lifecycle_state)
                <button class="crm-btn secondary" type="button" wire:click="lifecycle('restore')">Ripristina</button>
            @elseif (auth()->user()->can('archive', $request))
                <button class="crm-btn secondary" type="button" wire:click="lifecycle('archive')">Archivia</button>
            @endif
            @if ($this->actor()->isAdmin() && $request->lifecycle_state !== 'removed')
                <button class="crm-btn secondary" type="button" wire:click="openRemoveRequest">Rimuovi dalle liste</button>
            @endif
        </div>
    </details>

    <div class="crm-request-meta">
        <a class="crm-link" href="{{ route('gestionale.clients.show', $request->contact_id) }}" wire:navigate>Apri scheda cliente · {{ $request->contact->display_name }}</a>
    </div>

    <x-gestionale.record-activities :context="['contact_id' => $request->contact_id, 'property_request_id' => $request->id]" />

    <nav class="crm-tabs" aria-label="Scheda richiesta">
        @foreach (['Profilazione', 'Abbinamenti', 'Cronologia'] as $tab)
            <button type="button" wire:click="selectTab('{{ $tab }}')" @if ($activeTab === $tab) aria-current="page" @endif @if ($tab === 'Abbinamenti' && ! $operationKnown) disabled @endif>{{ $tab }}</button>
        @endforeach
    </nav>

    @error('command') <p class="crm-error" role="alert">{{ $message }}</p> @enderror

    @if ($activeTab === 'Profilazione')
    <section class="crm-profile">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="crm-profile-top">
                <div><p class="proto-eyebrow">Profilazione rapida</p><h2>Le esigenze del cliente.</h2></div>
                <x-gestionale.crm-pill>{{ $progress['answered'] }}/{{ $progress['total'] }} risposte del percorso base</x-gestionale.crm-pill>
            </div>
        </div>
        <details class="crm-context-help">
            <summary>Come compilare la richiesta</summary>
            <p>Raccogli ciò che cerca il cliente. Indica metri quadri e camere da letto, non vani catastali. I campi facoltativi possono restare vuoti: negli abbinamenti saranno segnalati i dati da verificare.</p>
        </details>
        <div class="crm-progress" role="progressbar" aria-label="Completezza del percorso base" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $progress['percent'] }}%"></span></div>
        @if ($optional !== [])
            <div class="crm-profile-tools"><p class="crm-profile-intro">Cinque passaggi per le esigenze del cliente. Situazione abitativa e mutuo sono nell’approfondimento facoltativo.</p><button class="crm-btn secondary" type="button" wire:click="openOptionalQuestions" @disabled(! $operationKnown)>Approfondimento facoltativo{{ collect($optional)->contains(fn ($q) => App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null)) ? ' · dati già presenti' : '' }}</button></div>
        @endif

        @if (! $review)
            <div class="crm-question-nav">
                <button class="crm-link" type="button" wire:click="back" @disabled($index === 0)>← Indietro</button>
                <span>Passo {{ $index + 1 }} di {{ count($groups) }}</span>
                <button class="crm-link" type="button" wire:click="openReview" @disabled(! $operationKnown)>Riepilogo</button>
            </div>

            <div class="crm-question">
                <h2>{{ $current['title'] }}</h2>
                @if (! $operationKnown && $current['id'] !== 'operation')
                    <p class="crm-info">Scegli prima Acquisto o Locazione.</p>
                @endif
                @foreach ($current['questions'] as $question)
                    @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || (! $operationKnown && $question['id'] !== 'operation')])
                @endforeach
                @if ($editable)
                    <div class="crm-question-actions">
                        @if ($current['id'] === 'notes')
                            <button class="crm-btn secondary" type="button" wire:click="openSpecifics">Altre specifiche facoltative</button>
                        @endif
                        <button class="crm-btn" type="button" wire:click="advance" @disabled(! $ready)>
                            {{ $current['id'] === 'notes' ? 'Concludi e vedi il riepilogo' : 'Continua' }}
                        </button>
                    </div>
                @endif
            </div>
        @else
            @php
                $missing = $progress['total'] - $progress['answered'];
            @endphp
            <div class="crm-completion" role="status"><span aria-hidden="true">✓</span><div><p><strong>{{ $missing > 0 ? 'Salvato · '.$missing.($missing === 1 ? ' risposta ancora da definire' : ' risposte ancora da definire') : 'Salvato · tutte le risposte del percorso base sono presenti' }}</strong></p><small>I campi facoltativi possono restare da definire; gli abbinamenti useranno solo i dati disponibili.</small></div></div>
            @php
                $firstMissing = collect($groups)->flatMap(fn ($g) => $g['questions'])->first(fn ($q) => $q['id'] !== 'tags' && ! App\Gestionale\Questionnaire\Questionnaire::hasAnswer($criteria[$q['id']] ?? null));
            @endphp
            @if ($editable)
                <div class="crm-actions">
                    @if ($firstMissing)
                        <button class="crm-btn secondary" type="button" wire:click="goStep('{{ $firstMissing['id'] }}')">Completa le risposte mancanti</button>
                    @endif
                    <button class="crm-btn secondary" type="button" wire:click="goStep('{{ $groups[0]['id'] }}')">Modifica parametri richiesta</button>
                    <button class="crm-link" type="button" wire:click="openSpecifics">Note e tag</button>
                </div>
            @endif
            @if (! empty($criteria['tags']))
                <div class="crm-inline-links">@foreach ($criteria['tags'] as $tag)<x-gestionale.crm-pill>{{ $tag }}</x-gestionale.crm-pill>@endforeach</div>
            @endif
            <div class="crm-section-head"><h2>Riepilogo delle preferenze</h2></div>
            <div class="crm-review-grid">
                @foreach (collect($groups)->flatMap(fn ($g) => $g['questions'])->filter(fn ($q) => $q['id'] !== 'tags') as $q)
                    <button type="button" @if ($editable) wire:click="goStep('{{ $q['id'] }}')" @endif wire:key="rv-{{ $q['id'] }}">
                        <span>
                            <small>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}</small>
                            <strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']] ?? null) }}</strong>
                        </span>
                        <span aria-hidden="true">✎</span>
                    </button>
                @endforeach
            </div>
            @if (! $request->finished && $editable)
                <button class="crm-btn" type="button" wire:click="finish">Conferma le preferenze disponibili</button>
            @endif
        @endif
    </section>

    @elseif ($activeTab === 'Abbinamenti')
    <section class="crm-panel">
        <div class="crm-section-head"><div><h2>Immobili da confrontare con la richiesta</h2><p class="crm-muted">Punteggio e attendibilità sono distinti. Un requisito critico resta evidenziato, anche con un punteggio alto.</p></div><x-gestionale.crm-pill>{{ $this->matches->total() }} abbinamenti</x-gestionale.crm-pill></div>
        <div class="crm-toolbar">
            <label class="crm-field"><span>Compatibilità minima</span><select wire:model.live="matchMinimum"><option value="0">Tutti, anche da valutare</option><option value="60">Da 60 / 100</option><option value="80">Da 80 / 100</option></select></label>
            <label class="crm-field"><span>Stato abbinamento</span><select wire:model.live="matchStatus"><option value="Tutti">Tutti</option>@foreach (App\Models\PropertyMatch::STATUSES as $status)<option value="{{ $status }}">{{ $status }}</option>@endforeach</select></label>
            <span>{{ $this->matches->total() }} abbinamenti · ordinati per compatibilità</span>
        </div>
        <div class="crm-match-list">
        @forelse ($this->matches as $match)
            @php
                $comparisons = collect($match->result['comparisons'] ?? []);
                $critical = $comparisons->filter(fn ($item) => in_array($item['classification'] ?? '', ['Indispensabile', 'Da escludere'], true));
                $failedCritical = $critical->where('status', 'missing')->count();
                $unknownCritical = $critical->where('status', 'unknown')->count();
                $primaryReason = $comparisons->first(fn ($item) => ($item['status'] ?? '') === 'missing' && in_array($item['classification'] ?? '', ['Indispensabile', 'Da escludere'], true))
                    ?? $comparisons->first(fn ($item) => ($item['status'] ?? '') === 'unknown' && in_array($item['classification'] ?? '', ['Indispensabile', 'Da escludere'], true));
                $primaryReasonText = $primaryReason
                    ? (($primaryReason['status'] === 'unknown' ? 'Dato decisivo da verificare' : ($primaryReason['classification'] === 'Indispensabile' ? 'Requisito indispensabile non soddisfatto' : 'Elemento da escludere presente')).': '.$primaryReason['label'])
                    : collect($match->result['reasons'] ?? [])->first();
            @endphp
            <article class="crm-match-card">
                <div class="crm-score {{ $match->result['conditional'] ?? false ? 'conditional' : '' }} {{ $match->score === null ? 'unknown' : '' }}" title="Il punteggio considera solo i dati confrontabili. Verifica anche i requisiti e i dati mancanti."><strong>{{ $match->score !== null ? number_format((float) $match->score, 0) : '—' }}</strong>@if ($match->score !== null)<span>/ 100</span>@endif<small>Dati confrontati</small></div>
                <div class="crm-grow"><div class="crm-between"><a class="crm-title-link" href="{{ route('gestionale.properties.show', $match->property) }}" wire:navigate>{{ $match->property?->title ?? 'Immobile' }}</a><x-gestionale.crm-pill>{{ $match->status }}</x-gestionale.crm-pill></div>
                    <p>{{ collect([$match->property?->address, $match->property?->city])->filter()->unique()->join(' · ') }}</p>
                    <div class="crm-match-indicators">
                        @if ($failedCritical > 0)<x-gestionale.crm-pill>{{ 'Requisiti non rispettati: '.$failedCritical }}</x-gestionale.crm-pill>@endif
                        @if ($unknownCritical > 0)<x-gestionale.crm-pill>{{ 'Dati decisivi da verificare: '.$unknownCritical }}</x-gestionale.crm-pill>@endif
                        @if ($failedCritical === 0 && $unknownCritical === 0)<x-gestionale.crm-pill>{{ $match->score === null ? 'Da valutare' : 'Confronto disponibile' }}</x-gestionale.crm-pill>@endif
                        <span>{{ (int) ($match->result['compared'] ?? 0) }} criteri confrontati · {{ max(0, (int) ($match->result['answered'] ?? 0) - (int) ($match->result['compared'] ?? 0)) }} da verificare</span>
                    </div>
                    @if ($primaryReasonText)<p class="crm-muted">{{ App\Gestionale\Questionnaire\AnswerPresenter::operatorReason($primaryReasonText) }}@if (count($match->result['reasons'] ?? []) > 1) · altri {{ count($match->result['reasons']) - 1 }} dettagli @endif</p>@endif
                </div>
                <button class="crm-btn secondary" type="button" wire:click="openMatchEvidence({{ $match->id }})">Apri confronto</button>
            </article>
        @empty
            <div class="crm-empty">@if ($matchMinimum !== '0' || $matchStatus !== 'Tutti')Nessun abbinamento con questi filtri. Abbassa la soglia o scegli tutti gli stati.@else Nessun immobile in portafoglio compatibile per questa richiesta. Gli abbinamenti si aggiornano quando cambiano le preferenze o il portafoglio.@endif</div>
        @endforelse
        </div>
        {{ $this->matches->links() }}
    </section>
    @else
        <section class="crm-panel"><h2>Cronologia</h2><x-gestionale.activity-timeline :activities="\App\Gestionale\Activities\ActivityContext::activities($this->actor(), ['property_request_id' => $request->id])" scope="request-{{ $request->id }}" order="recent" /></section>
    @endif

    @if ($this->matchEvidence)
        @php
            $evidence = $this->matchEvidence;
            $result = $evidence->result ?? [];
            $comparisons = collect($result['comparisons'] ?? []);
            $critical = $comparisons->filter(fn ($item) => in_array($item['classification'] ?? '', ['Indispensabile', 'Da escludere'], true));
            $failedCritical = $critical->where('status', 'missing')->count();
            $unknownCritical = $critical->where('status', 'unknown')->count();
        @endphp
        <x-gestionale.crm-dialog title="Valuta l’abbinamento" label="Confronto tra richiesta e immobile" wide :close="'$wire.closeMatchEvidence()'">
            <div class="crm-dialog-summary">
                <div class="crm-score {{ $result['conditional'] ?? false ? 'conditional' : '' }} {{ $evidence->score === null ? 'unknown' : '' }}"><strong>{{ $evidence->score !== null ? number_format((float) $evidence->score, 0) : '—' }}</strong>@if ($evidence->score !== null)<span>/ 100</span>@endif<small>Dati confrontati</small></div>
                <div><h3>{{ $evidence->property?->title ?? 'Immobile' }}</h3><p>{{ $evidence->property?->address }} · {{ $evidence->property?->city }}</p><x-gestionale.crm-pill>{{ $evidence->status }}</x-gestionale.crm-pill>
                    <p>Attendibilità {{ strtolower($result['confidence'] ?? 'indicativa') }} · versione {{ $result['version'] ?? 1 }}</p>
                    <p>{{ (int) ($result['compared'] ?? 0) }} criteri confrontati · {{ max(0, (int) ($result['answered'] ?? 0) - (int) ($result['compared'] ?? 0)) }} da verificare
                        @if ($failedCritical > 0) · requisiti non rispettati: {{ $failedCritical }}@endif
                        @if ($unknownCritical > 0) · dati decisivi da verificare: {{ $unknownCritical }}@endif
                    </p>
                </div>
            </div>
            <p class="crm-info {{ $result['conditional'] ?? false ? 'warm' : '' }}">Il punteggio riguarda solo i dati confrontabili: 100/100 non significa che tutti i requisiti siano verificati. Un requisito non rispettato è diverso da un dato mancante. Controlla entrambi prima di proporre l’immobile.</p>
            <div class="crm-match-evidence">
                @foreach ([['satisfied', 'Cosa corrisponde'], ['missing', 'Cosa non coincide'], ['unknown', 'Cosa manca da verificare']] as [$evidenceStatus, $heading])
                    @php
                        $rows = $comparisons->where('status', $evidenceStatus);
                    @endphp
                    <section><h3>{{ $heading }}</h3>
                        @if ($rows->isNotEmpty())
                            <ul>@foreach ($rows as $item)
                                <li><strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel(['id' => $item['id'] ?? '', 'text' => $item['label'] ?? 'Criterio']) }}</strong> · {{ $item['classification'] ?? 'Preferibile' }}<br>
                                    Richiesta: {{ App\Gestionale\Questionnaire\AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['expected'] ?? 'Da definire')) }}<br>
                                    Immobile: {{ App\Gestionale\Questionnaire\AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['actual'] ?? 'Da definire')) }}
                                </li>
                            @endforeach</ul>
                        @else<p>Nessun criterio in questa categoria.</p>@endif
                    </section>
                @endforeach
            </div>
            <details class="crm-comparison" open><summary>Perché questo punteggio?</summary>
                @if ($comparisons->isNotEmpty())
                    <div class="crm-table-wrap"><table><thead><tr><th>Criterio</th><th>Richiesta</th><th>Immobile</th><th>Esito</th></tr></thead><tbody>
                        @foreach ($comparisons as $item)
                            @php
                                $outcome = match ($item['status'] ?? 'unknown') {
                                    'satisfied' => 'Corrisponde',
                                    'unknown' => 'Da verificare',
                                    default => ($item['classification'] ?? '') === 'Indispensabile' ? 'Requisito indispensabile non soddisfatto' : (($item['classification'] ?? '') === 'Da escludere' ? 'Elemento da escludere presente' : 'Non coincide'),
                                };
                            @endphp
                            <tr><td>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel(['id' => $item['id'] ?? '', 'text' => $item['label'] ?? 'Criterio']) }}<small>{{ $item['classification'] ?? 'Preferibile' }}</small></td>
                                <td>{{ App\Gestionale\Questionnaire\AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['expected'] ?? 'Da definire')) }}</td>
                                <td>{{ App\Gestionale\Questionnaire\AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['actual'] ?? 'Da definire')) }}</td>
                                <td><x-gestionale.crm-pill>{{ $outcome }}</x-gestionale.crm-pill></td></tr>
                        @endforeach
                    </tbody></table></div>
                @else<p>Servono alcune preferenze per un primo confronto. Non è un punteggio zero.</p>@endif
            </details>
            @if ($editable && $matchStateId !== null)
                <form class="crm-form" wire:submit="saveMatchState">
                    <h3>Valuta abbinamento</h3>
                    <label class="crm-field"><span>Stato</span><select wire:model.live="matchStateForm.state">@foreach (App\Models\PropertyMatch::STATUSES as $status)<option value="{{ $status }}">{{ $status }}</option>@endforeach</select></label>
                    @if ($matchStateForm['state'] === 'Proposto al cliente')
                        <p class="crm-info">La proposta viene registrata nell’Agenda. Da questa schermata non parte alcuna comunicazione.</p>
                        <label class="crm-check"><input type="checkbox" wire:model.live="matchStateForm.confirmed">Ho verificato i dati e confermo la registrazione della proposta</label>
                    @endif
                    @if (in_array($matchStateForm['state'], ['Rifiutato', 'Non compatibile'], true))
                        <label class="crm-field"><span>Motivo</span><select wire:model.live="matchStateForm.rejection_reason"><option value="">Seleziona un motivo</option>@foreach (App\Models\PropertyMatch::REJECTION_REASONS as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach</select></label>
                    @endif
                    @if ($matchStateForm['state'] === 'Visita programmata')
                        <label class="crm-field"><span>Data e ora della visita</span><input type="datetime-local" wire:model.live="matchStateForm.visit_at"></label>
                    @endif
                    <label class="crm-field"><span>Riscontro</span><textarea wire:model="matchStateForm.feedback" maxlength="4000"></textarea></label>
                    <label class="crm-field"><span>{{ $matchStateForm['state'] === 'Proposto al cliente' ? 'Nota della proposta' : 'Nota interna' }}</span><textarea wire:model="matchStateForm.note" maxlength="4000"></textarea></label>
                    <label class="crm-field"><span>Prossima azione</span><input wire:model="matchStateForm.next_action" maxlength="500"></label>
                    @if ($matchStateError !== '')<p class="crm-form-error" role="alert">{{ $matchStateError }}</p>@endif
                    <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Salva stato</button><button type="button" class="crm-btn secondary" wire:click="closeMatchEvidence">Chiudi</button></div>
                </form>
            @else
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeMatchEvidence">Chiudi</button></div>
            @endif
        </x-gestionale.crm-dialog>
    @endif

    @if ($manageRequestOpen)
        <x-gestionale.crm-dialog title="Gestisci richiesta" label="Richiesta del cliente" :close="'$wire.closeManageRequest()'">
            <form wire:submit="manage" class="crm-form">
                <label class="crm-field"><span>Cliente</span><select wire:model="manageContactId">@foreach ($this->clients as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select></label>
                <p class="crm-muted">Per cambiare cliente scegli una scheda già presente: il referente della richiesta diventa quello del cliente.</p>
                <label class="crm-field"><span>Gestione della richiesta</span><select wire:model="manageStatus">@foreach (App\Models\PropertyRequest::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
                @error('contact_id')<p class="crm-form-error">{{ $message }}</p>@enderror
                @error('status')<p class="crm-form-error">{{ $message }}</p>@enderror
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeManageRequest">Annulla</button><button class="crm-btn">Salva collegamento</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($optionalQuestionsOpen)
        <x-gestionale.crm-dialog title="Situazione abitativa e mutuo" label="Approfondimento facoltativo" wide :close="'$wire.closeOptionalQuestions()'">
            <div class="crm-form"><p class="crm-muted">Domande facoltative: non entrano nel punteggio.</p>
                @foreach ($optional as $question)@include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])@endforeach
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeOptionalQuestions">Chiudi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif

    @if ($specificsOpen)
        <x-gestionale.crm-dialog title="Note, tag e specifiche" label="Richiesta del cliente" wide :close="'$wire.closeSpecifics()'">
            <div class="crm-form">
                @foreach (array_filter($this->questionnaire->visible($criteria), fn ($q) => in_array($q['id'], ['notes', 'tags'], true)) as $question)@include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])@endforeach
                <h3>Aggiungi o modifica una specifica</h3>
                @foreach ($specifics as $question)@include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || ! $operationKnown])@endforeach
                @if ($archived !== [])<section class="crm-panel"><h3>Informazioni raccolte in precedenza</h3>@foreach ($archived as $q)<p>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($q) }}: <strong>{{ App\Gestionale\Questionnaire\AnswerPresenter::answerLabel($q['id'], $criteria[$q['id']]) }}</strong></p>@endforeach</section>@endif
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeSpecifics">Chiudi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif

    @if ($removeRequestOpen)
        <x-gestionale.crm-dialog title="Rimuovi dalle liste" label="Richiesta del cliente" :close="'$wire.closeRemoveRequest()'">
            <div class="crm-form"><p class="crm-muted">La richiesta esce dalle liste ma resta conservata. Puoi ripristinarla in qualsiasi momento.</p>
                <label class="crm-check"><input type="checkbox" wire:model="confirmRemove">Confermo la rimozione reversibile dalla lista</label>
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeRemoveRequest">Annulla</button><button type="button" class="crm-btn" wire:click="lifecycle('remove')">Rimuovi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif
</div>
