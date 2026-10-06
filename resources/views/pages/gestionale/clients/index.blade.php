<?php

use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\ClientProfile;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Clienti (clients.tsx Clients): ricerca per nome, telefono o email; Tutti / Da richiamare;
 * filtro per stato; solo schede attive, archiviate e rimosse in una sezione a parte.
 * Visibilità: Responsabile tutti, Segreteria solo i propri, Operatore nessuno.
 */
new #[Layout('layouts::gestionale'), Title('Clienti')] class extends Component {
    use HandlesCommands, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = Tutti, 'richiamare' = Da richiamare, oppure uno dei 9 stati */
    #[Url(as: 'filtro')]
    public string $filter = '';

    public bool $showInactive = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);
        if ($this->filter !== '' && $this->filter !== 'richiamare' && ! in_array($this->filter, ClientProfile::STATUSES, true)) {
            $this->filter = '';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function base(): Builder
    {
        $term = trim($this->search);

        return Contact::query()->visibleTo($this->actor())->whereHas('clientProfile')
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $digits = preg_replace('/\D+/', '', $term);
                $q->where(fn (Builder $w) => $w->where('display_name', 'ilike', $like)
                    ->orWhereHas('channels', fn (Builder $c) => $c->where('value', 'ilike', $like)
                        ->when(strlen($digits) >= 3, fn ($c) => $c->orWhere('normalized_value', 'like', '%'.$digits.'%'))));
            });
    }

    #[Computed]
    public function clients()
    {
        return $this->base()
            ->whereHas('clientProfile', function (Builder $p) {
                $p->activeRecords();
                match (true) {
                    $this->filter === 'richiamare' => $p->needsCallback(),
                    $this->filter !== '' => $p->where('status', $this->filter),
                    default => null,
                };
            })
            ->with(['clientProfile', 'primaryPhone', 'primaryEmail'])->withCount('propertyRequests')
            ->orderBy('display_name')->orderBy('id')
            ->paginate(25);
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'all' => $this->base()->whereHas('clientProfile', fn ($p) => $p->activeRecords())->count(),
            'callback' => $this->base()->whereHas('clientProfile', fn ($p) => $p->activeRecords()->needsCallback())->count(),
        ];
    }

    #[Computed]
    public function inactive()
    {
        return $this->base()->whereHas('clientProfile', fn ($p) => $p->whereNotNull('lifecycle_state'))
            ->with('clientProfile')->orderBy('display_name')->limit(200)->get();
    }

    public function restore(int $contactId): void
    {
        $contact = Contact::query()->visibleTo($this->actor())->findOrFail($contactId);
        $this->command(fn () => app(SetRecordLifecycle::class)->handle($this->actor(), $contact->clientProfile, 'restore'), 'Cliente ripristinato.');
        unset($this->clients, $this->counts, $this->inactive);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filter');
        $this->resetPage();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="crm-page-head">
        <div>
            <flux:heading size="xl" level="1">Clienti</flux:heading>
            <flux:text class="mt-1">Una relazione da seguire, anche quando le esigenze cambiano.</flux:text>
        </div>
        @can('create', App\Models\Contact::class)
            <flux:button variant="primary" icon="user-plus" :href="route('gestionale.clients.create')" wire:navigate>Nuovo cliente</flux:button>
        @endcan
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Cerca cliente" placeholder="Nome, telefono o email" />
        </div>
        <flux:button.group>
            <flux:button size="sm" :variant="$filter === '' ? 'primary' : 'outline'" wire:click="$set('filter', '')">Tutti · {{ $this->counts['all'] }}</flux:button>
            <flux:button size="sm" :variant="$filter === 'richiamare' ? 'primary' : 'outline'" wire:click="$set('filter', 'richiamare')">Da richiamare · {{ $this->counts['callback'] }}</flux:button>
        </flux:button.group>
        <div class="w-56">
            <flux:select wire:model.live="filter" label="Filtra per stato">
                <flux:select.option value="">Tutti</flux:select.option>
                <flux:select.option value="richiamare">Da richiamare</flux:select.option>
                @foreach (App\Models\ClientProfile::STATUSES as $status)
                    <flux:select.option :value="$status">{{ $status }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <flux:text>{{ $this->clients->total() }} {{ $this->clients->total() === 1 ? 'cliente' : 'clienti' }}</flux:text>
        @if ($filter !== '' || $search !== '')
            <flux:link as="button" wire:click="clearFilters">Rimuovi filtro</flux:link>
        @endif
    </div>

    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
        @forelse ($this->clients as $client)
            @php($profile = $client->clientProfile)
            @php($phone = $client->primaryPhone?->value)
            @php($wa = $phone && preg_match('/^(\+|00)/', trim($phone)) && preg_match('/^(?:\D*\d){6,15}\D*$/', $phone) ? preg_replace('/^00/', '', preg_replace('/\D+/', '', $phone)) : null)
            <div class="flex flex-wrap items-center gap-4 p-4" wire:key="c-{{ $client->id }}">
                <flux:avatar :name="$client->display_name" size="sm" />
                <div class="min-w-48 flex-1">
                    <flux:link :href="route('gestionale.clients.show', $client)" wire:navigate class="font-medium">{{ $client->display_name }}</flux:link>
                    <flux:text size="sm">Canale preferito: {{ $profile->preferred_channel }}</flux:text>
                </div>
                <flux:badge size="sm">{{ $profile->status }}</flux:badge>
                <flux:text size="sm" class="w-24">{{ $client->property_requests_count }} {{ $client->property_requests_count === 1 ? 'richiesta' : 'richieste' }}</flux:text>
                <div class="flex gap-1">
                    @if ($phone)
                        <flux:button size="xs" variant="ghost" icon="phone" :href="'tel:'.preg_replace('/[^\d+]/', '', $phone)" aria-label="Chiama" />
                    @endif
                    @if ($wa)
                        <flux:button size="xs" variant="ghost" icon="chat-bubble-left-right" :href="'https://wa.me/'.$wa" target="_blank" aria-label="WhatsApp" />
                    @endif
                    @if ($client->primaryEmail)
                        <flux:button size="xs" variant="ghost" icon="envelope" :href="'mailto:'.rawurlencode($client->primaryEmail->value)" aria-label="Email" />
                    @endif
                </div>
            </div>
        @empty
            <div class="p-6"><flux:text>Nessun cliente con questi filtri.</flux:text></div>
        @endforelse
    </div>

    {{ $this->clients->links() }}

    @if ($this->inactive->isNotEmpty())
        <div>
            <flux:button variant="ghost" size="sm" :icon="$showInactive ? 'chevron-down' : 'chevron-right'" wire:click="$toggle('showInactive')">
                Archiviati e rimossi · {{ $this->inactive->count() }}
            </flux:button>
            @if ($showInactive)
                <div class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
                    @foreach ($this->inactive as $client)
                        <div class="flex items-center gap-4 p-3" wire:key="i-{{ $client->id }}">
                            <flux:link :href="route('gestionale.clients.show', $client)" wire:navigate class="flex-1">{{ $client->display_name }}</flux:link>
                            <flux:badge size="sm" color="zinc">{{ $client->clientProfile->lifecycle_state === 'removed' ? 'Rimosso' : 'Archiviato' }}</flux:badge>
                            <flux:button size="xs" wire:click="restore({{ $client->id }})">Ripristina</flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
