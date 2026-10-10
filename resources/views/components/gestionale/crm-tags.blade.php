@props(['value' => null])
{{-- tag-input.tsx Tags: i tag salvati come etichette, se ce ne sono. --}}
@if (is_array($value) && $value !== [])
    <div class="crm-tags">@foreach ($value as $tag)<span wire:key="tag-{{ \App\Gestionale\Questionnaire\Tags::key((string) $tag) }}">{{ $tag }}</span>@endforeach</div>
@endif
