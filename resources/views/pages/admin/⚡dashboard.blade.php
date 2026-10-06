<?php

use App\Models\Agency;
use App\Models\User;
use App\Models\AgencyRequest;
use Livewire\Component;

new class extends Component {};
?>

<div class="mx-auto max-w-5xl space-y-8 p-6">
    <div>
        <h1 class="text-2xl font-semibold">Amministrazione REKO</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Gestisci le agenzie e assegna utenti già registrati. Il ruolo admin di agenzia non concede accesso a questo pannello.</p>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
    @endif

    <dl class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border p-5"><dt>Agenzie</dt><dd class="mt-2 text-3xl font-semibold">{{ Agency::count() }}</dd></div>
        <div class="rounded-xl border p-5"><dt>Utenti registrati</dt><dd class="mt-2 text-3xl font-semibold">{{ User::count() }}</dd></div>
        <div class="rounded-xl border p-5"><dt>Richieste alle agenzie</dt><dd class="mt-2 text-3xl font-semibold">{{ AgencyRequest::count() }}</dd></div>
    </dl>

    <section class="space-y-4" aria-labelledby="admin-agencies-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="admin-agencies-title" class="text-xl font-semibold">Agenzie</h2>
            <a class="rounded-lg bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-white dark:text-zinc-900" href="{{ route('admin.agencies.index') }}">Gestisci agenzie</a>
        </div>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-zinc-50 dark:bg-zinc-900"><tr><th class="p-3">Agenzia</th><th class="p-3">Membri</th><th class="p-3">Richieste</th><th class="p-3"></th></tr></thead>
                <tbody>
                    @forelse (Agency::query()->withCount(['memberships', 'requests'])->orderBy('name')->limit(10)->get() as $agency)
                        <tr class="border-b last:border-0"><th class="p-3 font-medium">{{ $agency->name }}</th><td class="p-3">{{ $agency->memberships_count }}</td><td class="p-3">{{ $agency->requests_count }}</td><td class="p-3 text-right"><a class="underline" href="{{ route('admin.agencies.edit', $agency) }}">Apri</a></td></tr>
                    @empty
                        <tr><td class="p-3" colspan="4">Nessuna agenzia. Creane una per assegnare i collaboratori.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
