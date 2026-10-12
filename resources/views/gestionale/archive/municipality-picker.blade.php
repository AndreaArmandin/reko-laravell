{{--
    municipality-autocomplete.tsx MunicipalityAutocomplete. Variabili: $id, $model (proprietà con il testo digitato), $openProperty,
    $options (name/province/code), $activeIndex, $isOpen, $select/$move/$choose/$close (metodi Livewire), $listLabel, $emptyText e $hint (facoltativo).
--}}
<label class="crm-field"><span>Comune</span>
    <span class="census-municipality-picker" x-data x-on:click.outside="if ($wire.{{ $openProperty }}) $wire.{{ $close }}()">
        <input wire:model.live.debounce.250ms="{{ $model }}" type="search" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="{{ $id }}-options" aria-activedescendant="{{ $id }}-option-{{ $activeIndex }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" autocomplete="off" placeholder="Es. Milano, Cuneo…" wire:focus="$set('{{ $openProperty }}', true)" wire:blur="{{ $close }}" wire:keydown.arrow-down.prevent="{{ $move }}(1)" wire:keydown.arrow-up.prevent="{{ $move }}(-1)" wire:keydown.enter.prevent="{{ $choose }}" wire:keydown.escape="{{ $close }}">
        @if ($isOpen)
            <span class="census-municipality-options" id="{{ $id }}-options" role="listbox" aria-label="{{ $listLabel }}">
                @forelse ($options as $index => $municipality)
                    <button type="button" role="option" aria-selected="{{ $index === $activeIndex ? 'true' : 'false' }}" id="{{ $id }}-option-{{ $index }}" wire:click="{{ $select }}('{{ $municipality['code'] }}')" x-on:mousedown.prevent>
                        <span>{{ $municipality['name'] }}</span><small>{{ $municipality['province'] ?: 'Provincia non indicata' }} · {{ $municipality['code'] }}</small>
                    </button>
                @empty
                    <span role="status">{{ $emptyText }}</span>
                @endforelse
            </span>
        @endif
    </span>
    @if (! empty($hint))<small>{{ $hint }}</small>@endif
</label>
