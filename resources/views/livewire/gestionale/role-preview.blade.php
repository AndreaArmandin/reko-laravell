<?php

use App\Gestionale\CurrentAgency;
use App\Gestionale\WorkProfile;
use App\Models\AgencyMembership;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * "Vedi come…" (role-preview.tsx): anteprima di Oggi per un altro operatore, in sola lettura. Resta il tuo
 * account Responsabile: non cambia sessione, ruolo né permessi e non scrive nulla. Solo per il profilo Responsabile.
 */
new class extends Component {
    public bool $open = false;

    public ?int $viewAs = null;

    #[Computed]
    public function membership(): ?AgencyMembership
    {
        $membership = app(CurrentAgency::class)->membership();

        return $membership?->role === 'admin' ? $membership : null;
    }

    /** previewUsers: the active people of the agency, with their role. */
    #[Computed]
    public function users()
    {
        $actor = $this->membership;

        return $actor === null ? collect() : AgencyMembership::query()->active()->where('agency_id', $actor->agency_id)
            ->whereHas('user')->with('user')->orderBy('id')->get();
    }

    #[Computed]
    public function preview(): ?AgencyMembership
    {
        return $this->viewAs === null ? null : $this->users->firstWhere('id', $this->viewAs);
    }

    public function show(): void
    {
        abort_if($this->membership === null, 403, 'Anteprima non disponibile.');
        $this->viewAs = ($this->users->first(fn ($m) => $m->role !== 'admin') ?? $this->users->first())?->id;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }
}; ?>

<div>
    @if ($this->membership)
        <button type="button" class="crm-btn secondary" wire:click="show">Vedi come…</button>
        @if ($open)
            <dialog class="proto-dialog reko-prototype proto-dialog-wide" aria-labelledby="role-preview-title" x-init="$el.showModal()" x-on:cancel.prevent="$wire.close()" x-on:click="if ($event.target === $el) $wire.close()">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="role-preview-title">Vedi come… · anteprima di Oggi</h2></div>
                    <button type="button" class="crm-icon-button" aria-label="Chiudi" wire:click="close"><x-gestionale.lucide name="x" /></button>
                </div>
                <p class="crm-info">Solo lettura: stai ancora usando il tuo account Responsabile. Nessun dato o permesso viene modificato.</p>
                <label class="crm-field">Persona e ruolo
                    <select wire:model.live="viewAs">
                        @foreach ($this->users as $person)
                            <option value="{{ $person->id }}">{{ WorkProfile::label($person->role) }} · {{ $person->user->name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($this->preview === null)
                    <p role="status">Caricamento dell’anteprima…</p>
                @else
                    <fieldset disabled class="crm-role-preview" x-on:click.prevent>
                        <legend class="sr-only">Anteprima in sola lettura</legend>
                        {{-- Il pannello di Oggi è dell'area Home: se esiste lo mostra, senza dipendere dalla sua compilazione. --}}
                        @if (view()->exists('components.gestionale.today-panel'))
                            <x-dynamic-component component="gestionale.today-panel" :membership="$this->preview" :readonly="true" />
                        @endif
                    </fieldset>
                @endif
            </dialog>
        @endif
    @endif
</div>
