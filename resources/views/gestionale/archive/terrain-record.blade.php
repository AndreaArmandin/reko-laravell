@php
    use App\Gestionale\Scouting\Format;
    $context = $entry['context'];
@endphp
<article class="census-unit-record" wire:key="terrain-{{ $entry['parcelId'] }}">
    <div class="census-unit-head">
        <div><strong>{{ $entry['address'] }}</strong><small>{{ $context['municipality'] }} · Catasto Terreni</small></div>
        <x-gestionale.crm-pill>Perimetro acquisito</x-gestionale.crm-pill>
    </div>
    <dl class="census-unit-facts">
        <div><dt>Comune</dt><dd>{{ $context['municipality'] }}</dd></div>
        <div><dt>Sezione</dt><dd>{{ $context['section'] ?: '—' }}</dd></div>
        <div><dt>Foglio</dt><dd>{{ $context['sheet'] }}</dd></div>
        <div><dt>Particella</dt><dd>{{ $context['parcel'] }}</dd></div>
        @if ($entry['areaSqm'] !== null)<div><dt>Superficie del perimetro</dt><dd>{{ Format::area($entry['areaSqm']) }}</dd></div>@endif
    </dl>
    <p>Acquisito il {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($entry['acquiredAt'], true) }} · edizione catastale {{ $entry['releaseDate'] ?: 'non indicata' }}</p>
    <p class="crm-muted">Perimetro cartografico verificato. Non sono state create unità, qualità catastali o intestazioni: i dati restano distinti da quelli da verificare nella fonte.</p>
    <x-gestionale.record-activities :context="['parcel_id' => $entry['parcelId']]" compact hide-summary hide-empty-summary />
</article>
