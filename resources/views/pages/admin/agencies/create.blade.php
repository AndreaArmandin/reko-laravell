<?php

use App\Models\Agency;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nuova agenzia')] class extends Component {
    public string $name = '';
    public string $vat_number = '';

    // Check di controllo accessi per l'amministratore
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    // Salva una nuova agenzia nel database
    public function save(): void
    {
        $this->name = trim($this->name);
        $this->vat_number = trim($this->vat_number);

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

        $agency = Agency::query()->create([
            'name' => $this->name,
            'slug' => $slug,
            'vat_number' => $this->vat_number !== '' ? $this->vat_number : null,
        ]);

        session()->flash('status', 'Agenzia creata.');
        $this->redirectRoute('admin.agencies.index', navigate: true);
    }
}; ?>

<div class="mx-auto max-w-xl space-y-6 p-6">
    <flux:heading size="xl">Nuova agenzia</flux:heading>

    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="name" label="Nome" required />
        <flux:input wire:model="vat_number" label="Partita IVA (opzionale)" />

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">Crea agenzia</flux:button>
            <flux:button :href="route('admin.agencies.index')" wire:navigate>Annulla</flux:button>
        </div>
    </form>
</div>
