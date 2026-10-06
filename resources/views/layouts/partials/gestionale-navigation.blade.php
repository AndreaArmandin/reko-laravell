<nav class="crm-navigation" aria-label="Sezioni del Gestionale">
    @foreach ($groups as $group)
        <section class="crm-navigation-group">
            <p>{{ $group['label'] }}</p>
            @foreach ($group['items'] as $item)
                <a href="{{ $item['href'] }}"
                    @if (request()->routeIs($item['pattern'])) aria-current="page" @endif
                    wire:navigate>
                    <flux:icon :name="$item['icon']" class="size-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </section>
    @endforeach
</nav>
