<?php

use App\Gestionale\Actions\Properties\SaveProperty;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\Property;
use App\Models\TerritorialProvince;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::gestionale')] class extends Component {
    use HandlesCommands;

    public ?Property $property = null;
    public ?int $agent_user_id = null;
    public ?int $municipality_id = null;
    public string $title = '';
    public string $address = '';
    public string $civic = '';
    public string $city = '';
    public string $province = '';
    public string $postal_code = '';
    public string $zone = '';
    public string $status = 'Non attivo';
    public array $features = ['operation' => 'Acquisto'];
    public array $mandate = [];
    public array $publication = [];
    public string $description = '';
    public string $strengths = '';
    public string $internal_notes = '';
    public float|string|null $latitude = null;
    public float|string|null $longitude = null;
    public bool $confirm_duplicate = false;
    public string $reason = '';
    #[Url(as: 'unitIds')]
    public array $cadastral_unit_ids = [];

    public function mount(?Property $property = null): void
    {
        if ($property?->exists) {
            $this->authorize('update', $property);
            $this->property = $property;
            $this->fill([
                'agent_user_id' => $property->agent_user_id, 'municipality_id' => $property->municipality_id,
                'title' => $property->title, 'address' => $property->address, 'civic' => (string) $property->civic,
                'city' => (string) ($property->city ?: $property->municipality?->name), 'province' => (string) $property->province,
                'postal_code' => (string) $property->postal_code, 'zone' => (string) $property->zone, 'status' => $property->status,
                'features' => $property->features ?? [], 'mandate' => $property->mandate ?? [], 'publication' => $property->publication ?? [],
                'description' => (string) $property->description, 'strengths' => (string) $property->strengths,
                'internal_notes' => (string) $property->internal_notes,
            ]);
        } else {
            $this->authorize('create', Property::class);
            $this->agent_user_id = $this->actor()->user_id;
            $this->cadastral_unit_ids = array_values(array_unique(array_slice(array_filter(array_map('intval', $this->cadastral_unit_ids)), 0, 100)));
            if ($this->cadastral_unit_ids !== []) {
                $unit = CadastralUnit::query()->with('parcel.municipality')->whereIn('id', $this->cadastral_unit_ids)->first();
                if ($unit?->parcel?->municipality) {
                    $this->municipality_id = $unit->parcel->municipality_id;
                    $this->city = $unit->parcel->municipality->name;
                    $this->title = 'Immobile catastale · '.$unit->parcel->municipality->name;
                }
            }
        }
    }

    #[Computed]
    public function agents()
    {
        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)->active()->with('user')->get()->sortBy(fn ($m) => $m->user->name);
    }

    #[Computed]
    public function provinces()
    {
        return TerritorialProvince::query()->whereHas('municipalities')->orderBy('name')->get();
    }

    public function updatedFeaturesOperation(string $operation): void
    {
        if ($operation === 'Locazione' && $this->status === 'Venduto') $this->status = 'Non attivo';
        if ($operation === 'Acquisto' && $this->status === 'Locato') $this->status = 'Non attivo';
    }

    public function save()
    {
        if ($this->property) $this->authorize('update', $this->property);
        else $this->authorize('create', Property::class);

        $input = [
            'agent_user_id' => $this->agent_user_id, 'municipality_id' => $this->municipality_id,
            'title' => $this->title, 'address' => $this->address, 'civic' => $this->civic, 'city' => $this->city,
            'province' => $this->province, 'postal_code' => $this->postal_code, 'zone' => $this->zone, 'status' => $this->status,
            'features' => $this->features, 'mandate' => $this->mandate, 'publication' => $this->publication,
            'cadastral_unit_ids' => $this->cadastral_unit_ids,
            'description' => $this->description, 'strengths' => $this->strengths, 'internal_notes' => $this->internal_notes,
            'latitude' => $this->latitude, 'longitude' => $this->longitude,
            'confirm_duplicate' => $this->confirm_duplicate, 'reason' => $this->reason,
        ];
        // The form does not edit the links of an existing property: leave them untouched
        if ($this->property) {
            unset($input['cadastral_unit_ids']);
        }

        $saved = $this->command(fn () => app(SaveProperty::class)->handle($this->actor(), $input, $this->property), 'Scheda immobile salvata.');

        if ($saved) return $this->redirectRoute('gestionale.properties.show', $saved, navigate: true);
    }
}; ?>

