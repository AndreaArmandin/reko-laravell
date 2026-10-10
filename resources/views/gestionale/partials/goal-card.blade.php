@use('App\Gestionale\Today\Ui')
        <article class="crm-panel crm-goal-card">
            <div class="crm-between"><span class="proto-eyebrow">{{ $v['icon'] ?: 'Obiettivo' }} · {{ $v['period'] }}</span>{{ Ui::pill($item['complete'] ? 'Raggiunto' : $v['priority']) }}</div>
            <h3>{{ $v['name'] }}</h3>
            @if ($admin)<p class="crm-muted">{{ $item['user']['name'] }}</p>@endif
            <strong class="crm-goal-value">{{ $fmt($item['value']) }} <small>/ {{ $fmt($v['target']) }} {{ $v['unit'] }}</small></strong>
            <progress max="{{ $v['target'] }}" value="{{ min($item['value'], $v['target']) }}" aria-label="{{ $v['name'] }}"></progress>
            <p>@if ($item['complete']){{ Ui::icon('check', 15) }}Obiettivo raggiunto @else{{ $item['missing'] == 1 ? 'Manca' : 'Mancano' }} {{ $fmt($item['missing']) }} {{ $unit }}@endif</p>
            <small>{{ Ui::dateLabel($item['start']) }}@if ($item['start'] !== $item['end']) → {{ Ui::dateLabel($item['end']) }}@endif{{ $v['period'] === 'Giornaliera' && $day === $today ? ' · circa '.$remainingHours.' ore rimaste' : '' }}{{ $v['mode'] === 'team' ? ' · di squadra' : '' }}</small>
            @if ($previous)
                @php($delta = $item['value'] - $previous['value'])
                <p>Periodo di confronto: {{ $fmt($previous['value']) }}/{{ $fmt($previous['version']['target']) }} · variazione {{ $delta > 0 ? '+' : '' }}{{ $fmt($delta) }}</p>
            @endif
            <details>
                <summary>Conteggi e regole</summary>
                <p>{{ $v['description'] }}</p>
                <p>{{ implode(' · ', $v['events']) }} · {{ $v['dedup'] === 'person' ? 'Una volta per persona' : ($v['dedup'] === 'property' ? 'Una volta per immobile' : 'Una volta per evento') }} nel periodo.</p>
                <p>Annullamenti: {{ $v['keepCancelled'] ? 'rimangono nel conteggio' : 'esclusi dal conteggio' }}.</p>
                @foreach ($item['events'] as $e)
                    <div class="crm-goal-event">
                        <span>{{ Ui::dateLabel($e['at'], true) }} · {{ $e['reason'] }}</span>
                        <strong>{{ $e['value'] ? '+' : '' }}{{ $fmt($e['value']) }}</strong>
                        @if ($e['eventId'] !== 'team')<small>Evento {{ $e['eventId'] }} · soggetto {{ $e['subject'] ?: 'non indicato' }}{{ $e['propertyId'] ? ' · immobile '.$e['propertyId'] : '' }}</small>@endif
                        @if ($admin)<small>Operatore {{ $board->users()[$e['operatorId']]['name'] ?? $e['operatorId'] }} · {{ $e['rule'] }} · versione {{ $e['versionId'] }}</small>@endif
                    </div>
                @endforeach
                @if (! count($item['events']))<p>Nessun evento nel periodo.</p>@endif
                <small>Ultimo progresso: {{ $item['last'] ? Ui::dateLabel($item['last'], true) : 'nessuno' }}</small>
            </details>
        </article>
