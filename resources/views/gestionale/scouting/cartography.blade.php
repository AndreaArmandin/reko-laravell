<x-gestionale.scouting.dialog title="Cartografia REKO" label="Disponibile nel Gestionale" close="$wire.closeCartography()">
    <div class="crm-form">
        <p>Scegli il Comune nella mappa Scout, poi avvicinati e seleziona una particella. La cartografia è già caricata: non devi attivare i singoli fogli.</p>
        @if ($this->inventories === [])
            <p>Nessuna cartografia disponibile al momento.</p>
        @endif
        @foreach ($this->inventories as $inventory)
            <section class="crm-panel" wire:key="cartography-{{ $inventory['code'] }}">
                <h3>{{ $inventory['municipality'] }} · {{ $inventory['code'] }}</h3>
                <p>{{ number_format($inventory['parcels'], 0, ',', '.') }} particelle inventariate</p>
                <p>Seleziona una particella per consultare gli immobili acquisiti nel catalogo di {{ $inventory['municipality'] }}. L’acquisizione nel censimento dipende dalle abilitazioni del tuo profilo; i dati dei diversi Comuni rimangono separati.</p>
            </section>
        @endforeach
        <p>Le mappe comprendono anche eventuali allegati e sviluppi. La presenza di una particella sulla mappa non significa che siano già disponibili tutti i suoi subalterni. Gli edifici 3D aiutano a orientarsi e non rappresentano i singoli subalterni.</p>
        @if ($isAdmin)
            <details wire:ignore.self><summary>Carica altra cartografia</summary><a class="crm-link" href="{{ route('admin.catalog.index') }}" wire:navigate>Apri gestione cartografia</a></details>
        @endif
    </div>
</x-gestionale.scouting.dialog>
