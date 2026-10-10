<?php

use App\Gestionale\Actions\Activities\RecordCallResult;
use App\Gestionale\Actions\Activities\SaveActivity;
use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Activities\ActivityPresentation;
use App\Gestionale\CommandRejected;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\Activity;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| quick-call-result.tsx "QuickCallResult": esito rapido di una telefonata (Non risponde, Richiama il…,
| Interessato, Non interessato). Si usa con <x-gestionale.quick-call-result :activity="$activity" />:
| mostra il pulsante "Registra esito" solo se la telefonata è ancora da completare e la persona è il
| destinatario (o un amministratore).
*/
new class extends Component
{
    use HandlesCommands;

    #[Locked]
    public int $activityId = 0;

    #[Locked]
    public string $token = '';

    public bool $open = false;

    public string $result = '';

    public string $note = '';

    public string $nextAt = '';

    public function mount(int $activityId): void
    {
        $this->activityId = $activityId;
    }

    #[Computed]
    public function current(): ?Activity
    {
        $actor = $this->actor();
        $activity = Activity::query()->where('agency_id', $actor->agency_id)->with(['units', 'participants'])->find($this->activityId);

        return $activity && app(ActivityAccess::class)->canSee($actor, $activity) ? $activity : null;
    }

    #[Computed]
    public function eligible(): bool
    {
        $current = $this->current;

        return $current !== null && $current->kind === 'Telefonata' && ! $current->isDone() && $current->outcome_confirmed_at === null
            && $current->status !== 'Annullata' && ! ActivityCatalog::suspendedAcquisition($current)
            && app(ActivityAccess::class)->canComplete($this->actor(), $current);
    }

    public function openDialog(): void
    {
        $this->reset('result', 'note', 'nextAt');
        $this->resetErrorBag();
        $this->token = str_replace('-', '', (string) Str::uuid());
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetErrorBag();
    }

    public function choose(string $result): void
    {
        if (collect(ActivityCatalog::CALL_RESULTS)->contains('id', $result)) {
            $this->result = $result;
            $this->resetErrorBag();
        }
    }

    public function save(): void
    {
        $this->command(function () {
            if (! $this->eligible) {
                throw new CommandRejected('La telefonata non è più da completare. Consulta l’agenda aggiornata.');
            }
            if ($this->result === '') {
                throw new CommandRejected('Scegli l’esito della telefonata.');
            }
            $due = $this->result === 'callback' && $this->nextAt !== '' ? SaveActivity::parseDate($this->nextAt) : null;
            if ($this->result === 'callback' && ($due === null || $due->lte(now()))) {
                throw new CommandRejected('Indica una data e un’ora future per il richiamo.');
            }

            return app(RecordCallResult::class)->handle($this->actor(), $this->current, [
                'token' => $this->token, 'result' => $this->result, 'note' => $this->note,
                ...($due ? ['next_at' => $due->toIso8601String()] : []),
            ]);
        }, $this->result === 'callback' ? 'Telefonata completata e richiamo aggiunto all’agenda' : 'Riscontro della telefonata salvato');

        if ($this->getErrorBag()->isEmpty()) {
            $this->close();
            $this->dispatch('activities-changed');
        }
    }
}; ?>

<span>
    @if ($this->eligible)
        <button type="button" class="crm-btn" wire:click="openDialog">Registra esito</button>
    @endif
    @if ($open)
        @php
            $current = $this->current;
            $followup = $current?->nextActivity;
            $remaining = max(0, 4000 - mb_strlen((string) $current?->notes) - ($current?->notes ? 2 : 0));
        @endphp
        <dialog class="proto-dialog reko-prototype" wire:key="call-result-{{ $activityId }}" aria-labelledby="call-result-title-{{ $activityId }}" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.close()" x-on:click="if ($event.target === $el) $wire.close()">
            <div class="proto-dialog-heading">
                <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="call-result-title-{{ $activityId }}">Com’è andata la telefonata?</h2></div>
                <button type="button" class="crm-dialog-close" aria-label="Chiudi" wire:click="close"><x-gestionale.activity-icon name="x" :size="16" /></button>
            </div>
            <p><strong>{{ $current?->subject }}</strong></p>
            @if (! $this->eligible)
                <p role="status">La telefonata non è più da completare. I dati aggiornati sono già nello storico.</p>
                @if ($followup)<p>Richiamo in agenda: {{ ActivityPresentation::dateLabel($followup->scheduled_at, true) }}</p>@endif
                <button type="button" class="crm-btn" wire:click="close">Chiudi</button>
            @else
                <form class="crm-form" wire:submit="save">
                    <fieldset class="crm-fieldset">
                        <legend>Esito della telefonata</legend>
                        <div class="crm-actions">
                            @foreach (ActivityCatalog::CALL_RESULTS as $option)
                                <button type="button" class="crm-btn {{ $result === $option['id'] ? '' : 'secondary' }}" aria-pressed="{{ $result === $option['id'] ? 'true' : 'false' }}" wire:click="choose('{{ $option['id'] }}')">{{ $option['label'] }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                    @if ($result === 'callback')
                        <label class="crm-field"><span>Richiama il giorno e all’ora</span><input type="datetime-local" wire:model="nextAt" required></label>
                    @endif
                    @if ($current?->notes)
                        <details><summary>Nota precedente, conservata</summary><p style="white-space: pre-wrap">{{ $current->notes }}</p></details>
                    @endif
                    <label class="crm-field"><span>Nota della telefonata · facoltativa</span><textarea wire:model="note" maxlength="{{ $remaining }}"></textarea></label>
                    <p class="crm-muted">{{ $result === 'callback' ? 'Telefonata e richiamo saranno salvati insieme, con gli stessi collegamenti e lo stesso responsabile.' : 'Il riscontro resta nello storico della telefonata.' }} Nessun messaggio viene inviato al contatto.</p>
                    @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                    <button class="crm-btn" @disabled($result === '')>{{ $result === 'callback' ? 'Salva esito e richiamo' : 'Salva esito' }}</button>
                </form>
            @endif
        </dialog>
    @endif
</span>
