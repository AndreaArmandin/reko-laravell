<?php

use App\Gestionale\CurrentAgency;
use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Actions\Census\ReviewCensusProposal;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CommandRejected;
use App\Models\CensusProposal;
use App\Gestionale\Goals\GoalEngine;
use App\Gestionale\Properties\PropertyFilters;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
use App\Models\Activity;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Panoramica operativa con i dati già disponibili nell'agenzia attiva.
 */
new #[Layout('layouts::gestionale'), Title('Oggi')] class extends Component {
    #[Url(as: 'filtro')]
    public string $filtro = '';
    #[Url(as: 'completo')]
    public bool $fullOverview = false;
    public ?int $reviewProposalId = null;
    public string $proposalDecision = 'approve';
    public string $proposalReviewNote = '';
    public string $proposalError = '';

    public function mount(): void
    {
        app(GoalEngine::class)->ensureInitialGoals((int) $this->membership->agency_id);
    }

    public function openFullOverview(): void
    {
        $this->fullOverview = true;
    }

    /** Dopo un salvataggio dalle finestre delle attività: i riquadri si rileggono. */
    #[On('activities-changed')]
    public function refreshActivities(): void
    {
        unset($this->visibleActivities, $this->agenda, $this->activityProgress, $this->receivedActivities,
            $this->scheduledRecalls, $this->callbackClients, $this->followups, $this->stats);
    }

    #[Computed]
    public function membership()
    {
        return app(CurrentAgency::class)->membership();
    }

    #[Computed]
    public function pendingCensusProposals()
    {
        abort_unless($this->membership->role === 'admin', 403);

        return CensusProposal::query()->where('agency_id', $this->membership->agency_id)
            ->where('status', 'Da verificare')->with('proposedBy')->orderBy('created_at')->orderBy('id')->get();
    }

    public function openProposalReview(int $proposalId, string $decision): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        $this->pendingCensusProposals->firstWhere('id', $proposalId) ?? abort(404);
        $this->reviewProposalId = $proposalId;
        $this->proposalDecision = $decision;
        $this->proposalReviewNote = '';
        $this->proposalError = '';
    }

    public function closeProposalReview(): void
    {
        $this->reviewProposalId = null;
        $this->proposalReviewNote = '';
        $this->proposalError = '';
    }

    public function reviewProposal(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if ($this->reviewProposalId === null) {
            abort(404);
        }
        try {
            app(ReviewCensusProposal::class)->handle($this->membership, $this->reviewProposalId, [
                'decision' => $this->proposalDecision,
                'reason' => $this->proposalReviewNote,
            ]);
        } catch (CommandRejected $e) {
            $this->proposalError = $e->getMessage();

            return;
        }
        $approved = $this->proposalDecision === 'approve';
        $this->closeProposalReview();
        unset($this->pendingCensusProposals);
        $this->dispatch('crm-notice', type: 'success', text: $approved ? 'Proposta approvata e registrata nello storico' : 'Proposta rifiutata e registrata nello storico');
    }

    #[Computed]
    public function stats(): array
    {
        $m = $this->membership;
        $progress = $this->activityProgress;
        $clients = Contact::query()->visibleTo($m)->whereHas('clientProfile', fn ($q) => $q->activeRecords());
        $requests = PropertyRequest::query()->visibleTo($m)->activeRecords()
            ->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata']);
        $stats = [
            'activityRemaining' => $progress['remaining'],
            'activityTotal' => $progress['total'],
            'overdue' => $progress['overdueCount'],
            'callback' => (clone $clients)->whereHas('clientProfile', fn ($q) => $q->needsCallback())->count(),
            'incomplete' => (clone $requests)->where('finished', false)->count(),
            'compatible' => PropertyMatch::query()->where('score', '>=', 80)
                ->whereNotIn('status', ['Rifiutato', 'Non compatibile', 'Concluso'])
                ->whereHas('propertyRequest', fn ($q) => $q->visibleTo($m)->activeRecords()
                    ->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata']))->count(),
        ];

        if ($m->role === 'scout') {
            $stats['owners'] = CensusScope::units($m)
                ->join('ownerships as ow', 'ow.cadastral_unit_id', '=', 'o.cadastral_unit_id')
                ->join('contacts as owner', 'owner.id', '=', 'ow.contact_id')
                ->whereNull('ow.valid_to')->whereNull('owner.removed_at')->distinct()->count('owner.id');
            $stats['properties'] = Property::query()->where('agency_id', $m->agency_id)
                ->where(fn (Builder $q) => $q->where('acquired_by_user_id', $m->user_id)
                    ->orWhere('agent_user_id', $m->user_id)
                    ->orWhereJsonContains('assigned_scout_user_ids', (int) $m->user_id))->count();
        }

        return $stats;
    }

    #[Computed]
    public function dashboardWidgets(): array
    {
        $scout = $this->membership->role === 'scout';
        $widgets = [
            ['id' => 'overview', 'title' => 'A colpo d’occhio', 'description' => 'Conteggi e accessi rapidi', 'wide' => true, 'hidden' => true],
            ['id' => 'activities', 'title' => 'Attività e scadenze', 'description' => 'Prima le scadenze da recuperare'],
            ['id' => 'goals', 'title' => 'Obiettivi', 'description' => 'Avanzamento del periodo', 'pinned' => true],
        ];
        if (! $scout) {
            $widgets[] = ['id' => 'requests', 'title' => 'Richieste da completare', 'description' => 'Profilazioni da riprendere'];
        }
        $widgets[] = ['id' => 'listings', 'title' => 'Annunci e schede', 'description' => 'Immobili e annunci da completare'];
        $widgets[] = ['id' => 'received', 'title' => 'Attività ricevute', 'description' => 'Impegni assegnati dagli altri operatori'];
        if (! $scout) {
            $widgets[] = ['id' => 'followups', 'title' => 'Clienti e trattative', 'description' => 'Ricontatti, visite e risposte in attesa', 'hidden' => true];
            $widgets[] = ['id' => 'active-requests', 'title' => 'Richieste in movimento', 'description' => 'Ultime richieste attive', 'hidden' => true];
        }
        $widgets[] = ['id' => 'properties', 'title' => 'Ultimi immobili acquisiti', 'description' => 'Le ultime schede nel tuo portafoglio', 'wide' => true, 'hidden' => true];

        return $widgets;
    }

    /**
     * Every activity the member can see (permissions.ts canSeeActivity, the same rule as the agenda), not
     * cancelled and not a suspended acquisition. Completed ones included: the counters below split them.
     */
    #[Computed]
    public function visibleActivities()
    {
        return app(ActivityAccess::class)
            ->visible($this->membership, fn ($query) => $query->with(['contact', 'property'])->where('status', '<>', 'Annullata'))
            ->reject(fn (Activity $activity) => ActivityCatalog::suspendedAcquisition($activity))->values();
    }

    #[Computed]
    public function agenda()
    {
        return $this->visibleActivities->reject(fn (Activity $activity) => $activity->status === 'Completata')
            ->sortBy([fn (Activity $a, Activity $b) => ($a->scheduled_at === null) <=> ($b->scheduled_at === null),
                fn (Activity $a, Activity $b) => $a->scheduled_at <=> $b->scheduled_at])->values();
    }

    #[Computed]
    public function scheduledRecalls(): int
    {
        return $this->agenda->filter(fn (Activity $a) => in_array($a->kind, ['Telefonata', 'Promemoria'], true) && $a->scheduled_at !== null)->count();
    }

    #[Computed]
    public function questionnaire(): Questionnaire
    {
        return Questionnaire::forAgency((int) $this->membership->agency_id);
    }

    #[Computed]
    public function requestsToComplete()
    {
        return PropertyRequest::query()->visibleTo($this->membership)->activeRecords()->where('finished', false)
            ->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata'])->with('contact')->orderByDesc('updated_at')->limit(4)->get();
    }

    #[Computed]
    public function activeRequests()
    {
        return PropertyRequest::query()->visibleTo($this->membership)->activeRecords()
            ->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata'])->with('contact')->orderByDesc('updated_at')->limit(4)->get();
    }

    #[Computed]
    public function incompleteProperties()
    {
        $query = Property::query()->visibleTo($this->membership)->whereNull('lifecycle_state');
        PropertyFilters::incomplete($query);

        return $query->orderByDesc('acquired_at')->limit(3)->get();
    }

    #[Computed]
    public function incompletePropertyCount(): int
    {
        $query = Property::query()->visibleTo($this->membership)->whereNull('lifecycle_state');
        PropertyFilters::incomplete($query);

        return $query->count();
    }

    #[Computed]
    public function receivedActivities()
    {
        $actor = $this->membership;
        $items = app(ActivityAccess::class)->visible($actor, fn ($query) => $query->whereNotIn('status', ['Completata', 'Annullata'])
            ->where('assigned_to_user_id', $actor->user_id)->where('created_by_user_id', '!=', $actor->user_id));

        return ActivityCatalog::sort($items->reject(fn (Activity $a) => ActivityCatalog::suspendedAcquisition($a)), 'priority');
    }

    #[Computed]
    public function callbackClients()
    {
        return Contact::query()->visibleTo($this->membership)->whereHas('clientProfile', fn ($q) => $q->activeRecords()->needsCallback())
            ->with('clientProfile')->orderBy('display_name')->limit(3)->get();
    }

    #[Computed]
    public function followups(): array
    {
        $activeRequests = fn ($query) => $query->visibleTo($this->membership)->activeRecords()->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata']);
        $matches = PropertyMatch::query()->whereHas('propertyRequest', $activeRequests);
        $visits = $this->agenda->filter(fn (Activity $a) => $a->kind === 'Visita');

        return [
            'callbacks' => $this->stats['callback'] ?? 0,
            'awaiting' => (clone $matches)->whereIn('status', ['Proposto al cliente', 'In attesa di risposta'])->count(),
            'visitsToPlan' => (clone $matches)->where('status', 'Visita da programmare')->count(),
            'scheduledVisits' => $visits->count(),
            'negotiations' => (clone $matches)->where('status', 'In trattativa')->count(),
            'visitFollowups' => $visits->filter(fn (Activity $a) => $a->scheduled_at?->isPast())->count(),
        ];
    }

    #[Computed]
    public function latestProperties()
    {
        return Property::query()->visibleTo($this->membership)->whereNull('lifecycle_state')->with('agent')
            ->orderByDesc('acquired_at')->orderByDesc('id')->limit(3)->get();
    }

    #[Computed]
    public function activityProgress(): array
    {
        $today = CarbonImmutable::today();
        $activities = $this->agenda;
        $overdue = $activities->filter(fn (Activity $a) => ActivityCatalog::isOverdue($a));
        $dueToday = $activities->filter(fn (Activity $a) => $a->scheduled_at?->toDateString() === $today->toDateString() && ! ActivityCatalog::isOverdue($a));
        $now = CarbonImmutable::now();
        $plannedToday = $this->visibleActivities
            ->filter(fn (Activity $a) => $a->scheduled_at !== null
                && $a->scheduled_at >= $today->startOfDay() && $a->scheduled_at < $today->addDay()->startOfDay());
        $pendingToday = $plannedToday->reject(fn (Activity $a) => $a->status === 'Completata');
        $total = $plannedToday->count();
        $remaining = $pendingToday->count();
        $todayCount = $pendingToday->filter(fn (Activity $a) => $a->scheduled_at >= $now)->count();
        $overdueCount = $activities->filter(fn (Activity $a) => $a->scheduled_at !== null && $a->scheduled_at < $now)->count();

        return [
            'overdue' => $overdue->values(),
            'overdueCount' => $overdueCount,
            'today' => $dueToday->values(),
            'todayCount' => $todayCount,
            'remaining' => $remaining,
            'total' => $total,
        ];
    }
}; ?>

