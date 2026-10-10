<x-gestionale.scouting.dialog title="Cartografia REKO" close="$wire.closeCartography()" :wide="true">
    <div class="crm-form">
        <p>Scegli il Comune nella mappa, poi avvicinati e seleziona una particella. La presenza sulla mappa non significa che tutti i subalterni siano già nell’archivio catastale.</p>
        @forelse ($this->inventories as $inventory)
            <section class="crm-panel">
                <h3>{{ $inventory['municipality'] }} · {{ $inventory['code'] }}</h3>
                <p><strong>{{ number_format($inventory['parcels'], 0, ',', '.') }} perimetri di particella disponibili</strong></p>
                @if ($inventory['parcels'] === 0)
                    <p>Per questo Comune non sono ancora disponibili confini di particella. Le schede catastali restano consultabili nell’Archivio.</p>
                @elseif ($inventory['code'] === 'X002')
                    <p class="crm-import-warning">Campione dimostrativo: i perimetri catastali sono geometrie sintetiche basate sugli edifici OpenStreetMap e non rappresentano confini catastali ufficiali.</p>
                @endif
                <p>I dati dei diversi Comuni rimangono separati. Le sagome degli edifici aiutano a orientarsi e non rappresentano i singoli subalterni.</p>
            </section>
        @empty
            <div class="crm-empty">Nessuna cartografia disponibile al momento.</div>
        @endforelse
        @if ($this->membership->role === 'admin')
            <p><a class="crm-btn secondary" href="{{ route('admin.catalog.index') }}" wire:navigate>Apri gestione cartografia</a></p>
        @endif
    </div>
</x-gestionale.scouting.dialog>
