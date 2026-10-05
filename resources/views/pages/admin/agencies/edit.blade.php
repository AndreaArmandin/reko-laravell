<?php

use App\Models\Agency;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nuova agenzia')] class extends Component {
    public string $name = '';
    public string $vat_number = '';

    public Agency $agency;
    public string $memberEmail = '';
    public string $memberRole = 'scout';

    // Inizializzo i valori dei campi con quelli dell'agenzia esistente
    public function mount(Agency $agency): void
    {
        $this->name = $agency->name;
        $this->vat_number = $agency->vat_number ?? '';
    }

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    //Aggiorna un'agenzia esistente nel database
    public function update(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:32'],
        ]);

        // Lo slug è obbligatorio e unico: lo ricaviamo dal nome
        $slug = Str::slug($this->name);

        if ($slug === '' || Agency::query()->where('slug', $slug)->exists()) {
            $this->addError('name', 'Esiste già un’agenzia con questo nome, oppure il nome non è valido.');
            return;
        }

        $agency = Agency::query()->update([
            'name' => $this->name,
            'slug' => $slug,
            'vat_number' => $this->vat_number !== '' ? $this->vat_number : null,
        ]);

        session()->flash('status', 'Agenzia Aggiornata.');
        $this->redirectRoute('admin.agencies.index', navigate: true);
    }

    /*
     * Restituisce i membri dell'agenzia
     */
    #[Computed]
    public function memberships()
    {
        return $this->agency->memberships()->with('user')->orderBy('created_at')->get();
    }

    // Collega un utente registrato all'agenzia
    public function addMember(): void
    {
        $this->memberEmail = Str::lower(trim($this->memberEmail));

        $this->validate(
            [
                'memberEmail' => ['required', 'email', Rule::exists('users', 'email')],
                'memberRole' => ['required', Rule::in(['admin', 'scout', 'crm'])],
            ],
            [
                'memberEmail.exists' => 'Nessun utente registrato con questa email.',
            ],
        );

        $user = User::query()->where('email', $this->memberEmail)->firstOrFail();

        // Se è già membro, aggiorna solo il ruolo
        $this->agency->memberships()->updateOrCreate(['user_id' => $user->id], ['role' => $this->memberRole]);

        $this->reset('memberEmail');
        unset($this->memberships);
        Flux::toast(variant: 'success', text: 'Utente collegato.');
    }

    // Cambia il ruolo di un membro dell'agenzia
    public function changeRole(int $membershipId, string $role): void
    {
        abort_unless(in_array($role, ['admin', 'scout', 'crm'], true), 422);

        // Cerca solo tra i membri di QUESTA agenzia
        $this->agency
            ->memberships()
            ->findOrFail($membershipId)
            ->update(['role' => $role]);

        unset($this->memberships);
    }

    // Rimuove un membro dall'agenzia
    public function removeMember(int $membershipId): void
    {
        $this->agency->memberships()->findOrFail($membershipId)->delete();

        unset($this->memberships);
        Flux::toast(variant: 'success', text: 'Utente scollegato.');
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-10 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ $agency->name }}</flux:heading>
        <flux:button :href="route('admin.agencies.index')" wire:navigate>Torna all’elenco</flux:button>
    </div>

    {{-- Dati agenzia --}}
    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="name" label="Nome" required />
        <flux:input wire:model="vat_number" label="Partita IVA (opzionale)" />
        <flux:button type="submit" variant="primary">Salva</flux:button>
    </form>

    {{-- Membri --}}
    <section class="space-y-4">
        <flux:heading size="lg">Utenti collegati</flux:heading>

        <form wire:submit="addMember" class="flex flex-wrap items-end gap-2">
            <div class="min-w-64 flex-1">
                <flux:input wire:model="memberEmail" type="email" label="Email utente registrato" required />
            </div>
            <flux:select wire:model="memberRole" label="Ruolo" class="w-40">
                <flux:select.option value="admin">Admin agenzia</flux:select.option>
                <flux:select.option value="scout">Scout</flux:select.option>
                <flux:select.option value="crm">CRM</flux:select.option>
            </flux:select>
            <flux:button type="submit" variant="primary">Collega</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Ruolo</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->memberships as $membership)
                    <flux:table.row :key="$membership->id">
                        <flux:table.cell>{{ $membership->user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $membership->user->email }}</flux:table.cell>
                        <flux:table.cell>
                            <select class="rounded border p-1 text-sm"
                                wire:change="changeRole({{ $membership->id }}, $event.target.value)">
                                @foreach (['admin' => 'Admin agenzia', 'scout' => 'Scout', 'crm' => 'CRM'] as $value => $label)
                                    <option value="{{ $value }}" @selected($membership->role === $value)>{{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button size="sm" variant="danger"
                                wire:click="removeMember({{ $membership->id }})"
                                wire:confirm="Scollegare {{ $membership->user->name }} da questa agenzia?">
                                Rimuovi
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">Nessun utente collegato.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
