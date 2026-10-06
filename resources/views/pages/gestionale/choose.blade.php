<?php

use App\Gestionale\CurrentAgency;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Scelta dell'agenzia del gestionale: chi ha più agenzie attive sceglie qui (la scelta resta
 * in sessione); chi non ne ha nessuna riceve un messaggio chiaro, mai il pannello admin.
 */
new #[Title('Scegli l’agenzia')] class extends Component {
    #[Computed]
    public function memberships()
    {
        return app(CurrentAgency::class)->available();
    }

    public function choose(int $agencyId)
    {
        $memberships = $this->memberships();
        abort_unless($memberships->contains('agency_id', $agencyId), 403);
        app(CurrentAgency::class)->switchTo($agencyId);

        return $this->redirectRoute('gestionale.home');
    }
}; ?>

@php($roles = ['admin' => 'Responsabile', 'crm' => 'Segreteria', 'scout' => 'Operatore'])
<div class="mx-auto flex max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Gestionale</flux:heading>
        <flux:text class="mt-1">Clienti, richieste e attività della tua agenzia.</flux:text>
    </div>

    @if (session('gestionale.error'))
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="session('gestionale.error')" />
    @endif

    @if ($this->memberships->isEmpty())
        <flux:callout icon="information-circle" data-test="no-agency">
            <flux:callout.heading>Il tuo account non è collegato a nessuna agenzia attiva</flux:callout.heading>
            <flux:callout.text>
                Per usare il gestionale chiedi al Responsabile della tua agenzia, o all’amministratore di REKO, di aggiungerti come membro.
                @if (auth()->user()->isAdmin())
                    L’accesso al pannello di amministrazione della piattaforma non dà accesso al gestionale di un’agenzia.
                @endif
            </flux:callout.text>
        </flux:callout>
        <div><flux:button :href="route('home')" variant="ghost" icon="arrow-left">Torna alla home</flux:button></div>
    @else
        <flux:text>Scegli l’agenzia in cui lavorare. Puoi cambiarla in qualsiasi momento dal menu laterale.</flux:text>
        <div class="grid gap-3">
            @foreach ($this->memberships as $membership)
                <flux:card class="flex items-center justify-between gap-4" wire:key="m-{{ $membership->id }}">
                    <div>
                        <flux:heading>{{ $membership->agency->name }}</flux:heading>
                        <flux:text size="sm">{{ $roles[$membership->role] ?? $membership->role }}</flux:text>
                    </div>
                    <flux:button variant="primary" wire:click="choose({{ $membership->agency_id }})">Apri</flux:button>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
