<?php

use Livewire\Component;
use App\Models\Agency;
use App\Gestionale\DbGuard;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /*
     * Restituisce l'elenco delle agenzie filtrate per ricerca e ordinate per nome.
     */
    #[Computed]
    public function agencies()
    {
        return Agency::query()
            ->withCount('memberships')
            ->when($this->search !== '', fn($q) => $q->where('name', 'ilike', '%' . $this->search . '%'))
            ->orderBy('name')
            ->paginate(20);
    }

    /*
     * Elimina un'agenzia solo se è vuota. Richieste, CRM e registro attività la bloccano
     * (FK RESTRICT nel database): in quel caso si disattiva, così nessun dato va perso.
     */
    public function delete($id)
    {
        $agency = Agency::findOrFail($id);
        if ($agency->requests()->exists()) {
            Flux::toast(variant: 'danger', text: 'L’agenzia ha richieste degli utenti: non si può eliminare. Puoi disattivarla.');
            return;
        }

        // Controllo esplicito prima di cancellare; il catch resta come rete di sicurezza (concorrenza).
        if (DbGuard::blockingReferences($agency) !== []) {
            Flux::toast(variant: 'danger', text: 'L’agenzia ha dati del gestionale: non si può eliminare. Puoi disattivarla.');
            return;
        }

        try {
            DB::transaction(fn () => $agency->delete());
        } catch (QueryException $e) {
            if (! DbGuard::isForeignKeyBlock($e)) {
                throw $e;
            }
            Flux::toast(variant: 'danger', text: 'L’agenzia ha dati del gestionale: non si può eliminare. Puoi disattivarla.');
            return;
        }

        Flux::toast(variant: 'success', text: 'Agenzia eliminata.');
    }

    /*
     * Disattiva o riattiva un'agenzia: i dati restano, i membri non la vedono più come agenzia corrente.
     */
    public function toggleActive($id): void
    {
        $agency = Agency::findOrFail($id);
        $agency->update(['deactivated_at' => $agency->isActive() ? now() : null]);
        unset($this->agencies);
        Flux::toast(variant: 'success', text: $agency->isActive() ? 'Agenzia riattivata.' : 'Agenzia disattivata.');
    }
};
?>
<div class="mx-auto max-w-5xl space-y-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Agenzie</flux:heading>
        <flux:button variant="primary" :href="route('admin.agencies.create')" wire:navigate>Nuova agenzia</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Cerca per nome" icon="magnifying-glass" />

    <flux:table :paginate="$this->agencies">
        <flux:table.columns>
            <flux:table.column>Nome</flux:table.column>
            <flux:table.column>Partita IVA</flux:table.column>
            <flux:table.column>Membri</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->agencies as $agency)
                <flux:table.row :key="$agency->id">
                    <flux:table.cell>
                        {{ $agency->name }}
                        @unless ($agency->isActive())
                            <flux:badge size="sm" color="zinc">Disattivata</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell>{{ $agency->vat_number ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $agency->memberships_count }}</flux:table.cell>
                    <flux:table.cell class="text-right">
                        <flux:button size="sm" :href="route('admin.agencies.edit', $agency)" wire:navigate>Modifica
                        </flux:button>
                        <flux:button size="sm" wire:click="toggleActive({{ $agency->id }})">
                            {{ $agency->isActive() ? 'Disattiva' : 'Riattiva' }}
                        </flux:button>
                        <flux:button size="sm" variant="danger" wire:click="delete({{ $agency->id }})"
                            wire:confirm="Eliminare {{ $agency->name }}? I membri verranno scollegati.">Elimina
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
