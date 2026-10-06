<?php

use App\Gestionale\CurrentAgency;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\Activity;
use App\Models\Property;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Panoramica operativa con i dati già disponibili nell'agenzia attiva.
 */
new #[Layout('layouts::gestionale'), Title('Oggi')] class extends Component {
    #[Computed]
    public function membership()
    {
        return app(CurrentAgency::class)->membership();
    }

    #[Computed]
    public function stats(): array
    {
        $m = $this->membership;
        if (! in_array($m->role, ['admin', 'crm'], true)) {
            return [];
        }
        $clients = Contact::query()->visibleTo($m)->whereHas('clientProfile', fn ($q) => $q->activeRecords());

        return [
            'clients' => (clone $clients)->count(),
            'callback' => (clone $clients)->whereHas('clientProfile', fn ($q) => $q->needsCallback())->count(),
            'incomplete' => PropertyRequest::query()->visibleTo($m)->activeRecords()->where('finished', false)
                ->whereNotIn('status', ['Sospesa', 'Conclusa', 'Annullata'])->count(),
        ];
    }

    #[Computed]
    public function agenda()
    {
        $m = $this->membership;
        $query = Activity::query()->with(['contact', 'property'])->where('agency_id', $m->agency_id)
            ->whereNotIn('status', ['Completata', 'Annullata']);
        if ($m->role !== 'admin') {
            $query->where(function ($q) use ($m) {
                $q->where('assigned_to_user_id', $m->user_id)->orWhere('created_by_user_id', $m->user_id)
                    ->orWhere('user_id', $m->user_id)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $m->user_id));
                if ($m->role === 'crm') {
                    $q->orWhere(function ($workflow) use ($m) {
                        $workflow->where('visibility', 'workflow')->where(function ($related) use ($m) {
                            $related->whereHas('contact.clientProfile', fn ($c) => $c->where('agent_user_id', $m->user_id))
                                ->orWhereHas('propertyRequest', fn ($r) => $r->where('agent_user_id', $m->user_id))
                                ->orWhereHas('property', fn ($p) => $p->where('agent_user_id', $m->user_id));
                        });
                    });
                }
            });
        }
        return $query->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at')->limit(6)->get();
    }

    #[Computed]
    public function propertyCount(): int
    {
        return Property::query()->visibleTo($this->membership)->whereNull('lifecycle_state')->count();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Oggi</flux:heading>
        <flux:text class="mt-1">Buongiorno {{ auth()->user()->name }}. Ecco da dove ripartire.</flux:text>
    </div>

    @if ($this->stats)
        <div class="grid gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text>Clienti</flux:text>
                <flux:heading size="xl">{{ $this->stats['clients'] }}</flux:heading>
                <flux:link :href="route('gestionale.clients.index')" wire:navigate>Apri i clienti</flux:link>
            </flux:card>
            <flux:card>
                <flux:text>Da richiamare</flux:text>
                <flux:heading size="xl">{{ $this->stats['callback'] }}</flux:heading>
                <flux:link :href="route('gestionale.clients.index', ['filtro' => 'richiamare'])" wire:navigate>Vedi chi richiamare</flux:link>
            </flux:card>
            <flux:card>
                <flux:text>Richieste da completare</flux:text>
                <flux:heading size="xl">{{ $this->stats['incomplete'] }}</flux:heading>
                <flux:link :href="route('gestionale.requests.index', ['filtro' => 'incomplete'])" wire:navigate>Completa le richieste</flux:link>
            </flux:card>
        </div>
        <div class="flex gap-2">
            <flux:button variant="primary" icon="plus" :href="route('gestionale.requests.create')" wire:navigate>Nuova richiesta</flux:button>
            <flux:button icon="user-plus" :href="route('gestionale.clients.create')" wire:navigate>Nuovo cliente</flux:button>
            <flux:button icon="calendar-days" :href="route('gestionale.activities.index')" wire:navigate>Agenda</flux:button>
        </div>
    @else
        <flux:callout icon="map">
            <flux:callout.heading>Scouting e immobili</flux:callout.heading>
            <flux:callout.text>Apri la mappa per vedere le zone assegnate e il portafoglio per consultare gli immobili visibili al tuo ruolo.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="flex flex-col gap-3">
            <div class="flex items-center justify-between"><flux:heading size="lg">Agenda imminente</flux:heading><flux:link :href="route('gestionale.activities.index')" wire:navigate>Apri agenda</flux:link></div>
            @forelse ($this->agenda as $activity)
                <div class="flex justify-between gap-3 border-b border-zinc-200 py-2 last:border-0"><div><flux:heading size="sm">{{ $activity->subject }}</flux:heading><flux:text size="sm">{{ $activity->contact?->display_name ?? $activity->property?->title ?? $activity->kind }}</flux:text></div><flux:text size="sm">{{ $activity->scheduled_at?->format('d/m H:i') ?? 'Senza data' }}</flux:text></div>
            @empty
                <flux:text>Nessuna attività aperta. Puoi pianificare chiamate e appuntamenti dall’agenda.</flux:text>
            @endforelse
        </flux:card>
        <flux:card class="flex flex-col gap-3"><flux:heading size="lg">Portafoglio</flux:heading><flux:heading size="xl">{{ $this->propertyCount }}</flux:heading><flux:text>Immobili attivi visibili al tuo ruolo.</flux:text><flux:link :href="route('gestionale.properties.index')" wire:navigate>Apri immobili</flux:link></flux:card>
    </div>
</div>
