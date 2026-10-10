@props(['membership', 'readonly' => false])
@php
    // today-panel.tsx: le code di lavoro del ruolo (lib/crm/today-work.ts).
    $work = \App\Gestionale\Today\TodayWork::work($membership);
    $role = $work['role'];
    $dayLabel = \Carbon\CarbonImmutable::parse($work['today'])->locale('it')->translatedFormat('j M Y');
    $list = function (array $rows, string $empty) {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<a class="crm-next-action" href="'.e($row['href']).'" wire:navigate><span>'.e($row['title'])
                .(! empty($row['detail']) ? '<small>'.e($row['detail']).'</small>' : '')
                .(! empty($row['dueAt']) ? '<small>'.e($row['dueAt']->locale('it')->translatedFormat('j M Y, H:i')).'</small>' : '')
                .'</span><span aria-hidden="true">→</span></a>';
        }

        return new \Illuminate\Support\HtmlString($html !== '' ? $html : '<p class="crm-muted">'.e($empty).'</p>');
    };
@endphp
<section aria-label="Oggi, azioni del tuo ruolo" @if ($readonly) inert @endif>
    <header class="crm-between crm-today-heading"><h1>Oggi</h1><p>{{ $dayLabel }}</p></header>
    <div class="crm-form-grid">
        @if ($role === 'admin')
            <section class="crm-panel"><h2>Obiettivi</h2><p>Segui l’avanzamento degli obiettivi del periodo e il lavoro della squadra.</p><a class="crm-btn" href="{{ route('gestionale.goals.index') }}" wire:navigate>Apri gli obiettivi</a></section>
        @elseif ($role === 'scout')
            <section class="crm-panel"><h2>Proprietari da chiamare · {{ $work['counts']['ownerCalls'] }}</h2>{{ $list($work['queues']['ownerCalls'], 'Nessuna chiamata ai proprietari in agenda.') }}<a class="crm-link" href="{{ route('gestionale.activities.index') }}" wire:navigate>Apri agenda</a></section>
            <section class="crm-panel"><h2>Particelle assegnate da avviare · {{ $work['counts']['parcelsToStart'] }}</h2>{{ $list($work['queues']['parcelsToStart'], 'Nessuna particella assegnata senza attività.') }}<a class="crm-link" href="{{ route('gestionale.scouting.index') }}" wire:navigate>Apri mappa e zone</a></section>
        @else
            <section class="crm-panel"><h2>Richiami programmati · {{ $work['counts']['callbacks'] }}</h2>{{ $list($work['queues']['callbacks'], 'Nessun richiamo programmato.') }}<a class="crm-link" href="{{ route('gestionale.activities.index') }}" wire:navigate>Apri agenda</a></section>
            <section class="crm-panel"><h2>Clienti da completare · {{ $work['counts']['clients'] }}</h2>{{ $list($work['queues']['clients'], 'Nessuna scheda da completare.') }}<div class="crm-actions"><a class="crm-btn" href="{{ route('gestionale.clients.create') }}" wire:navigate>Nuovo cliente</a><a class="crm-btn secondary" href="{{ route('gestionale.requests.create') }}" wire:navigate>Nuova richiesta</a></div></section>
            <section class="crm-panel"><h2>Profili da completare · {{ $work['counts']['requests'] }}</h2>{{ $list($work['queues']['requests'], 'Nessuna richiesta da completare.') }}</section>
        @endif
        <section class="crm-panel"><h2>Oggi e attività da recuperare · {{ $work['counts']['today'] }}</h2>{{ $list($work['queues']['today'], 'Nessuna attività da completare oggi o scaduta.') }}<a class="crm-btn" href="{{ route('gestionale.activities.index') }}" wire:navigate>Tutta l’agenda</a></section>
    </div>
</section>
