@props(['match'])
@php
    // matches.tsx MatchStatus: requisiti critici non rispettati o da verificare, criteri confrontati.
    $result = $match->result ?? [];
    $critical = collect($result['comparisons'] ?? [])->filter(fn ($item) => in_array($item['classification'] ?? '', ['Indispensabile', 'Da escludere'], true));
    $failed = $critical->where('status', 'missing')->count();
    $unknown = $critical->where('status', 'unknown')->count();
    $compared = (int) ($result['compared'] ?? 0);
@endphp
<div class="crm-match-indicators">
    @if ($failed > 0)<x-gestionale.crm-pill tone="danger">Requisiti non rispettati: {{ $failed }}</x-gestionale.crm-pill>@endif
    @if ($unknown > 0)<x-gestionale.crm-pill tone="warm">Dati decisivi da verificare: {{ $unknown }}</x-gestionale.crm-pill>@endif
    @if (! $failed && ! $unknown)<x-gestionale.crm-pill :tone="$match->score === null ? '' : 'green'">{{ $match->score === null ? 'Da valutare' : 'Confronto disponibile' }}</x-gestionale.crm-pill>@endif
    <span>{{ $compared }} criteri confrontati · {{ max(0, (int) ($result['answered'] ?? 0) - $compared) }} da verificare</span>
</div>
