@props(['tone' => ''])
@php
    // common.tsx Pill: il tono semantico dipende dal testo (statusTone) e sceglie l'icona.
    $semantic = \App\Gestionale\Crm\Presenter::statusTone(trim(strip_tags((string) $slot)), $tone);
@endphp
<span class="crm-pill {{ $semantic }}"><x-gestionale.lucide :name="\App\Gestionale\Crm\Presenter::toneIcon($semantic)" :size="14" /><span>{{ $slot }}</span></span>
