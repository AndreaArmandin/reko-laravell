@props(['match'])
@php
    // matches.tsx ScoreBadge: punteggio sui soli dati confrontabili, con la guida "Come leggere il punteggio".
    $result = $match->result ?? [];
    $value = $match->score !== null ? number_format((float) $match->score, 0) : null;
    $conditional = (bool) ($result['conditional'] ?? false);
@endphp
<div class="crm-score {{ $conditional ? 'conditional' : '' }} {{ $value === null ? 'unknown' : '' }}" title="Punteggio dei soli dati confrontabili: i dati mancanti non sono inclusi. Verifica sempre i requisiti sotto." aria-label="Punteggio sui dati confrontati: {{ $value ?? 'non disponibile' }}{{ $value === null ? '' : ' su 100' }}"><strong>{{ $value ?? '—' }}</strong>@if ($value !== null)<span>/ 100</span>@endif<small>Dati confrontati</small>
    <details class="crm-score-help"><summary aria-label="Come leggere il punteggio"><x-gestionale.lucide name="info" :size="18" /></summary><p>Considera solo i criteri confrontabili ({{ (int) ($result['compared'] ?? 0) }} su {{ count($result['comparisons'] ?? []) }}). Verifica anche i requisiti e i dati mancanti.</p></details>
</div>
