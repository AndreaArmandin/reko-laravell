@props(['question', 'options' => null, 'model', 'disabled' => false])
{{-- Controllo per tipo di domanda (AnswerInput del vecchio gestionale). Il valore si converte con AnswerPresenter::fromField. --}}
@php($type = $question['type'])
<div {{ $attributes->class('flex flex-col gap-2') }}>
    @if ($type === 'boolean')
        <flux:radio.group wire:model.live.debounce.650ms="{{ $model }}" variant="segmented" :disabled="$disabled">
            <flux:radio value="1" label="Sì" />
            <flux:radio value="0" label="No" />
        </flux:radio.group>
    @elseif ($type === 'single')
        @if (count($options ?? []) <= 4)
            <flux:radio.group wire:model.live.debounce.650ms="{{ $model }}" variant="segmented" :disabled="$disabled">
                @foreach ($options ?? [] as $option)
                    <flux:radio :value="$option" :label="$option" />
                @endforeach
            </flux:radio.group>
        @else
            <flux:select wire:model.live.debounce.650ms="{{ $model }}" :disabled="$disabled" placeholder="Scegli…">
                <flux:select.option value="">Da definire</flux:select.option>
                @foreach ($options ?? [] as $option)
                    <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    @elseif ($type === 'multi' && $question['id'] === 'tags')
        <flux:input wire:model.live.debounce.650ms="{{ $model }}" :disabled="$disabled" placeholder="Tag separati da virgola (massimo 30)" />
    @elseif ($type === 'multi')
        @if (($options ?? []) === [])
            <flux:text size="sm">Nessuna opzione configurata per questa agenzia.</flux:text>
        @else
            <flux:checkbox.group wire:model.live.debounce.650ms="{{ $model }}" :disabled="$disabled" class="grid gap-1 sm:grid-cols-2">
                @foreach ($options as $option)
                    <flux:checkbox :value="$option" :label="$option" />
                @endforeach
            </flux:checkbox.group>
        @endif
    @elseif ($type === 'range')
        <div class="grid grid-cols-2 gap-2">
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.min" inputmode="decimal" placeholder="Da" :disabled="$disabled" />
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.max" inputmode="decimal" placeholder="A" :disabled="$disabled" />
        </div>
    @elseif ($type === 'financing')
        <div class="grid grid-cols-2 gap-2">
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.amount" inputmode="decimal" placeholder="Importo €" :disabled="$disabled" />
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.percent" inputmode="decimal" placeholder="Percentuale %" :disabled="$disabled" />
        </div>
    @elseif ($type === 'amount')
        <flux:input wire:model.live.debounce.650ms="{{ $model }}" inputmode="decimal" :disabled="$disabled" />
    @elseif ($type === 'date')
        <flux:input type="date" wire:model.live.debounce.650ms="{{ $model }}" :disabled="$disabled" />
    @elseif ($type === 'zone')
        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.label" placeholder="Luogo" :disabled="$disabled" />
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.lat" inputmode="decimal" placeholder="Latitudine" :disabled="$disabled" />
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.lng" inputmode="decimal" placeholder="Longitudine" :disabled="$disabled" />
            <flux:input wire:model.live.debounce.650ms="{{ $model }}.radius" inputmode="decimal" placeholder="Raggio km" :disabled="$disabled" />
        </div>
    @else
        <flux:textarea wire:model.live.debounce.650ms="{{ $model }}" rows="3" maxlength="4000" :disabled="$disabled" />
    @endif
</div>
