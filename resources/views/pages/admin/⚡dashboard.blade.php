<?php

use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {};
?>

<div class="mx-auto max-w-5xl space-y-8 p-6">
    <div>
        <h1 class="text-2xl font-semibold">Amministrazione REKO</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Crea le agenzie e assegna utenti già registrati. Il
            ruolo admin qui sotto riguarda solo l’agenzia.</p>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg bg-green-50 p-3 text-green-800">{{ session('status') }}</p>
    @endif

</div>
