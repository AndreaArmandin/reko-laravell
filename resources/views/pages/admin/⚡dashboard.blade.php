<?php

use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $agencyName = '';
    public string $vatNumber = '';
    public string $memberEmail = '';
    public string $agencyId = '';
    public string $memberRole = 'scout';

    #[Computed]
    public function agencies()
    {
        return Agency::query()->with(['memberships.user'])->orderBy('name')->get();
    }

    public function createAgency(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $this->agencyName = trim($this->agencyName);
        $this->vatNumber = trim($this->vatNumber);
        $slug = Str::slug($this->agencyName);

        $this->validate([
            'agencyName' => ['required', 'string', 'max:255'],
            'vatNumber' => ['nullable', 'string', 'max:32'],
        ]);

        if ($slug === '' || Agency::query()->where('slug', $slug)->exists()) {
            $this->addError('agencyName', 'Il nome genera un codice agenzia già usato o non valido.');
            return;
        }

        Agency::query()->create([
            'name' => $this->agencyName,
            'slug' => $slug,
            'vat_number' => $this->vatNumber !== '' ? $this->vatNumber : null,
        ]);

        $this->reset('agencyName', 'vatNumber');
        unset($this->agencies);
        session()->flash('status', 'Agenzia creata.');
    }

    public function assignMember(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $this->memberEmail = Str::lower(trim($this->memberEmail));
        $validated = $this->validate([
            'memberEmail' => ['required', 'email', Rule::exists('users', 'email')],
            'agencyId' => ['required', 'integer', Rule::exists('agencies', 'id')],
            'memberRole' => ['required', Rule::in(['admin', 'scout', 'crm'])],
        ]);

        $user = User::query()->where('email', $validated['memberEmail'])->firstOrFail();

        AgencyMembership::query()->updateOrCreate(
            ['agency_id' => $validated['agencyId'], 'user_id' => $user->id],
            ['role' => $validated['memberRole']],
        );

        $this->reset('memberEmail', 'agencyId');
        unset($this->agencies);
        session()->flash('status', 'Utente assegnato all’agenzia.');
    }
};
?>

<div class="mx-auto max-w-5xl space-y-8 p-6">
    <div>
        <h1 class="text-2xl font-semibold">Amministrazione REKO</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Crea le agenzie e assegna utenti già registrati. Il ruolo admin qui sotto riguarda solo l’agenzia.</p>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <form wire:submit="createAgency" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <h2 class="font-semibold">Nuova agenzia</h2>
            <div>
                <label for="agency-name" class="block text-sm">Nome</label>
                <input id="agency-name" wire:model="agencyName" required class="mt-1 w-full rounded border p-2 text-zinc-900">
                @error('agencyName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="vat-number" class="block text-sm">Partita IVA (opzionale)</label>
                <input id="vat-number" wire:model="vatNumber" class="mt-1 w-full rounded border p-2 text-zinc-900">
                @error('vatNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded bg-zinc-900 px-4 py-2 text-white dark:bg-zinc-100 dark:text-zinc-900">Crea agenzia</button>
        </form>

        <form wire:submit="assignMember" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <h2 class="font-semibold">Assegna utente</h2>
            <div>
                <label for="member-email" class="block text-sm">Email dell’utente registrato</label>
                <input id="member-email" type="email" wire:model="memberEmail" required class="mt-1 w-full rounded border p-2 text-zinc-900">
                @error('memberEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="agency-id" class="block text-sm">Agenzia</label>
                <select id="agency-id" wire:model="agencyId" required class="mt-1 w-full rounded border p-2 text-zinc-900">
                    <option value="">Seleziona agenzia</option>
                    @foreach ($this->agencies as $agency)
                        <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                    @endforeach
                </select>
                @error('agencyId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="member-role" class="block text-sm">Ruolo nell’agenzia</label>
                <select id="member-role" wire:model="memberRole" class="mt-1 w-full rounded border p-2 text-zinc-900">
                    <option value="admin">Admin agenzia</option>
                    <option value="scout">Scout</option>
                    <option value="crm">CRM</option>
                </select>
                @error('memberRole') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded bg-zinc-900 px-4 py-2 text-white dark:bg-zinc-100 dark:text-zinc-900">Assegna utente</button>
        </form>
    </div>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold">Agenzie e membri</h2>
        @forelse ($this->agencies as $agency)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <h3 class="font-medium">{{ $agency->name }}</h3>
                <p class="text-xs text-zinc-500">{{ $agency->slug }}</p>
                @forelse ($agency->memberships as $membership)
                    <p class="mt-2 text-sm">{{ $membership->user->name }} · {{ $membership->user->email }} · {{ $membership->role }}</p>
                @empty
                    <p class="mt-2 text-sm text-zinc-500">Nessun membro assegnato.</p>
                @endforelse
            </div>
        @empty
            <p class="text-sm text-zinc-500">Non ci sono ancora agenzie.</p>
        @endforelse
    </section>
</div>
