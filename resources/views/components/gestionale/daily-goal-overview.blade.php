@props(['membership'])
@php
    // daily-goal-overview.tsx: l'obiettivo contatti di oggi, mai una somma di metriche diverse.
    $board = new \App\Gestionale\Goals\GoalBoard($membership);
    $today = \App\Gestionale\Goals\GoalCatalog::today();
    $rows = $board->dailyRows($today);
    $without = collect($rows)->filter(fn ($r) => ! $r['primary'])->count();
@endphp
<section class="crm-daily-goals" aria-label="Obiettivi contatti di oggi">
    <h2>Oggi · {{ \App\Gestionale\Today\Ui::dateLabel($today) }}</h2>
    @foreach ($rows as $row)
        @if ($row['primary'])
            @php($primary = $row['primary'])
            <article class="crm-panel crm-daily-goal" wire:key="daily-{{ $row['user']['id'] }}">
                <strong>{{ $row['user']['name'] }}</strong>
                <span>{{ \App\Gestionale\Today\Ui::number($primary['value']) }} / {{ \App\Gestionale\Today\Ui::number($primary['version']['target']) }} contatti validi</span>
                <progress max="{{ $primary['version']['target'] }}" value="{{ min($primary['value'], $primary['version']['target']) }}" aria-label="Contatti validi di oggi · {{ $row['user']['name'] }}"></progress>
                <small>{{ $primary['complete'] ? 'Obiettivo raggiunto' : ($primary['missing'] == 1 ? 'Manca 1 contatto valido' : 'Mancano '.\App\Gestionale\Today\Ui::number($primary['missing']).' contatti validi') }}{{ $row['additional'] > 0 ? ' · Altri obiettivi nel dettaglio' : '' }}</small>
            </article>
        @endif
    @endforeach
    @if ($without > 0)
        <p class="crm-muted">{{ $without === count($rows) ? 'Nessun obiettivo contatti impostato per oggi.' : $without.' operatori senza obiettivo contatti giornaliero.' }}</p>
    @endif
    <details class="crm-goal-counting"><summary>Come vengono conteggiati</summary><p>Contano le risposte effettive con esito valido. I contatti senza risposta o ancora da richiamare non contano come nuovi contatti validi.</p></details>
</section>
