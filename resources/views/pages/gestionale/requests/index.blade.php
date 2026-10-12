<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Properties\TestRecords;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Richieste (requests.tsx Requests): filtri per stato e cliente; "incomplete" dalla dashboard;
 * prima le richieste aggiornate più di recente; archiviate e rimosse in una sezione a parte, senza filtri.
 * I filtri degli abbinamenti riprendono le viste della dashboard originale.
 * Il campo «Cerca» e la paginazione sono aggiunte del porting.
 */
new #[Layout('layouts::gestionale'), Title('Ricerche dei clienti')] class extends Component
{
    use HandlesCommands, WithPagination;

    /** Viste della panoramica: da completare e abbinamenti (compatibili, in attesa, visite, trattative). */
    private const FILTERS = ['incomplete', 'compatibili', 'attesa', 'visite', 'trattativa'];

    #[Url(as: 'stato')]
    public string $status = '';

    #[Url(as: 'cliente')]
    public string $client = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'filtro')]
    public string $filter = '';

    /** standard | test | all — test requests are identified by their explicit demo client marker. */
    #[Url(as: 'schede')]
    public string $tests = 'standard';

    public function mount(): void
    {
        $this->authorize('viewAny', PropertyRequest::class);
        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = '';
        }
        if (! in_array($this->tests, TestRecords::TEST_MODES, true)) {
            $this->tests = 'standard';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function base(): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';

        $query = PropertyRequest::query()->visibleTo($this->actor())
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', $like)
                ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'ilike', $like))))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->client !== '', fn ($q) => $q->where('contact_id', (int) $this->client))
            ->when($this->filter === 'incomplete', fn ($q) => $q->where('finished', false)->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata']));

        if ($this->filter === 'compatibili') {
            $query->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata'])
                ->whereHas('matches', fn (Builder $matches) => $matches->where('score', '>=', 80)
                    ->whereNotIn('status', ['Rifiutato', 'Non compatibile', 'Concluso']));
        } elseif (in_array($this->filter, ['attesa', 'visite', 'trattativa'], true)) {
            $statuses = match ($this->filter) {
                'attesa' => ['Proposto al cliente', 'In attesa di risposta'],
                'visite' => ['Visita da programmare'],
                'trattativa' => ['In trattativa'],
            };
            $query->whereHas('matches', fn (Builder $matches) => $matches->whereIn('status', $statuses));
        }

        return $query;
    }

    #[Computed]
    public function requests()
    {
        $query = $this->base()->activeRecords();
        $query = match ($this->tests) {
            'all' => $query,
            'test' => $query->whereHas('contact', fn (Builder $contact) => $contact->whereIn('display_name', TestRecords::TEST_CLIENT_NAMES)),
            default => $query->whereHas('contact', fn (Builder $contact) => $contact->whereNotIn('display_name', TestRecords::TEST_CLIENT_NAMES)),
        };

        return $query->with('contact')->orderByDesc('updated_at')->orderByDesc('id')->paginate(25);
    }

    #[Computed]
    public function testCount(): int
    {
        return PropertyRequest::query()->visibleTo($this->actor())->activeRecords()
            ->whereHas('contact', fn (Builder $contact) => $contact->whereIn('display_name', TestRecords::TEST_CLIENT_NAMES))
            ->count();
    }

    /** ArchivedRecords: tutte le schede archiviate o rimosse, non subiscono i filtri della lista. */
    #[Computed]
    public function inactive()
    {
        return PropertyRequest::query()->visibleTo($this->actor())->whereNotNull('lifecycle_state')->with('contact')
            ->orderByDesc('updated_at')->limit(200)->get();
    }

    #[Computed]
    public function clients()
    {
        return Contact::query()->whereIn('id', PropertyRequest::query()->visibleTo($this->actor())->select('contact_id'))->orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function questionnaire(): Questionnaire
    {
        return Questionnaire::forAgency((int) $this->actor()->agency_id);
    }

    private function lifecycle(int $id, string $mode, string $message): void
    {
        $request = PropertyRequest::query()->visibleTo($this->actor())->findOrFail($id);
        $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $request, $mode, $mode === 'remove'), $message);
        unset($this->requests, $this->inactive);
    }

    public function archive(int $id): void
    {
        $this->lifecycle($id, 'archive', 'Scheda archiviata. Puoi annullare con “Ripristina”.');
    }

    public function restore(int $id): void
    {
        $this->lifecycle($id, 'restore', 'Scheda ripristinata');
    }

    public function remove(int $id): void
    {
        $this->lifecycle($id, 'remove', 'Scheda rimossa dalla lista. Puoi ripristinarla dagli archiviati.');
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'client', 'search', 'filter', 'tests');
        $this->resetPage();
    }
}; ?>

