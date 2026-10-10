@props(['request', 'progress', 'showClient' => true, 'showActivities' => true])
@php
    use App\Gestionale\Questionnaire\AnswerPresenter as A;
    use App\Gestionale\Questionnaire\Questionnaire;
    // common.tsx RequestRow: avatar, titolo, cliente, riepilogo (budget, zone, m², camere), stato e avanzamento.
    $c = $request->criteria ?? [];
    $summary = implode(' · ', array_filter([
        Questionnaire::hasAnswer($c['budget'] ?? null) ? A::money($c['budget']).(($c['operation'] ?? null) === 'Locazione' ? '/mese' : '') : '',
        Questionnaire::hasAnswer($c['zones'] ?? null) ? A::label($c['zones']) : '',
        ...array_map(fn ($id) => Questionnaire::hasAnswer($c[$id] ?? null) ? A::answerLabel($id, $c[$id]) : '', ['area', 'bedrooms']),
    ]));
    $name = $request->contact?->display_name ?? 'Richiesta';
@endphp
<article>
    <a class="crm-record-row crm-request-row{{ ! $showClient ? ' crm-request-row--single' : '' }}" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>
        @if ($showClient)<x-gestionale.crm-avatar :name="$name" :id="$request->contact_id ?? $request->id" />@endif
        <span class="crm-grow">
            <strong>{{ ! $showClient && $request->title_auto ? 'Richiesta immobiliare' : $request->title }}</strong>
            @if ($showClient)<small>{{ $request->contact?->display_name }}</small>@endif
            @if ($summary !== '')<small>{{ $summary }}</small>@endif
        </span>
        <span class="crm-row-tail"><x-gestionale.crm-pill>{{ $request->status }}</x-gestionale.crm-pill><x-gestionale.crm-answer-progress :answered="$progress['answered']" :total="$progress['total']" /></span>
        <x-gestionale.lucide name="arrow-up-right" :size="17" />
    </a>
    @if ($showActivities)
        <x-gestionale.record-activities :context="['contact_id' => (int) $request->contact_id, 'property_request_id' => (int) $request->id]" :compact="true" />
    @endif
</article>
