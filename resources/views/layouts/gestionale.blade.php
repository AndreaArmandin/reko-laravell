@php
    $current = app(\App\Gestionale\CurrentAgency::class);
    $membership = $current->membership();
    $agencies = $current->available();
    $groups = \App\Gestionale\Navigation::groupedItems($membership);
    $items = collect($groups)->flatMap(fn ($group) => $group['items']);
    $currentLabel = $items->first(fn ($item) => request()->routeIs($item['pattern']))['label'] ?? 'Gestionale';
    $roles = ['admin' => 'Responsabile', 'crm' => 'Segreteria', 'scout' => 'Operatore'];
@endphp
<!DOCTYPE html>
<html lang="it">

<head>
    @include('partials.head')
</head>

<body>
    <div class="crm-app">
        <a href="#crm-main" class="crm-skip">Vai al contenuto</a>

        <aside class="crm-sidebar" aria-label="Navigazione Gestionale">
            <a href="{{ route('gestionale.home') }}" class="reko-brand-logo" aria-label="REKO · Gestionale" wire:navigate>
                <img src="/reko-logo.svg" width="158" height="48" alt="REKO">
            </a>
            <p class="crm-sidebar-caption">GESTIONALE<br><span>Le relazioni diventano opportunità.</span></p>

            @include('layouts.partials.gestionale-navigation', ['groups' => $groups])

            <div class="crm-sidebar-footer">
                @if ($membership?->role === 'admin')
                    <a href="{{ route('gestionale.settings.index') }}" wire:navigate>Impostazioni <flux:icon name="cog-6-tooth" class="size-4" /></a>
                @endif
                <a href="{{ route('trova') }}">Trova immobili <flux:icon name="arrow-up-right" class="size-4" /></a>
                <a href="{{ route('dashboard') }}" wire:navigate>Torna al mio account <flux:icon name="arrow-up-right" class="size-4" /></a>
                <p><flux:icon name="shield-check" class="size-4" /> Accesso personale riservato</p>
                <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
            </div>
        </aside>

        <div class="crm-content">
            <header class="crm-topbar">
                <span class="crm-top-location"><span>REKO Gestionale</span><flux:icon name="chevron-right" class="size-3.5" />{{ $currentLabel }}</span>
                <span class="crm-mobile-title">REKO</span>
                <div class="crm-user-switch">
                    <span>{{ auth()->user()->name }} · {{ $roles[$membership?->role] ?? '' }}</span>
                    @if ($agencies->count() > 1)
                        <select aria-label="Cambia agenzia" onchange="if (this.value) document.getElementById('agency-switch-' + this.value)?.requestSubmit()">
                            @foreach ($agencies as $option)
                                <option value="{{ $option->agency_id }}" @selected($option->agency_id === $membership?->agency_id)>{{ $option->agency->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <strong>{{ $membership?->agency?->name }}</strong>
                    @endif
                    @foreach ($agencies as $option)
                        <form id="agency-switch-{{ $option->agency_id }}" method="POST" action="{{ route('gestionale.enter', $option->agency_id) }}" class="hidden">@csrf</form>
                    @endforeach
                </div>
                <details class="crm-mobile-nav">
                    <summary aria-label="Apri menu">Menu</summary>
                    <div class="crm-mobile-nav-panel">
                        @include('layouts.partials.gestionale-navigation', ['groups' => $groups])
                        <a href="{{ route('trova') }}">Trova immobili</a>
                        <a href="{{ route('dashboard') }}">Account</a>
                    </div>
                </details>
            </header>

            <div class="crm-demo-strip">
                <span>Uso interno dell’agenzia · nessun annuncio viene pubblicato automaticamente sui portali</span>
                <span><flux:icon name="check" class="size-3.5" />{{ $membership?->agency?->name }} · {{ $roles[$membership?->role] ?? '' }}</span>
            </div>

            <main id="crm-main" class="crm-main" tabindex="-1">
                {{ $slot }}
            </main>
            <footer class="crm-footer"><span>REKO Gestionale · archivio riservato</span><span>Dati e ricerche visibili in base al ruolo</span></footer>
        </div>
    </div>

    @persist('toast')
        <flux:toast.group><flux:toast /></flux:toast.group>
    @endpersist
    @fluxScripts
</body>

</html>
