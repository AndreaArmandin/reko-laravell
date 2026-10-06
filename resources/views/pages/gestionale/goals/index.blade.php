<?php

use App\Gestionale\Audit;
use App\Gestionale\CurrentAgency;
use App\Models\Goal;
use App\Models\GoalLedger;
use App\Models\GoalVersion;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale'), Title('Obiettivi')] class extends Component {
    public array $targets = [];

    public function mount(): void
    {
        $membership = app(CurrentAgency::class)->membership();
        $this->seedInitialGoals((int) $membership->agency_id);
    }

    private function seedInitialGoals(int $agencyId): void
    {
        $defaults = [
            ['key' => 'initial-valid-contacts', 'definition' => ['name' => 'Contatti validi', 'description' => 'Persone con risposta effettiva, una sola volta nel periodo.', 'category' => 'Contatti', 'metric' => 'contacts', 'target' => 20, 'unit' => 'persone', 'period' => 'Giornaliera', 'events' => ['Contatto'], 'outcomes' => ['Non interessato', 'Non valuta al momento', 'Informazione ricevuta', 'Potenziale venditore o locatore', 'Disponibile ad approfondire', 'Appuntamento di acquisizione fissato', 'Appuntamento di acquisizione svolto', 'Incarico acquisito'], 'dedup' => 'person', 'roles' => ['scout'], 'priority' => 'Alta', 'order' => 1, 'icon' => 'Telefono', 'active' => true, 'start' => today()->toDateString()]],
            ['key' => 'initial-acquisition-appointments', 'definition' => ['name' => 'Appuntamenti di acquisizione fissati', 'description' => 'Appuntamenti confermati con proprietario, immobile o interesse, giorno e ora.', 'category' => 'Acquisizione', 'metric' => 'appointments', 'target' => 1, 'unit' => 'appuntamenti', 'period' => 'Giornaliera', 'events' => ['Appuntamento di acquisizione fissato'], 'outcomes' => ['Appuntamento di acquisizione fissato'], 'dedup' => 'event', 'roles' => ['scout'], 'priority' => 'Alta', 'order' => 2, 'icon' => 'Calendario', 'active' => true, 'start' => today()->toDateString()]],
        ];
        DB::transaction(function () use ($agencyId, $defaults) {
            foreach ($defaults as $row) {
                $goal = Goal::query()->firstOrCreate(['agency_id' => $agencyId, 'key' => $row['key']], ['read_only' => true]);
                if (! $goal->versions()->exists()) GoalVersion::query()->create(['agency_id' => $agencyId, 'goal_id' => $goal->id, 'version' => 1, 'effective_from' => today(), 'definition' => $row['definition'], 'reason' => 'Obiettivo iniziale del Gestionale']);
            }
        });
    }

    #[Computed]
    public function goals()
    {
        $membership = app(CurrentAgency::class)->membership();
        $day = today()->toDateString();
        return Goal::query()->where('agency_id', $membership->agency_id)->with(['versions' => fn ($q) => $q->where('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('version')])->get()
            ->filter(fn ($goal) => $goal->versions->isNotEmpty())
            ->filter(fn ($goal) => $membership->role === 'admin' || in_array($membership->role, $goal->versions->first()->definition['roles'] ?? [], true))
            ->map(function ($goal) use ($day) {
                $version = $goal->versions->first();
                $definition = $version->definition;
                $start = match ($definition['period'] ?? 'Giornaliera') {
                    'Settimanale' => today()->startOfWeek()->toDateString(),
                    'Mensile' => today()->startOfMonth()->toDateString(),
                    'Trimestrale' => today()->startOfQuarter()->toDateString(),
                    'Annuale' => today()->startOfYear()->toDateString(),
                    default => $day,
                };
                $value = (float) GoalLedger::query()->where('agency_id', $goal->agency_id)->where('goal_id', $goal->id)->where('period_start', $start)->sum('value');
                return ['goal' => $goal, 'version' => $version, 'definition' => $definition, 'start' => $start, 'value' => $value];
            })->values();
    }

    public function saveTargets(): void
    {
        $membership = app(CurrentAgency::class)->membership();
        abort_unless($membership?->isAdmin(), 403);
        $this->validate(['targets' => ['required', 'array'], 'targets.*' => ['required', 'numeric', 'min:1', 'max:1000000']]);
        DB::transaction(function () use ($membership) {
            foreach ($this->targets as $goalId => $target) {
                $goal = Goal::query()->where('agency_id', $membership->agency_id)->with('versions')->findOrFail((int) $goalId);
                $current = $goal->versions->first();
                if (! $current || $goal->read_only) continue;
                $definition = $current->definition;
                $definition['target'] = (float) $target;
                GoalVersion::query()->create(['agency_id' => $membership->agency_id, 'goal_id' => $goal->id, 'version' => (int) $goal->versions->max('version') + 1, 'effective_from' => today(), 'definition' => $definition, 'author_user_id' => $membership->user_id, 'reason' => 'Revisione del target']);
                app(Audit::class)->record('goal.target', $goal, ['version' => $definition['target']]);
            }
        });
        unset($this->goals);
        session()->flash('status', 'Target aggiornati con una nuova versione.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="crm-page-head"><div><flux:heading size="xl" level="1">Obiettivi</flux:heading><flux:text>Ogni obiettivo conserva le proprie versioni e mostra l’avanzamento registrato nel periodo corrente.</flux:text></div></div>
    @if (session('status'))<flux:callout icon="check-circle">{{ session('status') }}</flux:callout>@endif
    @if ($this->membership->isAdmin())<form wire:submit="saveTargets" class="flex flex-col gap-4">@endif
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse ($this->goals as $row)
                <flux:card class="flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3"><div><flux:heading size="lg">{{ $row['definition']['name'] }}</flux:heading><flux:text size="sm">{{ $row['definition']['description'] }}</flux:text></div><flux:badge>{{ $row['definition']['period'] }}</flux:badge></div>
                    <div><div class="flex justify-between text-sm"><span>Avanzamento</span><span>{{ number_format($row['value'], 0, ',', '.') }} / {{ number_format((float) $row['definition']['target'], 0, ',', '.') }} {{ $row['definition']['unit'] }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ min(100, (float) $row['definition']['target'] > 0 ? $row['value'] / (float) $row['definition']['target'] * 100 : 0) }}%"></div></div></div>
                    <flux:text size="sm">Versione {{ $row['version']->version }} · dal {{ $row['version']->effective_from->format('d/m/Y') }}</flux:text>
                    @if ($this->membership->isAdmin() && ! $row['goal']->read_only)<flux:input type="number" min="1" step="1" wire:model="targets.{{ $row['goal']->id }}" label="Nuovo target" />@endif
                </flux:card>
            @empty
                <flux:card><flux:text>Nessun obiettivo previsto per il tuo ruolo.</flux:text></flux:card>
            @endforelse
        </div>
        @if ($this->membership->isAdmin())<div><flux:button variant="primary" type="submit">Salva nuovi target</flux:button></div></form>@endif
    <flux:callout icon="information-circle" variant="secondary"><flux:callout.text>I target iniziali sono caricati dal Gestionale originale. Gli eventi storici si conteggiano solo quando vengono registrati come attività completate con esito verificato.</flux:callout.text></flux:callout>
</div>
