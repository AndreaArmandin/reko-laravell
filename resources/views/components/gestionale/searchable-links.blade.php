@props(['legend', 'model', 'searchModel', 'onlyModel', 'options', 'matching', 'selected', 'unavailable' => 0, 'query' => '', 'placeholder' => 'Cerca per nome o riferimento'])
@php($uid = 'links-'.\Illuminate\Support\Str::random(6))

{{--
    searchable-links.tsx SearchableLinks: ricerca e collegamento esplicito. La ricerca non cambia le selezioni:
    quelle fuori dai risultati restano nella proprietà Livewire $model. Le opzioni arrivano già filtrate dal server
    (PropertyLinks) e si mostrano al massimo 50 righe per ricerca; i collegamenti scelti si vedono sempre.
--}}
<fieldset class="crm-fieldset">
    <legend>{{ $legend }}</legend>
    <label for="{{ $uid }}-search">Cerca {{ mb_strtolower($legend) }}</label>
    <input id="{{ $uid }}-search" type="search" wire:model.live.debounce.300ms="{{ $searchModel }}" placeholder="{{ $placeholder }}">
    <label class="crm-check"><input type="checkbox" wire:model.live="{{ $onlyModel }}">Mostra solo selezionati</label>
    <p role="status" aria-live="polite">{{ count($options) }} su {{ $matching > 1000 ? 'oltre 1.000' : $matching }} visibili · {{ $selected }} selezionati. La ricerca non cambia le selezioni.</p>
    <div class="crm-check-scroll">
        @foreach ($options as $option)
            <label class="crm-check" wire:key="{{ $uid }}-{{ $option['value'] }}"><input type="checkbox" wire:model="{{ $model }}" value="{{ $option['value'] }}"><span>{{ $option['label'] }}</span></label>
        @endforeach
    </div>
    @if (count($options) === 0)<p>Nessuna corrispondenza. Cambia ricerca o disattiva “Mostra solo selezionati”.</p>@endif
    @if ($unavailable > 0)<p role="status">{{ $unavailable }} collegamenti già salvati non sono presenti nell’elenco corrente. Sono conservati e non vengono rimossi dalla ricerca.</p>@endif
    @if ($query !== '')<button type="button" class="crm-link" wire:click="$set('{{ $searchModel }}', '')">Cancella ricerca</button>@endif
</fieldset>
