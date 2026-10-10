<?php

use App\Gestionale\CurrentAgency;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Scelta dell'agenzia del gestionale: chi ha più agenzie attive sceglie qui (la scelta resta
 * in sessione); chi non ne ha nessuna riceve un messaggio chiaro, mai il pannello admin.
 */
new #[Layout('layouts::gestionale-entry'), Title('Scegli l’agenzia')] class extends Component {
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

        return $this->redirectRoute(app(CurrentAgency::class)->workProfile() ? 'gestionale.home' : 'gestionale.profile');
    }
}; ?>

@php($roles = ['admin' => 'Responsabile', 'crm' => 'Segreteria', 'scout' => 'Agente acquisizioni'])
<main class="reko-prototype crm-profile-entry">
    <a href="{{ route('home') }}" class="reko-brand-logo"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a>
    <h1>Gestionale</h1>
    <p>Clienti, richieste e attività della tua agenzia.</p>

    @if (session('gestionale.error'))
        <p class="crm-error" role="alert">{{ session('gestionale.error') }}</p>
    @endif

    @if ($this->memberships->isEmpty())
        <section class="crm-panel" data-test="no-agency">
            <h2>Il tuo account non è collegato a nessuna agenzia attiva</h2>
            <p>
                Per usare il gestionale chiedi al Responsabile della tua agenzia, o all’amministratore di REKO, di aggiungerti come membro.
                @if (auth()->user()->isAdmin())
                    L’accesso al pannello di amministrazione della piattaforma non dà accesso al gestionale di un’agenzia.
                @endif
            </p>
            <a class="crm-link" href="{{ route('home') }}">Torna alla home</a>
        </section>
    @else
        <p>Scegli l’agenzia in cui lavorare. Puoi cambiarla in qualsiasi momento dal menu in alto.</p>
        <div class="crm-profile-options">
            @foreach ($this->memberships as $membership)
                <button type="button" class="crm-panel" wire:key="m-{{ $membership->id }}" wire:click="choose({{ $membership->agency_id }})">
                    <strong>{{ $membership->agency->name }}</strong>
                    <span>{{ $roles[$membership->role] ?? $membership->role }}</span>
                    <span class="crm-link">Apri →</span>
                </button>
            @endforeach
        </div>
    @endif
</main>