<div>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Richieste</h1>
            <p class="crm-muted">Clienti, esigenze e immobili compatibili.</p>
        </div>
        @can('create', App\Models\PropertyRequest::class)
            <div class="crm-actions"><a class="crm-btn crm-mobile-primary" href="{{ route('gestionale.requests.create') }}" wire:navigate><x-gestionale.lucide name="plus" :size="16" />Nuova richiesta</a></div>
        @endcan
    </div>

    <div class="crm-toolbar">
        <label class="crm-field"><span>Schede da mostrare</span><select wire:model.live="tests">
            <option value="standard">Lista principale · test esclusi</option>
            <option value="test">Solo test ({{ $this->testCount }})</option>
            <option value="all">Tutte, compresi i test</option>
        </select></label>
        <label class="crm-field"><span>Stato</span><select wire:model.live="status">
            <option value="">Tutte</option>
            @foreach (App\Models\PropertyRequest::STATUSES as $s)
                <option value="{{ $s }}">{{ $s }}</option>
            @endforeach
        </select></label>
        <label class="crm-field"><span>Cliente</span><select wire:model.live="client">
            <option value="">Tutti</option>
            @foreach ($this->clients as $c)
                <option value="{{ $c->id }}">{{ $c->display_name }}</option>
            @endforeach
        </select></label>
        <label class="crm-field crm-request-search"><span>Cerca</span><input wire:model.live.debounce.300ms="search" placeholder="Titolo o nome del cliente" /></label>
        <span>{{ $this->requests->total() }} {{ $this->requests->total() === 1 ? 'richiesta' : 'richieste' }}{{ $filter !== '' ? ' · '.$filter : '' }}</span>
        @if ($filter !== '')
            <button class="crm-link" type="button" wire:click="$set('filter', '')">Rimuovi filtro</button>
        @endif
        <button class="crm-link" type="button" wire:click="clearFilters">Azzera filtri</button>
    </div>
    <p class="crm-muted">Prima le richieste aggiornate più di recente.</p>

    @if ($this->inactive->isNotEmpty())
        <details class="crm-operational-filters">
            <summary>Archiviati e rimossi · {{ $this->inactive->count() }}</summary>
            @foreach ($this->inactive as $archived)
                <div class="crm-record-row" wire:key="archived-{{ $archived->id }}">
                    <a class="crm-link" href="{{ route('gestionale.requests.show', $archived) }}" wire:navigate>{{ $archived->title }}</a>
                    <x-gestionale.crm-lifecycle kind="request" :id="$archived->id" :state="$archived->lifecycle_state" :at="$archived->lifecycle_at" :admin="$this->actor()->isAdmin()" />
                </div>
            @endforeach
        </details>
    @endif

    <section class="crm-panel crm-request-list">
        @forelse ($this->requests as $request)
            <x-gestionale.crm-request-row :request="$request" :progress="ProfileFlow::baseCompleteness($this->questionnaire, $request->criteria)" wire:key="r-{{ $request->id }}" />
        @empty
            <div class="crm-empty">Nessuna richiesta con questi filtri. Puoi rimuoverli o creare una nuova richiesta.</div>
        @endforelse
    </section>

    {{ $this->requests->links() }}
    <livewire:gestionale.request-form-modal :auto-open="request()->routeIs('gestionale.requests.create') ? (request()->query('cliente') ?? 'new') : null" />
</div>
