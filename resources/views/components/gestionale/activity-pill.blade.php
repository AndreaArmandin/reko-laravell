@props(['tone' => ''])
@php
    // common.tsx Pill: il tono semantico dipende dal testo (statusTone) e sceglie l'icona.
    $semantic = \App\Gestionale\Activities\ActivityPresentation::statusTone(trim(strip_tags((string) $slot)), $tone);
    $icon = match ($semantic) { 'green' => 'circle-check', 'danger' => 'triangle-alert', 'warm' => 'clock', 'info' => 'info', default => 'circle' };
@endphp
<span class="crm-pill {{ $semantic }}"><x-gestionale.activity-icon :name="$icon" :size="14" /><span>{{ $slot }}</span></span>
