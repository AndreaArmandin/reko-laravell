@props(['title', 'text'])

{{-- ContextGuide di Trova (components/context-guide.tsx): riquadro sopra il pulsante "Guida" --}}
<span x-data="{ open: false, help: document.documentElement.dataset.rekoFieldHelp === 'on', style: '', arrow: 163 }" x-on:keydown.escape.window="open = false" x-on:reko-field-help.window="help = $event.detail" class="contents">
    <button type="button" x-ref="trigger" aria-haspopup="dialog" :aria-expanded="open" data-reko-guide-ui="true"
        aria-label="Apri la guida della pagina" title="Apri la guida" class="reko-page-guide"
        x-on:click="const r = $refs.trigger.getBoundingClientRect(), left = Math.max(12, Math.min(window.innerWidth - 352, r.left + r.width / 2 - 170)); arrow = r.left + r.width / 2 - left - 7; style = 'position:fixed;left:' + left + 'px;bottom:' + (window.innerHeight - r.top + 12) + 'px'; open = ! open">
        <x-trova.icon name="circle-question-mark" size="21" class="lucide-help-circle lucide-circle-help" /><span>Guida</span>
    </button>
    <template x-teleport="body">
        <div class="reko-guide-positioner" x-show="open" x-cloak :style="style">
            <div role="dialog" data-side="top" class="reko-guide-popup" aria-label="{{ $title }}" x-on:click.outside="if (! $refs.trigger.contains($event.target)) open = false">
                <div data-side="top" aria-hidden="true" class="reko-guide-arrow" :style="'position:absolute;left:' + arrow + 'px'"></div>
                <h2 class="reko-guide-title">{{ $title }}</h2>
                <div class="reko-guide-copy"><p>{{ $text }}</p></div>
                <button type="button" class="reko-guide-mode" :aria-pressed="help" x-on:click="help = ! help; document.documentElement.dataset.rekoFieldHelp = help ? 'on' : 'off'; window.dispatchEvent(new CustomEvent('reko-field-help', { detail: help }))" x-text="help ? 'Disattiva gli aiuti sui campi' : 'Attiva gli aiuti sui campi'"></button>
                <button type="button" aria-label="Chiudi spiegazione" class="reko-guide-close" x-on:click="open = false"><x-trova.icon name="x" size="20" /></button>
            </div>
        </div>
    </template>
</span>
