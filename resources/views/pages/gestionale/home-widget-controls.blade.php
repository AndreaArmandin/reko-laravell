<div class="reko-widget-controls" x-show="editing" x-cloak aria-label="Azioni del riquadro {{ $title }}">
    <button type="button" aria-label="Sposta {{ $title }} prima" title="Sposta prima" x-bind:disabled="!moveEnabled('{{ $id }}', -1)" x-on:click="move('{{ $id }}', -1)">
        <x-gestionale.lucide name="arrow-up" :size="16" />
    </button>
    <button type="button" aria-label="Sposta {{ $title }} dopo" title="Sposta dopo" x-bind:disabled="!moveEnabled('{{ $id }}', 1)" x-on:click="move('{{ $id }}', 1)">
        <x-gestionale.lucide name="arrow-down" :size="16" />
    </button>
    <button type="button" x-bind:aria-label="(isWide('{{ $id }}') ? 'Affianca ' : 'Allarga ') + '{{ $title }}'" x-bind:title="isWide('{{ $id }}') ? 'Mezza larghezza' : 'Tutta la larghezza'" x-on:click="toggleWide('{{ $id }}')">
        <span x-show="isWide('{{ $id }}')"><x-gestionale.lucide name="minimize-2" :size="16" /></span>
        <span x-show="!isWide('{{ $id }}')"><x-gestionale.lucide name="maximize-2" :size="16" /></span>
    </button>
    <button type="button" x-show="!definitions.find(widget => widget.id === '{{ $id }}')?.pinned" aria-label="Nascondi {{ $title }}" title="Nascondi riquadro" x-on:click="setVisible('{{ $id }}', false)">
        <x-gestionale.lucide name="eye-off" :size="16" />
    </button>
</div>
