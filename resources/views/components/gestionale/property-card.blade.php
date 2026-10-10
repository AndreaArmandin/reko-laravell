@props(['property'])
@php
    use App\Gestionale\Properties\TestRecords;
    use App\Gestionale\Questionnaire\AnswerPresenter;
    use App\Gestionale\Questionnaire\Questionnaire;

    // common.tsx PropertyCard
    $f = $property->features ?? [];
    $has = fn ($key) => Questionnaire::hasAnswer($f[$key] ?? null);
    $details = array_filter([
        ($f['operation'] ?? null) === 'Acquisto' ? 'In vendita' : (($f['operation'] ?? null) === 'Locazione' ? 'In affitto' : 'Operazione non indicata'),
        $has('occupancy') ? 'Occupazione: '.AnswerPresenter::label($f['occupancy']) : '',
        $has('rooms') ? AnswerPresenter::label($f['rooms']).($f['rooms'] === 1 ? ' locale' : ' locali') : '',
        $has('energy') ? 'Classe energetica '.AnswerPresenter::label($f['energy']) : '',
    ]);
@endphp
<article wire:key="property-{{ $property->id }}">
    <a class="crm-property-card" style="display:block" href="{{ route('gestionale.properties.show', $property) }}" wire:navigate>
        <div class="crm-property-graphic"><span>{{ $f['typology'] ?? '' }}</span><strong>{{ $property->zone }}</strong></div>
        <div class="crm-property-info">
            <div class="crm-between"><x-gestionale.crm-pill>Stato scheda: {{ $property->status }}</x-gestionale.crm-pill><small>{{ $property->code }}</small></div>
            <h3>{{ $property->title }}</h3>
            @if (TestRecords::isTestProperty($property))<x-gestionale.crm-pill>TEST · dati fittizi</x-gestionale.crm-pill>@endif
            <p>{{ implode(' ', array_filter([$property->address, $property->civic, $property->city])) }}</p>
            <div class="crm-property-stats">
                @if ($has('price'))<strong>{{ AnswerPresenter::money($f['price']) }}@if (($f['operation'] ?? null) === 'Locazione')<small>/mese</small>@endif</strong>@endif
                @if ($has('area'))<span>{{ AnswerPresenter::label($f['area']) }} m²</span>@endif
            </div>
            <small>{{ implode(' · ', $details) }}</small>
        </div>
    </a>
    <x-gestionale.record-activities :subject="$property" :compact="true" />
</article>
