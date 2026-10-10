{{-- GroupedNavigation (crm/index.tsx): stesse voci, ordine, etichette e icone; <a> al posto dei <button>. --}}
<nav aria-label="Sezioni del Gestionale">
    @foreach ($groups as $group)
        <div class="crm-navigation-group">
            @if ($group['label'] !== 'Oggi')<p>{{ $group['label'] }}</p>@endif
            @foreach ($group['items'] as $item)
                <a href="{{ $item['href'] }}" aria-label="{{ $item['label'] }}"
                    @if (request()->routeIs($item['pattern'])) aria-current="page" @endif
                    wire:navigate x-on:click="menu = false">
                    <x-gestionale.lucide :name="$item['icon']" :size="20" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