<div>
    @if ($filtro === 'approvals' && $this->membership->role === 'admin')
        {{-- proposals-panel.tsx: con il filtro "approvals" Oggi mostra solo questo pannello. --}}
        <section class="crm-panel" aria-label="Proposte da approvare">
            <h2>Da approvare <span>{{ $this->pendingCensusProposals->count() }}</span></h2>
            <p class="crm-muted">Proposte anagrafiche reali da verificare. Le pratiche di acquisizione restano sospese.</p>
            @forelse ($this->pendingCensusProposals as $proposal)
                <article class="crm-panel" wire:key="census-proposal-{{ $proposal->id }}">
                    <h3>{{ $proposal->payload['name'] ?? 'Correzione proposta' }}</h3>
                    <p>{{ $proposal->notes }}</p>
                    <p class="crm-muted">{{ $proposal->proposedBy?->name ?? 'Operatore' }} · {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($proposal->created_at, true) }}</p>
                    @if ($proposal->parcel_id || $proposal->cadastral_unit_id)<a class="crm-link" href="{{ route('gestionale.archive.index', $proposal->parcel_id ? ['parcel' => $proposal->parcel_id] : ['unit' => $proposal->cadastral_unit_id]) }}" wire:navigate>Apri la scheda collegata</a>@endif
                    <div class="crm-actions"><button type="button" class="crm-btn" wire:click="openProposalReview({{ $proposal->id }}, 'approve')">Approva</button><button type="button" class="crm-btn secondary" wire:click="openProposalReview({{ $proposal->id }}, 'reject')">Rifiuta</button></div>
                </article>
            @empty<div class="crm-empty">Nessuna proposta in attesa.</div>@endforelse
            @if ($reviewProposalId !== null)
                @php($reviewed = $this->pendingCensusProposals->firstWhere('id', $reviewProposalId))
                <x-gestionale.crm-dialog :title="$proposalDecision === 'approve' ? 'Approva proposta' : 'Rifiuta proposta'" :close="'$wire.closeProposalReview()'">
                    <form class="crm-form" wire:submit="reviewProposal">
                        <p>{{ ($reviewed?->payload['name'] ?? '') ?: $reviewed?->notes }}</p>
                        <p>L’esito registra la verifica. Non modifica automaticamente intestazioni, quote o dati catastali: eventuali correzioni si applicano dalla scheda con la fonte necessaria.</p>
                        <label class="crm-field"><span>{{ $proposalDecision === 'reject' ? 'Motivo del rifiuto' : 'Nota di verifica (facoltativa)' }}</span><textarea wire:model="proposalReviewNote" maxlength="4000" @required($proposalDecision === 'reject')></textarea></label>
                        @if ($proposalError !== '')<p class="crm-error" role="alert">{{ $proposalError }}</p>@endif
                        <button class="crm-btn" wire:loading.attr="disabled">{{ $proposalDecision === 'approve' ? 'Conferma approvazione' : 'Conferma rifiuto' }}</button>
                    </form>
                </x-gestionale.crm-dialog>
            @endif
        </section>
    @elseif (! $fullOverview)
        <x-gestionale.today-panel :membership="$this->membership" />
        <button class="crm-link" type="button" wire:click="openFullOverview">Apri Oggi completo e il layout personalizzato</button>
    @else
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">{{ auth()->user()->name }}</p>
            <h1>Il lavoro di oggi</h1>
        </div>
        <div class="crm-actions">
            @if ($this->membership->role !== 'scout')
                <a class="crm-btn" href="{{ route('gestionale.clients.create') }}" wire:navigate><x-gestionale.lucide name="users-round" :size="17" />Nuovo cliente</a>
            @else
                <a class="crm-btn" href="{{ route('gestionale.scouting.index') }}" wire:navigate>Apri mappa e zone</a>
            @endif
        </div>
    </div>

    @if ($this->membership->role !== 'scout')
        <section class="crm-panel crm-first-steps" x-data="{ visible: true, key: @js('reko-first-steps:'.$this->membership->user_id), init() { try { this.visible = localStorage.getItem(this.key) !== 'closed' } catch { this.visible = true } }, close() { this.visible = false; try { localStorage.setItem(this.key, 'closed') } catch {} } }" x-show="visible" x-cloak aria-label="Guida iniziale">
            <div class="crm-between"><h2>Inizia da qui</h2><button class="crm-icon-button" type="button" aria-label="Chiudi guida iniziale" x-on:click="close()"><x-gestionale.lucide name="x" :size="18" /></button></div>
            <ol>
                <li><a href="{{ route('trova') }}">Cerca immobili</a><span>Scegli zona e caratteristiche.</span></li>
                <li><a class="crm-link" href="{{ route('gestionale.clients.create') }}" wire:navigate>Crea un cliente</a><span>Bastano nome e un recapito.</span></li>
                <li><a class="crm-link" href="{{ route('gestionale.requests.index') }}" wire:navigate>Collega una richiesta</a><span>Raccogli le esigenze e confronta gli immobili.</span></li>
            </ol>
        </section>
    @endif

    @php($overdueCount = $this->activityProgress['overdue']->count())
    @if ($overdueCount > 0)
        <div class="crm-overdue-notice" role="status"><strong>{{ $overdueCount }} {{ $overdueCount === 1 ? 'attività scaduta' : 'attività scadute' }}</strong><a class="crm-link" href="{{ route('gestionale.activities.index', ['filter' => 'scadute']) }}" wire:navigate>Vedi cosa recuperare <x-gestionale.lucide name="arrow-up-right" :size="16" /></a></div>
    @endif

    <section class="{{ $this->membership->role === 'scout' ? 'crm-recalls-single' : 'crm-form-grid' }}" aria-label="Richiami e suggerimenti">
        <section class="crm-panel">
            <h2>Richiami programmati</h2>
            <p class="crm-muted">Telefonate e promemoria ancora da completare.</p>
            <p class="crm-recall-count"><strong>{{ $this->scheduledRecalls }}</strong> {{ $this->scheduledRecalls === 1 ? 'richiamo con una data fissata' : 'richiami con una data fissata' }}</p>
            <a class="crm-link" href="{{ route('gestionale.activities.index') }}" wire:navigate>Apri agenda <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
        </section>
        @if ($this->membership->role !== 'scout')
            <section class="crm-panel">
                <h2>Clienti da risentire</h2>
                <p class="crm-muted">Contatti da riprendere in base alle ultime attività. Non sono appuntamenti.</p>
                @foreach ($this->callbackClients as $client)<a class="crm-next-action" href="{{ route('gestionale.clients.show', $client) }}" wire:navigate><span>{{ $client->display_name }}</span><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>@endforeach
                @if ($this->callbackClients->isEmpty())<p>Nessun suggerimento al momento.</p>@endif
            </section>
        @endif
    </section>

    <div class="reko-dashboard" x-data="{
        definitions: @js($this->dashboardWidgets),
        key: @js('reko.dashboard.v1.'.rawurlencode((string) $this->membership->user_id).'.'.$this->membership->role),
        layout: [], saved: [], editing: false, ready: false, message: '',
        init() { try { this.layout = this.normalize(JSON.parse(localStorage.getItem(this.key) || 'null')) } catch { this.layout = this.normalize(null); this.message = 'La disposizione salvata non è disponibile. Puoi personalizzarla di nuovo.' } this.saved = JSON.parse(JSON.stringify(this.layout)); this.ready = true },
        normalize(entries) { const seen = new Set(); const saved = (Array.isArray(entries) ? entries : []).flatMap(item => { const widget = this.definitions.find(w => w.id === item?.id); if (!widget || seen.has(item.id)) return []; seen.add(item.id); return [{ id: item.id, visible: widget.pinned || (typeof item.visible === 'boolean' ? item.visible : !widget.hidden), wide: typeof item.wide === 'boolean' ? item.wide : !!widget.wide }] }); return [...saved, ...this.definitions.filter(w => !seen.has(w.id)).map(w => ({ id: w.id, visible: !w.hidden, wide: !!w.wide }))] },
        isVisible(id) { return !!this.layout.find(w => w.id === id)?.visible },
        isWide(id) { return !!this.layout.find(w => w.id === id)?.wide },
        orderOf(id) { return this.layout.findIndex(w => w.id === id) },
        setVisible(id, visible) { const widget = this.definitions.find(w => w.id === id); this.layout = this.layout.map(w => w.id === id ? { ...w, visible: widget?.pinned ? true : visible } : w) },
        toggleWide(id) { this.layout = this.layout.map(w => w.id === id ? { ...w, wide: !w.wide } : w) },
        move(id, direction) { const visible = this.layout.filter(w => w.visible), index = visible.findIndex(w => w.id === id), next = [...this.layout]; let from = next.findIndex(w => w.id === id), to = from + direction; while (to >= 0 && to < next.length && !next[to].visible) to += direction; if (from >= 0 && to >= 0 && to < next.length) [next[from], next[to]] = [next[to], next[from]]; this.layout = next; const widget = this.definitions.find(w => w.id === id); if (index >= 0 && widget) this.message = `${widget.title}: posizione ${direction < 0 ? index : index + 2} di ${visible.length}.` },
        moveEnabled(id, direction) { const visible = this.layout.filter(w => w.visible), index = visible.findIndex(w => w.id === id); return direction < 0 ? index > 0 : index >= 0 && index < visible.length - 1 },
        resetLayout() { this.layout = this.normalize(null) },
        cancelLayout() { this.layout = JSON.parse(JSON.stringify(this.saved)); this.editing = false; this.message = 'Modifiche alla disposizione annullate.' },
        saveLayout() { try { localStorage.setItem(this.key, JSON.stringify(this.layout)); this.saved = JSON.parse(JSON.stringify(this.layout)); this.editing = false; this.message = 'Disposizione salvata su questo dispositivo.' } catch { this.message = 'Il browser non consente il salvataggio. La disposizione resta aperta; puoi riprovare.' } }
    }" x-init="init()">
        <div class="reko-board-bar">
            <div><x-gestionale.lucide name="layout-dashboard" :size="19" /><h2>La tua area di lavoro</h2></div>
            <button class="crm-btn secondary" type="button" x-bind:disabled="!ready" x-bind:aria-expanded="editing" aria-controls="dashboard-customizer" x-on:click="if (editing) layout = JSON.parse(JSON.stringify(saved)); editing = !editing; message = ''"><x-gestionale.lucide name="settings-2" :size="16" /><span x-text="editing ? 'Chiudi personalizzazione' : 'Personalizza questa pagina'"></span></button>
        </div>
        <section id="dashboard-customizer" class="reko-board-settings" x-show="editing" x-cloak aria-label="Personalizza questa pagina">
            <div><h3>Scegli cosa tenere in primo piano</h3><p>Mostra le sezioni utili, spostale con le frecce e scegli la larghezza. Su telefono si dispongono in una colonna.</p></div>
            <div class="reko-widget-picker"><template x-for="widget in definitions" x-bind:key="widget.id"><label><input type="checkbox" x-bind:checked="isVisible(widget.id)" x-on:change="setVisible(widget.id, $event.target.checked)"><span><strong x-text="widget.title"></strong><small x-text="widget.description"></small></span></label></template></div>
            <div class="reko-board-settings-footer"><p>Preferenze personali su questo dispositivo. Non cambiano dati o permessi.</p><div class="crm-actions"><button class="crm-btn secondary" type="button" x-on:click="resetLayout()"><x-gestionale.lucide name="undo-2" :size="16" />Ripristina disposizione</button><button class="crm-btn secondary" type="button" x-on:click="cancelLayout()">Annulla</button><button class="crm-btn" type="button" x-on:click="saveLayout()"><x-gestionale.lucide name="check" :size="16" />Salva disposizione</button></div></div>
        </section>
        <p class="reko-board-message" role="status" aria-live="polite" x-text="message"></p>

        <div class="reko-widget-grid">
            <section class="reko-widget" x-cloak x-show="isVisible('overview')" x-bind:class="{ 'is-wide': isWide('overview') }" x-bind:style="{ order: orderOf('overview') }" aria-labelledby="widget-overview">
                <header class="reko-widget-head"><h2 id="widget-overview">A colpo d’occhio</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'overview', 'title' => 'A colpo d’occhio'])</header>
                <div class="reko-widget-body">
                    <div class="crm-kpis">
                        <a href="{{ route('gestionale.activities.index', ['filter' => 'oggi']) }}" wire:navigate>
                            <x-gestionale.lucide name="calendar-days" :size="19" /><strong>{{ $this->stats['activityRemaining'] }}/{{ $this->stats['activityTotal'] }}</strong><span>Attività di oggi da completare</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                        </a>
                        <a href="{{ route('gestionale.activities.index', ['filter' => 'scadute']) }}" wire:navigate>
                            <x-gestionale.lucide name="clipboard-list" :size="19" /><strong>{{ $this->stats['overdue'] }}</strong><span>Attività da recuperare</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                        </a>
                        @if ($this->membership->role === 'scout')
                            <a href="{{ route('gestionale.archive.index', ['vista' => 'Proprietari']) }}" wire:navigate>
                                <x-gestionale.lucide name="users-round" :size="19" /><strong>{{ $this->stats['owners'] }}</strong><span>Proprietari nel tuo archivio</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                            </a>
                            <a href="{{ route('gestionale.scouting.index') }}" wire:navigate>
                                <x-gestionale.lucide name="sparkles" :size="19" /><strong>{{ $this->stats['properties'] }}</strong><span>Immobili acquisiti / assegnati</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                            </a>
                        @else
                            <a href="{{ route('gestionale.requests.index', ['filtro' => 'incomplete']) }}" wire:navigate>
                                <x-gestionale.lucide name="users-round" :size="19" /><strong>{{ $this->stats['incomplete'] }}</strong><span>Richieste da completare</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                            </a>
                            <a href="{{ route('gestionale.requests.index', ['filtro' => 'compatibili']) }}" wire:navigate>
                                <x-gestionale.lucide name="sparkles" :size="19" /><strong>{{ $this->stats['compatible'] }}</strong><span>Abbinamenti da valutare ≥80</span><x-gestionale.lucide name="arrow-up-right" :size="17" />
                            </a>
                        @endif
                    </div>
                </div>
            </section>

            <section class="reko-widget" x-cloak x-show="isVisible('activities')" x-bind:class="{ 'is-wide': isWide('activities') }" x-bind:style="{ order: orderOf('activities') }" aria-labelledby="widget-activities">
                <header class="reko-widget-head"><h2 id="widget-activities">Attività e scadenze</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'activities', 'title' => 'Attività e scadenze'])</header>
                <div class="reko-widget-body">
                    <x-gestionale.activity-timeline :activities="$this->activityProgress['overdue']->concat($this->activityProgress['today'])->take(5)" scope="agenda" order="given" key-prefix="activities" />
                    <a class="crm-link" href="{{ route('gestionale.activities.index') }}" wire:navigate>Apri tutte le attività <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                </div>
            </section>

            <section class="reko-widget" x-cloak x-show="isVisible('goals')" x-bind:class="{ 'is-wide': isWide('goals') }" x-bind:style="{ order: orderOf('goals') }" aria-labelledby="widget-goals">
                <header class="reko-widget-head"><h2 id="widget-goals">Obiettivi</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'goals', 'title' => 'Obiettivi'])</header>
                <div class="reko-widget-body"><x-gestionale.daily-goal-overview :membership="$this->membership" /><a class="crm-btn secondary" href="{{ route('gestionale.goals.index') }}" wire:navigate>Tutti gli obiettivi</a></div>
            </section>

            @if ($this->membership->role !== 'scout')
                <section class="reko-widget" x-cloak x-show="isVisible('requests')" x-bind:class="{ 'is-wide': isWide('requests') }" x-bind:style="{ order: orderOf('requests') }" aria-labelledby="widget-requests">
                    <header class="reko-widget-head"><h2 id="widget-requests">Richieste da completare</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'requests', 'title' => 'Richieste da completare'])</header>
                    <div class="reko-widget-body">
                        @foreach ($this->requestsToComplete as $request)<x-gestionale.crm-request-row :request="$request" :progress="App\Gestionale\Questionnaire\ProfileFlow::baseCompleteness($this->questionnaire, $request->criteria)" :show-activities="false" />@endforeach
                        @if ($this->requestsToComplete->isEmpty())<div class="crm-empty">Nessuna richiesta da completare.</div>@endif
                        <a class="crm-link" href="{{ route('gestionale.requests.index', ['filtro' => 'incomplete']) }}" wire:navigate>Apri richieste <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                    </div>
                </section>
            @endif

            <section class="reko-widget" x-cloak x-show="isVisible('listings')" x-bind:class="{ 'is-wide': isWide('listings') }" x-bind:style="{ order: orderOf('listings') }" aria-labelledby="widget-listings">
                <header class="reko-widget-head"><h2 id="widget-listings">Annunci e schede</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'listings', 'title' => 'Annunci e schede'])</header>
                <div class="reko-widget-body">
                    <div class="reko-task-links"><a href="{{ route('gestionale.properties.index', ['filtro' => 'incompleti']) }}" wire:navigate><span>Schede / annunci da completare</span><strong>{{ $this->incompletePropertyCount }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a></div>
                    @foreach ($this->incompleteProperties as $property)<a class="crm-next-action" href="{{ route('gestionale.properties.show', $property) }}" wire:navigate><span>{{ $property->title }}<small>{{ implode(' ', array_filter([$property->address, $property->civic])) }} · {{ ($property->publication['status'] ?? '') ?: 'Scheda da completare' }}</small></span><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>@endforeach
                </div>
            </section>

            <section class="reko-widget" x-cloak x-show="isVisible('received')" x-bind:class="{ 'is-wide': isWide('received') }" x-bind:style="{ order: orderOf('received') }" aria-labelledby="widget-received">
                <header class="reko-widget-head"><h2 id="widget-received">Attività ricevute</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'received', 'title' => 'Attività ricevute'])</header>
                <div class="reko-widget-body"><x-gestionale.activity-timeline :activities="array_slice($this->receivedActivities, 0, 5)" scope="agenda" order="given" key-prefix="received" /><a class="crm-link" href="{{ route('gestionale.activities.index') }}" wire:navigate>Tutte le attività</a></div>
            </section>

            @if ($this->membership->role !== 'scout')
                <section class="reko-widget" x-cloak x-show="isVisible('followups')" x-bind:class="{ 'is-wide': isWide('followups') }" x-bind:style="{ order: orderOf('followups') }" aria-labelledby="widget-followups">
                    <header class="reko-widget-head"><h2 id="widget-followups">Clienti e trattative</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'followups', 'title' => 'Clienti e trattative'])</header>
                    <div class="reko-widget-body"><div class="reko-task-links">
                        <a href="{{ route('gestionale.clients.index', ['filtro' => 'richiamare']) }}" wire:navigate><span>Clienti da risentire · suggerimenti</span><strong>{{ $this->followups['callbacks'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                        <a href="{{ route('gestionale.requests.index', ['filtro' => 'attesa']) }}" wire:navigate><span>In attesa di risposta</span><strong>{{ $this->followups['awaiting'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                        <a href="{{ route('gestionale.requests.index', ['filtro' => 'visite']) }}" wire:navigate><span>Visite da programmare</span><strong>{{ $this->followups['visitsToPlan'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                        <a href="{{ route('gestionale.activities.index', ['filter' => 'visite']) }}" wire:navigate><span>Visite programmate</span><strong>{{ $this->followups['scheduledVisits'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                        <a href="{{ route('gestionale.requests.index', ['filtro' => 'trattativa']) }}" wire:navigate><span>Trattative aperte</span><strong>{{ $this->followups['negotiations'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                        <a href="{{ route('gestionale.activities.index', ['filter' => 'riscontri']) }}" wire:navigate><span>Visite in attesa di riscontro</span><strong>{{ $this->followups['visitFollowups'] }}</strong><x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                    </div></div>
                </section>

                <section class="reko-widget" x-cloak x-show="isVisible('active-requests')" x-bind:class="{ 'is-wide': isWide('active-requests') }" x-bind:style="{ order: orderOf('active-requests') }" aria-labelledby="widget-active-requests">
                    <header class="reko-widget-head"><h2 id="widget-active-requests">Richieste in movimento</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'active-requests', 'title' => 'Richieste in movimento'])</header>
                    <div class="reko-widget-body">
                        @foreach ($this->activeRequests as $request)<x-gestionale.crm-request-row :request="$request" :progress="App\Gestionale\Questionnaire\ProfileFlow::baseCompleteness($this->questionnaire, $request->criteria)" />@endforeach
                        @if ($this->activeRequests->isEmpty())<div class="crm-empty">Nessuna richiesta attiva.</div>@endif
                        <a class="crm-link" href="{{ route('gestionale.requests.index') }}" wire:navigate>Tutte le richieste</a>
                    </div>
                </section>
            @endif

            <section class="reko-widget" x-cloak x-show="isVisible('properties')" x-bind:class="{ 'is-wide': isWide('properties') }" x-bind:style="{ order: orderOf('properties') }" aria-labelledby="widget-properties">
                <header class="reko-widget-head"><h2 id="widget-properties">Ultimi immobili acquisiti</h2>@include('pages.gestionale.home-widget-controls', ['id' => 'properties', 'title' => 'Ultimi immobili acquisiti'])</header>
                <div class="reko-widget-body">
                    <div class="crm-property-grid">@foreach ($this->latestProperties as $property)<x-gestionale.property-card :property="$property" />@endforeach</div>
                    @if ($this->latestProperties->isEmpty())<div class="crm-empty">Nessun immobile in portafoglio.</div>@endif
                    <a class="crm-link" href="{{ route('gestionale.properties.index') }}" wire:navigate>Apri portafoglio</a>
                </div>
            </section>
        </div>
        <div class="crm-empty" x-show="ready && !layout.some(w => w.visible)" x-cloak>
            <p>Nessuna sezione visibile. I tuoi dati sono al sicuro nelle sezioni del menu.</p>
            <button class="crm-btn secondary" type="button" x-on:click="editing = true">Scegli i riquadri</button>
        </div>
        <livewire:pages::gestionale.activities.dialogs scope="agenda" key="dashboard-agenda-dialogs" />
    </div>
    @endif
</div>
