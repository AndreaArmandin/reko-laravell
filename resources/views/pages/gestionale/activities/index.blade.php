<?php

use App\Gestionale\Audit;
use App\Gestionale\CurrentAgency;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::gestionale'), Title('Agenda')] class extends Component {
    use WithPagination;

    public string $filter = 'aperte';
    public string $kind = 'Chiamata';
    public string $subject = '';
    public string $scheduledAt = '';
    public string $notes = '';
    public ?int $contactId = null;
    public ?int $propertyId = null;

    #[Computed]
    public function membership() { return app(CurrentAgency::class)->membership(); }

    #[Computed]
    public function activities()
    {
        $membership = $this->membership;
        $query = Activity::query()->with(['contact', 'property', 'user'])->where('agency_id', $membership->agency_id);
        if ($membership->role !== 'admin') {
            $query->where(function ($q) use ($membership) {
                $q->where('assigned_to_user_id', $membership->user_id)
                    ->orWhere('created_by_user_id', $membership->user_id)
                    ->orWhere('user_id', $membership->user_id)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $membership->user_id));
                if ($membership->role === 'crm') {
                    $q->orWhere(function ($workflow) use ($membership) {
                        $workflow->where('visibility', 'workflow')->where(function ($related) use ($membership) {
                            $related->whereHas('contact.clientProfile', fn ($c) => $c->where('agent_user_id', $membership->user_id))
                                ->orWhereHas('propertyRequest', fn ($r) => $r->where('agent_user_id', $membership->user_id))
                                ->orWhereHas('property', fn ($p) => $p->where('agent_user_id', $membership->user_id));
                        });
                    });
                }
            });
        }
        if ($this->filter === 'aperte') $query->whereNotIn('status', ['Completata', 'Annullata']);
        elseif ($this->filter === 'completate') $query->where('status', 'Completata');
        return $query->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at')->orderByDesc('id')->paginate(20);
    }

    #[Computed]
    public function contacts()
    {
        return Contact::query()->visibleTo($this->membership)->whereHas('clientProfile', fn ($q) => $q->activeRecords())
            ->orderBy('display_name')->limit(250)->get(['id', 'display_name']);
    }

    #[Computed]
    public function properties()
    {
        return Property::query()->visibleTo($this->membership)->whereNull('lifecycle_state')->orderBy('title')->limit(250)->get(['id', 'title']);
    }

    public function create(): void
    {
        $membership = $this->membership;
        $this->validate([
            'kind' => ['required', 'in:Chiamata,Visita,Appuntamento,Email,Promemoria,Altro'],
            'subject' => ['required', 'string', 'max:180'],
            'scheduledAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'contactId' => ['nullable', 'integer'],
            'propertyId' => ['nullable', 'integer'],
        ]);

        $contact = $this->contactId ? Contact::query()->visibleTo($membership)->findOrFail($this->contactId) : null;
        $property = $this->propertyId ? Property::query()->visibleTo($membership)->findOrFail($this->propertyId) : null;
        $agencyId = app(CurrentAgency::class)->require()->id;
        DB::transaction(function () use ($membership, $agencyId, $contact, $property) {
            $activity = new Activity;
            $activity->forceFill([
                'agency_id' => $agencyId,
                'user_id' => $membership->user_id,
                'created_by_user_id' => $membership->user_id,
                'assigned_to_user_id' => $membership->user_id,
                'contact_id' => $contact?->id,
                'property_id' => $property?->id,
                'kind' => $this->kind,
                'subject' => trim($this->subject),
                'status' => 'Da svolgere',
                'priority' => 'Normale',
                'scheduled_at' => $this->scheduledAt ?: null,
                'visibility' => 'workflow',
                'notes' => trim($this->notes) ?: null,
                'metadata' => [],
            ])->save();
            app(Audit::class)->record('activity.create', $activity, ['kind' => $activity->kind, 'subject' => $activity->subject]);
        });
        $this->reset('subject', 'scheduledAt', 'notes', 'contactId', 'propertyId');
        unset($this->activities);
        session()->flash('status', 'Attività aggiunta all’agenda.');
    }

    public function complete(int $id): void
    {
        $activity = Activity::query()->where('agency_id', $this->membership->agency_id)->findOrFail($id);
        $this->authorize('update', $activity);
        DB::transaction(function () use ($activity) {
            $before = $activity->status;
            $activity->forceFill(['status' => 'Completata', 'completed_at' => now()])->save();
            app(Audit::class)->record('activity.complete', $activity, ['before' => $before, 'after' => 'Completata']);
        });
        unset($this->activities);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><flux:heading size="xl" level="1">Agenda</flux:heading><flux:text class="mt-1">Chiamate, visite e promemoria dell’agenzia.</flux:text></div>
        <flux:select wire:model.live="filter" class="w-44"><flux:select.option value="aperte">Da svolgere</flux:select.option><flux:select.option value="completate">Completate</flux:select.option><flux:select.option value="tutte">Tutte</flux:select.option></flux:select>
    </div>
    @if (session('status'))<flux:callout icon="check-circle">{{ session('status') }}</flux:callout>@endif
    <flux:card>
        <form wire:submit="create" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <flux:input wire:model="subject" label="Attività" placeholder="Es. Richiamare per confermare la visita" />
            <flux:select wire:model="kind" label="Tipo"><flux:select.option>Chiamata</flux:select.option><flux:select.option>Visita</flux:select.option><flux:select.option>Appuntamento</flux:select.option><flux:select.option>Email</flux:select.option><flux:select.option>Promemoria</flux:select.option><flux:select.option>Altro</flux:select.option></flux:select>
            <flux:input type="datetime-local" wire:model="scheduledAt" label="Quando (facoltativo)" />
            <flux:select wire:model="contactId" label="Cliente (facoltativo)"><flux:select.option value="">Nessun cliente</flux:select.option>@foreach ($this->contacts as $contact)<flux:select.option value="{{ $contact->id }}">{{ $contact->display_name }}</flux:select.option>@endforeach</flux:select>
            <flux:select wire:model="propertyId" label="Immobile (facoltativo)"><flux:select.option value="">Nessun immobile</flux:select.option>@foreach ($this->properties as $property)<flux:select.option value="{{ $property->id }}">{{ $property->title }}</flux:select.option>@endforeach</flux:select>
            <flux:textarea wire:model="notes" label="Note" rows="2" />
            <div class="md:col-span-2 xl:col-span-3"><flux:button variant="primary" type="submit">Aggiungi in agenda</flux:button></div>
            @error('subject')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </form>
    </flux:card>
    <div class="space-y-3">
        @forelse ($this->activities as $activity)
            <flux:card class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><flux:badge>{{ $activity->kind }}</flux:badge><flux:heading size="sm">{{ $activity->subject }}</flux:heading></div>
                    <flux:text size="sm" class="mt-1">{{ $activity->scheduled_at?->format('d/m/Y H:i') ?? 'Senza scadenza' }} · {{ $activity->contact?->display_name ?? $activity->property?->title ?? 'Attività generale' }}</flux:text>
                    @if ($activity->notes)<flux:text size="sm">{{ $activity->notes }}</flux:text>@endif</div>
                <div class="flex items-center gap-2"><flux:badge>{{ $activity->status }}</flux:badge>@if (! in_array($activity->status, ['Completata','Annullata'], true))<flux:button size="sm" wire:click="complete({{ $activity->id }})">Completa</flux:button>@endif</div>
            </flux:card>
        @empty
            <flux:card><flux:text>Nessuna attività in questa vista.</flux:text></flux:card>
        @endforelse
        {{ $this->activities->links() }}
    </div>
</div>
