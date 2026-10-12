<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ request()->routeIs('gestionale.*') ? 'REKO Gestionale e CRM — Accesso riservato' : (filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel')) }}
</title>

<link rel="icon" href="/favicon.svg" type="image/svg+xml">

{{-- Il Gestionale usa Manrope e IBM Plex Mono (gestionale.css): niente Instrument Sans di Flux --}}
@unless (isset($stylesheet))
    @fonts
@endunless

@vite([$stylesheet ?? 'resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
{{-- Come Trova, REKO usa solo il tema chiaro --}}
<script>window.Flux.applyAppearance('light')</script>
