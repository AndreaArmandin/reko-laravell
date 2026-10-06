<?php

use App\Gestionale\CurrentAgency;
use App\Gestionale\Navigation;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Segnaposto per le sezioni del gestionale delle fasi successive (stesse voci e permessi del vecchio gestionale).
 */
new #[Layout('layouts::gestionale')] class extends Component {
    public string $section;

    public string $label;

    public function mount(string $section): void
    {
        $key = Navigation::fromSlug(app(CurrentAgency::class)->membership(), $section);
        abort_if($key === null, 404);
        $this->section = $section;
        $this->label = Navigation::SECTIONS[$key][0];
    }
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl" level="1">{{ $label }}</flux:heading>
    <flux:callout icon="wrench-screwdriver">
        <flux:callout.text>Questa sezione arriva con le prossime fasi del gestionale.</flux:callout.text>
    </flux:callout>
</div>
