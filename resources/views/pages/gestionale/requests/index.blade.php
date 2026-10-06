<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Contact;
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
 * I filtri degli abbinamenti (compatibili, attesa, visite, trattativa) arrivano con la fase Matching.
 */
new #[Layout('layouts::gestionale'), Title('Ricerche dei clienti')] class extends Component {
    use HandlesCommands, WithPagination;

    #[Url(as: 'stato')]
    public string $status = '';

    #[Url(as: 'cliente')]
    public string $client = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'filtro')]
    public string $filter = '';

    public bool $showInactive = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PropertyRequest::class);
        if ($this->filter !== 'incomplete') {
            $this->filter = '';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function base(): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%';

        return PropertyRequest::query()->visibleTo($this->actor())
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', $like)
                ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'ilike', $like))))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->client !== '', fn ($q) => $q->where('contact_id', (int) $this->client))
            ->when($this->filter === 'incomplete', fn ($q) => $q->where('finished', false)->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata']));
    }

    #[Computed]
    public function requests()
    {
        return $this->base()->activeRecords()->with('contact')->orderByDesc('updated_at')->orderByDesc('id')->paginate(25);
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
        $this->reset('status', 'client', 'search', 'filter');
        $this->resetPage();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="crm-page-head">
        <div>
            <flux:text size="sm">Ricerche dei clienti</flux:text>
            <flux:heading size="xl" level="1">Richieste</flux:heading>
            <flux:text class="mt-1">Clienti, esigenze e immobili compatibili.</flux:text>
        </div>
        @can('create', App\Models\PropertyRequest::class)
            <flux:button variant="primary" icon="plus" :href="route('gestionale.requests.create')" wire:navigate>Nuova richiesta</flux:button>
        @endcan
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-56">
            <flux:select wire:model.live="status" label="Stato">
                <flux:select.option value="">Tutte</flux:select.option>
                @foreach (App\Models\PropertyRequest::STATUSES as $s)
                    <flux:select.option :value="$s">{{ $s }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-56">
            <flux:select wire:model.live="client" label="Cliente">
                <flux:select.option value="">Tutti</flux:select.option>
                @foreach ($this->clients as $c)
                    <flux:select.option :value="$c->id">{{ $c->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full max-w-sm">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Cerca" placeholder="Titolo o nome del cliente" />
        </div>
    </div>

    <div class="flex items-center gap-3">
        @if ($filter === 'incomplete')
            <flux:badge>Da completare</flux:badge>
            <flux:link as="button" wire:click="$set('filter', '')">Rimuovi filtro</flux:link>
        @endif
        @if ($status !== '' || $client !== '' || $search !== '' || $filter !== '')
            <flux:link as="button" wire:click="clearFilters">Azzera filtri</flux:link>
        @endif
        <flux:text size="sm">Prima le richieste aggiornate più di recente.</flux:text>
    </div>

    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
        @forelse ($this->requests as $request)
            @php($progress = App\Gestionale\Questionnaire\ProfileFlow::baseCompleteness($this->questionnaire, $request->criteria))
            <div class="flex flex-wrap items-center gap-4 p-4" wire:key="r-{{ $request->id }}">
                <flux:avatar :name="$request->contact->display_name" size="sm" />
                <div class="min-w-48 flex-1">
                    <flux:link :href="route('gestionale.requests.show', $request)" wire:navigate class="font-medium">{{ $request->title }}</flux:link>
                    <flux:text size="sm">{{ $request->contact->display_name }}@if ($s = $this->summary($request)) · {{ $s }}@endif</flux:text>
                </div>
                <flux:badge size="sm">{{ $request->status }}</flux:badge>
                <flux:text size="sm" class="w-16 text-end">{{ $progress['answered'] }}/{{ $progress['total'] }}</flux:text>
            </div>
        @empty
            <div class="p-6"><flux:text>Nessuna richiesta con questi filtri.</flux:text></div>
        @endforelse
    </div>

    {{ $this->requests->links() }}

    @if ($this->inactive->isNotEmpty())
        <div>
            <flux:button variant="ghost" size="sm" :icon="$showInactive ? 'chevron-down' : 'chevron-right'" wire:click="$toggle('showInactive')">
                Archiviate e rimosse · {{ $this->inactive->count() }}
            </flux:button>
            @if ($showInactive)
                <div class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
                    @foreach ($this->inactive as $request)
                        <div class="flex items-center gap-4 p-3" wire:key="ri-{{ $request->id }}">
                            <flux:link :href="route('gestionale.requests.show', $request)" wire:navigate class="flex-1">{{ $request->title }} · {{ $request->contact->display_name }}</flux:link>
                            <flux:badge size="sm" color="zinc">{{ $request->lifecycle_state === 'removed' ? 'Rimossa' : 'Archiviata' }}</flux:badge>
                            <flux:button size="xs" wire:click="restore({{ $request->id }})">Ripristina</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
