<x-layouts::app :title="__('Dashboard')">
    @php
        $memberships = app(\App\Gestionale\CurrentAgency::class)->available();
        $roles = ['admin' => 'Responsabile', 'crm' => 'Segreteria', 'scout' => 'Agente acquisizioni'];
    @endphp

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
        <header>
            <flux:heading size="xl" level="1">REKO</flux:heading>
            <flux:text class="mt-1">Scegli l’area di lavoro.</flux:text>
        </header>

        <section class="grid gap-4 md:grid-cols-2">
            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">Trova</flux:heading>
                    <flux:text class="mt-1">Cerca immobili e terreni nel catalogo catastale.</flux:text>
                </div>
                <flux:button class="w-fit" icon="magnifying-glass" :href="route('trova')">Apri Trova</flux:button>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">Gestionale</flux:heading>
                    <flux:text class="mt-1">Gestisci clienti, richieste e attività della tua agenzia.</flux:text>
                </div>
                @forelse ($memberships as $membership)
                    <form method="POST" action="{{ route('gestionale.enter', $membership->agency_id) }}">
                        @csrf
                        <flux:button type="submit" class="w-fit" icon="arrow-right">
                            {{ $membership->agency->name }} · {{ $roles[$membership->role] ?? $membership->role }}
                        </flux:button>
                    </form>
                @empty
                    <flux:text size="sm">Il tuo account non è ancora collegato a un’agenzia.</flux:text>
                    @if (auth()->user()->isAdmin())
                        <flux:callout icon="information-circle">
                            <flux:callout.heading>Collega un account per aprire il Gestionale</flux:callout.heading>
                            <flux:callout.text>In Amministrazione → Agenzie puoi associare un utente registrato e assegnargli il ruolo nell’agenzia.</flux:callout.text>
                        </flux:callout>
                    @endif
                @endforelse
            </flux:card>
        </section>

        @if (auth()->user()->isAdmin())
            <section>
                <flux:heading size="lg">Amministrazione REKO</flux:heading>
                <flux:text class="mt-1">Gestisci agenzie, cataloghi e zone di ricerca.</flux:text>
                <div class="mt-3 flex flex-wrap gap-2">
                    <flux:button variant="primary" icon="squares-2x2" :href="route('admin.dashboard')">Apri amministrazione</flux:button>
                    <flux:button variant="ghost" :href="route('admin.agencies.index')">Gestisci le agenzie</flux:button>
                </div>
            </section>
        @endif
    </div>
</x-layouts::app>
