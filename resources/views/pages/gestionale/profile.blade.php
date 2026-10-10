<?php

use App\Gestionale\CommandRejected;
use App\Gestionale\CurrentAgency;
use App\Gestionale\Navigation;
use App\Gestionale\WorkProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Come vuoi lavorare nel Gestionale?" (crm-entry.tsx): il Responsabile sceglie tra Responsabile,
 * Segreteria e Agente acquisizioni; ogni altro account ha il solo profilo del proprio ruolo.
 * Il profilo può solo restringere (WorkProfile::apply) e resta nella sessione per l'agenzia.
 */
new #[Layout('layouts::gestionale-entry'), Title('Scegli il profilo di lavoro')] class extends Component {
    #[Computed]
    public function roles(): array
    {
        $actual = app(CurrentAgency::class)->actualMembership() ?? abort(403, 'Nessuna agenzia attiva per questo account.');

        return WorkProfile::rolesFor($actual);
    }

    public function choose(string $role)
    {
        $current = app(CurrentAgency::class);
        try {
            $membership = $current->chooseWorkProfile($role);
        } catch (CommandRejected $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        $intended = session()->pull('gestionale.profile_intended');
        if (is_array($intended)
            && is_string($intended['route'] ?? null)
            && str_starts_with($intended['route'], 'gestionale.')
            && \Illuminate\Support\Facades\Route::has($intended['route'])) {
            $section = Navigation::sectionForRoute($intended['route']);
            $query = is_array($intended['query'] ?? null) ? $intended['query'] : [];
            $approvalsOnly = ($query['filtro'] ?? null) === 'approvals' && $membership->role !== 'admin';
            $sectionUnavailable = $section !== null
                && $section !== 'Impostazioni CRM'
                && ! in_array($section, Navigation::sectionsFor($membership), true);

            if (! $approvalsOnly && ! $sectionUnavailable) {
                $parameters = is_array($intended['parameters'] ?? null) ? $intended['parameters'] : [];

                return $this->redirectRoute($intended['route'], array_merge($query, $parameters));
            }
        }

        return $this->redirectRoute('gestionale.home');
    }
}; ?>

<main class="reko-prototype crm-profile-entry">
    <a href="{{ route('home') }}" class="reko-brand-logo"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a>
    <h1>Come vuoi lavorare nel Gestionale?</h1>
    <p>Scegli il profilo con cui organizzare la tua giornata.</p>
    <div class="crm-profile-options">
        @foreach ($this->roles as $role)
            <button type="button" class="crm-panel" wire:key="profile-{{ $role }}" wire:click="choose('{{ $role }}')">
                <strong>{{ WorkProfile::LABELS[$role] }}</strong>
                <span>{{ WorkProfile::DESCRIPTIONS[$role] }}</span>
                <span class="crm-link">Entra →</span>
            </button>
        @endforeach
    </div>
    <p class="crm-muted">Account: {{ auth()->user()->name }}. Il profilo limita le funzioni accessibili. Ogni salvataggio resta attribuito al tuo account.</p>
</main>