<div class="mx-auto flex max-w-4xl flex-col gap-6">
    <div>
        <flux:link :href="route('gestionale.properties.index')" wire:navigate>← Immobili a portafoglio</flux:link>
        <flux:heading size="xl" level="1" class="mt-3">{{ $property ? 'Modifica immobile' : 'Nuovo immobile' }}</flux:heading>
        <flux:text class="mt-1">Le informazioni non note restano vuote; non viene pubblicato alcun annuncio.</flux:text>
    </div>

    @error('command')<flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>@enderror
    @if ($errors->has('confirm_duplicate'))
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ $errors->first('confirm_duplicate') }}</flux:callout.heading>
            <flux:callout.text>Se hai verificato che si tratta di un immobile distinto, conferma prima di salvare.</flux:callout.text>
            <flux:checkbox wire:model="confirm_duplicate" label="Confermo che è un immobile distinto" />
        </flux:callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:card class="flex flex-col gap-4">
            @if ($cadastral_unit_ids)
                <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-950">
                    {{ count($cadastral_unit_ids) }} unità catastali selezionate da Trova. Comune e collegamenti sono precompilati: completa i dati commerciali.
                </div>
            @endif
            <flux:heading size="lg">Dati dell’immobile</flux:heading>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="title" label="Titolo" required maxlength="160" />
                <flux:select wire:model.live="features.operation" label="Contratto" required>
                    <flux:select.option value="Acquisto">Vendita</flux:select.option><flux:select.option value="Locazione">Locazione</flux:select.option>
                </flux:select>
                <flux:input wire:model="address" label="Indirizzo" required maxlength="255" />
                <flux:input wire:model="civic" label="Civico" maxlength="40" />
                <flux:input wire:model="city" label="Comune" required maxlength="120" />
                <flux:input wire:model="province" label="Provincia" maxlength="2" />
                <flux:input wire:model="postal_code" label="CAP" maxlength="12" />
                <flux:input wire:model="zone" label="Zona" maxlength="160" />
                <flux:select wire:model="status" label="Stato commerciale">
                    @foreach (App\Models\Property::STATUSES as $value)<flux:select.option :value="$value">{{ $value }}</flux:select.option>@endforeach
                </flux:select>
                @if ($this->actor()->isAdmin())
                    <flux:select wire:model="agent_user_id" label="Referente">
                        @foreach ($this->agents as $agent)<flux:select.option :value="$agent->user_id">{{ $agent->user->name }}</flux:select.option>@endforeach
                    </flux:select>
                @endif
            </div>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">Caratteristiche</flux:heading>
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input wire:model="features.price" type="number" min="0" step="0.01" label="Prezzo / canone (€)" />
                <flux:input wire:model="features.area" type="number" min="0" step="0.01" label="Superficie (m²)" />
                <flux:input wire:model="features.rooms" type="number" min="0" step="1" label="Locali" />
                <flux:input wire:model="features.bedrooms" type="number" min="0" step="1" label="Camere" />
                <flux:input wire:model="features.bathrooms" type="number" min="0" step="1" label="Bagni" />
                <flux:input wire:model="features.condition" label="Stato manutentivo" maxlength="80" />
            </div>
            <flux:textarea wire:model="description" label="Descrizione" rows="4" />
            <flux:textarea wire:model="strengths" label="Punti di forza" rows="3" />
            <flux:textarea wire:model="internal_notes" label="Note interne" rows="3" />
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">Incarico e annuncio</flux:heading>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="mandate.type" label="Tipo di incarico" maxlength="100" />
                <flux:input wire:model="mandate.start" type="date" label="Inizio incarico" />
                <flux:input wire:model="mandate.end" type="date" label="Scadenza incarico" />
                <flux:input wire:model="mandate.commission" label="Provvigione" maxlength="100" />
                <flux:input wire:model="publication.portals" label="Portali previsti" maxlength="300" />
                <flux:input wire:model="publication.url" type="url" label="Link annuncio HTTPS" />
                <flux:input wire:model="latitude" type="number" step="any" label="Latitudine (facoltativa)" />
                <flux:input wire:model="longitude" type="number" step="any" label="Longitudine (facoltativa)" />
            </div>
        </flux:card>

        @if ($property)<flux:textarea wire:model="reason" label="Motivo della modifica" rows="2" />@endif
        <div class="flex gap-2"><flux:button type="submit" variant="primary">Salva immobile</flux:button><flux:button variant="ghost" :href="route('gestionale.properties.index')" wire:navigate>Annulla</flux:button></div>
    </form>
</div>
