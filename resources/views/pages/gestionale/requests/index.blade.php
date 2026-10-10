<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Properties\TestRecords;
use App\Gestionale\Questionnaire\AnswerPresenter;
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
 * Ricerche dei clienti (requests.tsx Requests): filtri per stato, cliente e testo; "incomplete" dalla dashboard;
 * prima le richieste aggiornate più di recente; archiviate e rimosse in una sezione a parte.
 * I filtri degli abbinamenti riprendono le viste della dashboard originale.
 */
new #[Layout('layouts::gestionale'), Title('Ricerche dei clienti')] class extends Component
{
    use HandlesCommands, WithPagination;

    private const FILTER_LABELS = [
        'incomplete' => 'Da completare',
        'compatibili' => 'Abbinamenti da valutare ≥80',
        'attesa' => 'In attesa di risposta',
        'visite' => 'Visite da programmare',
        'trattativa' => 'Trattative aperte',
    ];

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

    public bool $showInactive = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PropertyRequest::class);
        if (! array_key_exists($this->filter, self::FILTER_LABELS)) {
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

    #[Computed]
    public function inactive()
    {
        return $this->base()->whereNotNull('lifecycle_state')->with('contact')->orderByDesc('updated_at')->limit(200)->get();
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

    public function summary(PropertyRequest $r): string
    {
        $c = $r->criteria;
        $rent = ($c['operation'] ?? null) === 'Locazione';

        return implode(' · ', array_filter([
            Questionnaire::hasAnswer($c['budget'] ?? null) ? AnswerPresenter::money($c['budget']).($rent ? '/mese' : '') : '',
            Questionnaire::hasAnswer($c['zones'] ?? null) ? implode(', ', $c['zones']) : '',
            Questionnaire::hasAnswer($c['area'] ?? null) ? AnswerPresenter::answerLabel('area', $c['area']) : '',
            Questionnaire::hasAnswer($c['bedrooms'] ?? null) ? AnswerPresenter::answerLabel('bedrooms', $c['bedrooms']) : '',
        ]));
    }

    public function restore(int $id): void
    {
        $request = PropertyRequest::query()->visibleTo($this->actor())->findOrFail($id);
        $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $request, 'restore'), 'Richiesta ripristinata.');
        unset($this->requests, $this->inactive);
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'client', 'search', 'filter', 'tests');
        $this->resetPage();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">Ricerche dei clienti</p>
            <h1>Richieste</h1>
            <p class="crm-muted">Clienti, esigenze e immobili compatibili.</p>
        </div>
        @can('create', App\Models\PropertyRequest::class)
            <a class="crm-btn" href="{{ route('gestionale.requests.create') }}" wire:navigate><x-gestionale.lucide name="plus" :size="16" />Nuova richiesta</a>
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
        <span>{{ $this->requests->total() }} {{ $this->requests->total() === 1 ? 'richiesta' : 'richieste' }}</span>
        @if ($status !== '' || $client !== '' || $search !== '' || $filter !== '')
            <button class="crm-link" type="button" wire:click="clearFilters">Azzera filtri</button>
        @endif
    </div>

    <div class="flex items-center gap-3">
        @if (isset(self::FILTER_LABELS[$filter]))
            <span class="crm-pill">{{ self::FILTER_LABELS[$filter] }}</span>
            <button class="crm-link" type="button" wire:click="$set('filter', '')">Rimuovi filtro</button>
        @endif
    </div>
    <p class="crm-muted">Prima le richieste aggiornate più di recente.</p>

    <section class="crm-panel crm-request-list">
        @forelse ($this->requests as $request)
            @php($progress = App\Gestionale\Questionnaire\ProfileFlow::baseCompleteness($this->questionnaire, $request->criteria))
            @php($initials = collect(preg_split('/\s+/', trim($request->contact->display_name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''))
            @php($avatarTone = abs(crc32((string) $request->contact_id)) % 5)
            <article wire:key="r-{{ $request->id }}">
                <a class="crm-record-row crm-request-row" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>
                    <span aria-hidden="true" class="crm-avatar crm-avatar--{{ $avatarTone }}">{{ $initials }}</span>
                    <span class="crm-grow">
                        <strong>{{ $request->title }}</strong>
                        <small>{{ $request->contact->display_name }}</small>
                        @if (TestRecords::isTestClient($request->contact))<x-gestionale.crm-pill>TEST · dati fittizi</x-gestionale.crm-pill>@endif
                        @if ($summary = $this->summary($request))<small>{{ $summary }}</small>@endif
                    </span>
                    <span class="crm-row-tail">
                        <span class="crm-pill">{{ $request->status }}</span>
                        <span class="crm-answer-progress"><span>{{ $progress['answered'] }}/{{ $progress['total'] }} risposte del percorso base</span><progress max="{{ max(1, $progress['total']) }}" value="{{ min($progress['answered'], $progress['total']) }}" aria-label="Risposte completate"></progress></span>
                    </span>
                    <x-gestionale.lucide name="arrow-up-right" :size="16" aria-hidden="true" />
                </a>
            </article>
        @empty
            <div class="crm-empty">Nessuna richiesta con questi filtri. Puoi rimuoverli o creare una nuova richiesta.</div>
        @endforelse
    </section>

    {{ $this->requests->links() }}

    @if ($this->inactive->isNotEmpty())
        <div>
            <button class="crm-link" type="button" aria-expanded="{{ $showInactive ? 'true' : 'false' }}" wire:click="$toggle('showInactive')"><span aria-hidden="true">{{ $showInactive ? '⌄' : '›' }}</span> Archiviate e rimosse · {{ $this->inactive->count() }}</button>
            @if ($showInactive)
                <div class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
                    @foreach ($this->inactive as $request)
                        <div class="crm-record-row" wire:key="ri-{{ $request->id }}">
                            <a class="crm-title-link crm-grow" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>{{ $request->title }} · {{ $request->contact->display_name }}</a>
                            <x-gestionale.crm-pill>{{ $request->lifecycle_state === 'removed' ? 'Rimossa' : 'Archiviata' }}</x-gestionale.crm-pill>
                            <button class="crm-btn secondary" type="button" wire:click="restore({{ $request->id }})">Ripristina</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
