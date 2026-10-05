<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">REKO</h1>
            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                You are signed in. This installation is the cadastral foundation: Livewire authentication and the PostgreSQL schema. Search, importers, CRM screens, and the map are not in this slice.
            </p>
        </div>

        @if (auth()->user()->is_admin)
            <a href="{{ route('admin.dashboard') }}" class="inline-flex w-fit rounded bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">Apri amministrazione</a>
        @endif

        <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <h2 class="font-medium text-zinc-900 dark:text-zinc-100">Le tue agenzie</h2>
            @forelse (auth()->user()->agencyMemberships()->with('agency')->get() as $membership)
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $membership->agency->name }} · {{ $membership->role }}</p>
            @empty
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Non sei ancora assegnato a un'agenzia. Chiedi all'amministratore di aggiungerti.</p>
            @endforelse
        </section>

        <div class="grid gap-4 md:grid-cols-3">
            <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="font-medium text-zinc-900 dark:text-zinc-100">Identity and versions</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    Parcels, units, and buildings keep a stable identity. Attributes change on a catalog release.
                </p>
            </section>
            <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="font-medium text-zinc-900 dark:text-zinc-100">One release per municipality</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    municipality_catalogs points each comune at the catalog release currently in force.
                </p>
            </section>
            <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <h2 class="font-medium text-zinc-900 dark:text-zinc-100">Missing data stays null</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    Areas, rendite, and geometries are nullable. Nothing is stored as zero when the source did not provide it.
                </p>
            </section>
        </div>
    </div>
</x-layouts::app>
