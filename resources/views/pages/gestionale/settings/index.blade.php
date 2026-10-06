<?php

use App\Gestionale\Audit;
use App\Gestionale\CurrentAgency;
use App\Models\GestionaleSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::gestionale'), Title('Impostazioni')] class extends Component {
    public array $weights = ['zone' => 20, 'budget' => 20, 'type' => 15, 'surface' => 10, 'rooms' => 10, 'required' => 15, 'preferred' => 5, 'availability' => 5];
    public int $contact_days = 7;
    public int $retention_days = 180;
    public function mount(): void
    {
        abort_unless(app(CurrentAgency::class)->membership()?->isAdmin(), 403);
        $settings = GestionaleSetting::query()->first();
        if ($settings) $this->fill(['weights' => array_replace($this->weights, $settings->matching_weights ?? []), 'contact_days' => $settings->contact_days, 'retention_days' => $settings->retention_days]);
    }

    public function save(): void
    {
        $membership = app(CurrentAgency::class)->membership();
        abort_unless($membership?->isAdmin(), 403);
        $this->validate([
            'weights' => ['required', 'array:zone,budget,type,surface,rooms,required,preferred,availability'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
            'contact_days' => ['required', 'integer', 'min:1', 'max:365'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);
        if (array_sum($this->weights) !== 100) {
            $this->addError('weights', 'La somma dei pesi deve essere 100.');
            return;
        }
        DB::transaction(function () use ($membership) {
            $setting = GestionaleSetting::query()->firstOrNew(['agency_id' => $membership->agency_id]);
            $before = $setting->exists ? $setting->only(['matching_weights', 'contact_days', 'retention_days']) : null;
            $setting->fill(['matching_weights' => $this->weights, 'contact_days' => $this->contact_days, 'retention_days' => $this->retention_days])->save();
            \App\Models\PropertyRequest::query()->where('agency_id', $membership->agency_id)->whereNull('lifecycle_state')->orderBy('id')->chunkById(200,
                fn ($requests) => $requests->each(fn ($request) => app(\App\Gestionale\Properties\PropertyMatcher::class)->refreshRequest($request)));
            app(Audit::class)->record('settings.save', $setting, ['before' => $before, 'after' => $setting->only(['matching_weights', 'contact_days', 'retention_days'])]);
        });
        session()->flash('status', 'Impostazioni dell’agenzia salvate.');
    }
}; ?>

<div class="mx-auto flex max-w-4xl flex-col gap-6">
    <div><flux:heading size="xl" level="1">Impostazioni</flux:heading><flux:text class="mt-1">Regole condivise per abbinare le richieste e gestire i richiami.</flux:text></div>
    @if (session('status'))<flux:callout icon="check-circle">{{ session('status') }}</flux:callout>@endif
    <flux:card>
        <form wire:submit="save" class="flex flex-col gap-5">
            <div><flux:heading size="lg">Pesi degli abbinamenti</flux:heading><flux:text size="sm">Il totale deve essere 100. Le modifiche si applicano ai nuovi confronti.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['zone' => 'Zona', 'budget' => 'Budget', 'type' => 'Tipologia', 'surface' => 'Superficie', 'rooms' => 'Locali', 'required' => 'Caratteristiche indispensabili', 'preferred' => 'Preferenze', 'availability' => 'Disponibilità'] as $key => $label)<flux:input type="number" min="0" max="100" wire:model="weights.{{ $key }}" label="{{ $label }} (%)" />@endforeach
            </div>
            @error('weights')<flux:callout variant="danger">{{ $message }}</flux:callout>@enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" min="1" max="365" wire:model="contact_days" label="Giorni prima di segnalare un richiamo" />
                <flux:input type="number" min="1" max="3650" wire:model="retention_days" label="Giorni di conservazione delle bozze" />
            </div>
            <div><flux:button variant="primary" type="submit">Salva impostazioni</flux:button></div>
        </form>
    </flux:card>
    <flux:callout icon="information-circle" variant="secondary"><flux:callout.text>Il questionario della richiesta mantiene la versione già usata da ciascun cliente. La modifica dei campi del questionario e delle regole avanzate richiede la gestione delle versioni dedicate.</flux:callout.text></flux:callout>
</div>
