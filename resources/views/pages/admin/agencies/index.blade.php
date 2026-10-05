<?php

use Livewire\Component;
use App\Models\Agency;
use Flux\Flux;
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
     * Elimina un'agenzia se non ha richieste associate.
     */
    public function delete($id)
    {
        $agency = Agency::findOrFail($id);
        if ($agency->requests()->exists()) {
            Flux::toast(variant: 'danger', text: 'L’agenzia ha richieste degli utenti: non si può eliminare.');
            return;
        }

        $agency->delete();
        Flux::toast(variant: 'success', text: 'Agenzia eliminata.');
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
                    <flux:table.cell>{{ $agency->name }}</flux:table.cell>
                    <flux:table.cell>{{ $agency->vat_number ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $agency->memberships_count }}</flux:table.cell>
                    <flux:table.cell class="text-right">
                        <flux:button size="sm" :href="route('admin.agencies.edit', $agency)" wire:navigate>Modifica
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
