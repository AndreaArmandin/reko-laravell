<?php

use App\Gestionale\Actions\Activities\RespondToActivity;
use App\Gestionale\Actions\Activities\SaveActivity;
use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Activities\ActivityContext;
use App\Gestionale\Activities\ActivityPresentation as P;
use App\Gestionale\CommandRejected;
use App\Gestionale\Livewire\HandlesCommands;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
| Finestre dell'agenda (activity-form.tsx "ActivityForm" e activities.tsx "ActivityResponse"), condivise da
| agenda e schede. Una pagina può avere più blocchi di attività: ognuno ha il suo "scope" e questa
| istanza risponde solo agli eventi del proprio scope. Dopo ogni salvataggio invia "activities-changed".
*/
new class extends Component
{
    use HandlesCommands;

    #[Locked]
    public string $scope = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $context = [];

    /** form | response | null */
    #[Locked]
    public ?string $dialog = null;

    #[Locked]
    public ?int $activityId = null;

    /** True for a standalone one-off reminder or when editing one. */
    #[Locked]
    public bool $reminder = false;

    /** the dialog completes an existing activity ("Segna come fatta" / "Registra esito") */
    #[Locked]
    public bool $markDone = false;

    #[Locked]
    public string $response = '';

    #[Locked]
    public string $token = '';

    public string $type = 'Telefonata';

    public string $title = '';

    public string $outcome = '';

    public string $note = '';

    public string $dueAt = '';

    public bool $withReminder = false;

    public string $reminderTitle = '';

    public string $reminderAt = '';

    public string $reason = '';

    public string $responseDueAt = '';

    public function mount(string $scope = '', array $context = []): void
    {
        $this->scope = $scope;
        $this->context = ActivityContext::normalize($context);
    }

    private function load(int $id): ?Activity
    {
        $actor = $this->actor();
        $activity = Activity::query()->where('agency_id', $actor->agency_id)->with(['units', 'participants', 'events'])->find($id);
        if (! $activity || ! app(ActivityAccess::class)->canSee($actor, $activity)) {
            return null;
        }

        return $activity;
    }

    #[On('activity-form')]
    public function openForm(string $scope, ?int $id = null, bool $done = false, ?string $mode = null): void
    {
        if ($scope !== $this->scope) {
            return;
        }
        $this->resetErrorBag();
        $this->reset('type', 'title', 'outcome', 'note', 'dueAt', 'withReminder', 'reminderTitle', 'reminderAt', 'reason', 'responseDueAt', 'response');
        $this->token = (string) Str::uuid();
        $this->activityId = $id;
        $this->markDone = $done;
        $this->reminder = $mode === 'reminder';
        if ($this->reminder && $id === null) {
            $this->addError('command', 'Crea il promemoria insieme a una nuova attività.');
            $this->reminder = false;

            return;
        }

        if ($id !== null) {
            $activity = $this->load($id);
            if (! $activity) {
                $this->addError('command', 'Attività non modificabile con questo ruolo.');

                return;
            }
            $this->reminder = $activity->kind === 'Promemoria';
            $this->type = in_array($activity->kind, ActivityCatalog::TYPES, true) ? $activity->kind : 'Telefonata';
            $this->title = $activity->subject;
            $this->outcome = (string) $activity->outcome;
            $this->note = (string) $activity->notes;
            $this->dueAt = P::localInput($activity->scheduled_at);
        }
        $this->dialog = 'form';
    }

    #[On('activity-response')]
    public function openResponse(string $scope, int $id, string $response): void
    {
        if ($scope !== $this->scope) {
            return;
        }
        $this->resetErrorBag();
        $this->reset('reason', 'responseDueAt');
        if (! in_array($response, ActivityCatalog::RESPONSES, true) || ! $this->load($id)) {
            return;
        }
        $this->activityId = $id;
        $this->response = $response;
        $this->dialog = 'response';
    }

    /** "Accetta" is immediate: no reason, no dialog. */
    #[On('activity-accept')]
    public function accept(string $scope, int $id): void
    {
        if ($scope !== $this->scope || ! ($activity = $this->load($id))) {
            return;
        }
        $done = $this->command(fn () => app(RespondToActivity::class)->handle($this->actor(), $activity, 'Accetta'));
        if ($done) {
            $this->dispatch('activities-changed');
        }
    }

    /** activity.done for a reminder: "Segna come fatta" without the form. */
    #[On('activity-done')]
    public function done(string $scope, int $id): void
    {
        if ($scope !== $this->scope || ! ($activity = $this->load($id))) {
            return;
        }
        $done = $this->command(fn () => app(RespondToActivity::class)->handle($this->actor(), $activity, 'Completa'));
        if ($done) {
            $this->dispatch('activities-changed');
        }
    }

    public function close(): void
    {
        $this->dialog = null;
        $this->activityId = null;
        $this->resetErrorBag();
    }

    #[Computed]
    public function stored(): ?Activity
    {
        return $this->activityId ? $this->load($this->activityId) : null;
    }

    /** activity-form.tsx: an already confirmed activity is corrected with a reason. */
    #[Computed]
    public function confirmed(): bool
    {
        return $this->stored !== null && ($this->stored->isDone() || $this->stored->outcome_confirmed_at !== null);
    }

    #[Computed]
    public function recording(): bool
    {
        return ! $this->reminder && ($this->activityId === null || $this->markDone || (bool) $this->stored?->isDone());
    }

    /** @return list<string> */
    #[Computed]
    public function outcomes(): array
    {
        return ActivityCatalog::formOutcomes($this->stored?->contact_operation);
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function banner(): ?array
    {
        return ActivityContext::banner($this->stored ? ActivityAccess::linksOfActivity($this->stored) : $this->context);
    }

    #[Computed]
    public function author(): string
    {
        $stored = $this->stored;
        if ($stored) {
            return 'Registrata da '.(User::query()->whereKey($stored->created_by_user_id)->value('name') ?: 'operatore precedente').' · '.P::dateLabel($stored->created_at, true);
        }

        return 'Autore: '.auth()->user()->name.'. Data e ora di creazione vengono registrate automaticamente.';
    }

    public function save(): void
    {
        $reminder = $this->reminder;
        $recording = $this->recording;
        $stored = $this->stored;
        $actor = $this->actor();

        $this->command(function () use ($reminder, $recording, $stored, $actor) {
            $dueAt = $this->dueAt !== '' ? SaveActivity::parseDate($this->dueAt) : null;
            $reminderAt = $this->reminderAt !== '' ? SaveActivity::parseDate($this->reminderAt) : null;
            if ($reminder && ($dueAt === null || ($stored === null && $dueAt->lte(now())))) {
                throw new CommandRejected('Indica una data e un’ora future per il promemoria.');
            }
            if ($this->withReminder && (trim($this->reminderTitle) === '' || $reminderAt === null || $reminderAt->lte(now()))) {
                throw new CommandRejected('Completa il titolo e scegli una data e un’ora future per il promemoria.');
            }
            if ($recording && (trim($this->outcome) === '' || trim($this->note) === '')) {
                throw new CommandRejected('Completa l’esito e il commento dell’attività.');
            }

            $input = [
                'type' => $reminder ? 'Promemoria' : $this->type,
                'title' => $reminder ? trim($this->title) : ($stored?->subject ?: $this->type.' · '.trim($this->outcome)),
                'notes' => trim($this->note),
                'outcome' => $reminder ? '' : trim($this->outcome),
                'done' => $reminder ? $stored?->isDone() === true : $recording,
                'recorded' => $recording,
                'assigned_to_user_id' => $stored?->assigned_to_user_id ?: $actor->user_id,
                'shared_with' => $stored ? $stored->participants->pluck('user_id')->filter()->all() : [],
                'internal' => $stored ? $stored->internal : true,
                'contact_operation' => $stored?->contact_operation,
                'reason' => trim($this->reason),
                'answered' => ! $reminder && in_array(trim($this->outcome), ActivityCatalog::contactOutcomes(), true),
            ];
            if ($reminder) {
                $input['due_at'] = $this->dueAt;
            } elseif ($stored) {
                $input['due_at'] = $stored->scheduled_at;
            }
            if (! $stored) {
                $input += $this->context + ['idempotency_key' => $this->token];
                if (isset($input['unit_ids'])) {
                    $input['unit_ids'] = array_map('intval', $input['unit_ids']);
                }
            }
            if ($this->withReminder) {
                $input['reminder'] = ['title' => trim($this->reminderTitle), 'due_at' => $this->reminderAt];
            }

            return app(SaveActivity::class)->handle($actor, $stored, $input);
        }, $reminder ? 'Promemoria salvato' : ($this->withReminder ? 'Attività registrata e promemoria creato' : ($recording ? 'Attività registrata' : 'Attività aggiornata')));

        if ($this->getErrorBag()->isEmpty()) {
            $this->close();
            $this->dispatch('activities-changed');
        }
    }

    public function respond(): void
    {
        $activity = $this->stored;
        $response = $this->response;
        if (! $activity) {
            $this->addError('command', 'Solo il destinatario può gestire l’avanzamento.');

            return;
        }
        $this->command(fn () => app(RespondToActivity::class)->handle($this->actor(), $activity, $response, [
            'reason' => $this->reason,
            'due_at' => $response === 'Rinvia' ? $this->responseDueAt : null,
        ]));
        if ($this->getErrorBag()->isEmpty()) {
            $this->close();
            $this->dispatch('activities-changed');
        }
    }
}; ?>

<div>
    @if ($dialog === 'form')
        @php
            $stored = $this->stored;
            $recording = $this->recording;
            $confirmed = $this->confirmed;
            $banner = $this->banner;
            $heading = $reminder ? ($activityId ? 'Modifica promemoria' : 'Nuovo promemoria') : ($confirmed ? 'Correggi attività' : ($recording ? 'Registra attività' : 'Modifica attività programmata'));
        @endphp
        <dialog class="proto-dialog reko-prototype" wire:key="dialog-form-{{ $token }}" aria-labelledby="activity-dialog-title" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.close()" x-on:click="if ($event.target === $el) $wire.close()">
            <div class="proto-dialog-heading">
                <div><p class="proto-eyebrow">REKO Gestionale</p><h2 id="activity-dialog-title">{{ $heading }}</h2></div>
                <button type="button" class="crm-dialog-close" aria-label="Chiudi" wire:click="close"><x-gestionale.activity-icon name="x" :size="16" /></button>
            </div>
            @if ($stored && $stored->kind === 'Appuntamento di acquisizione')
                <p>Questa attività appartiene al percorso incarichi sospeso. I dati restano conservati.</p>
            @else
            <form class="crm-form crm-daily-activity-form" wire:submit="save">
                @if ($banner)
                    <section class="crm-activity-context" aria-label="Collegamento automatico">
                        <span>{{ $banner['heading'] }}</span><strong>{{ $banner['text'] }}</strong>
                        @if ($banner['address'])<p>{{ $banner['address'] }}</p>@endif
                        @if ($banner['client'])<p>{{ $banner['client'] }}</p>@endif
                        <small>Collegamento automatico alla scheda di partenza.</small>
                    </section>
                @endif
                @if ($reminder)
                    <label class="crm-field"><span>Titolo promemoria</span><input wire:model="title" required maxlength="180" placeholder="Che cosa devi ricordare?"></label>
                    <label class="crm-field"><span>Data e ora</span><input type="datetime-local" wire:model="dueAt" required></label>
                    <label class="crm-field"><span>Commento · facoltativo</span><textarea wire:model="note" maxlength="4000"></textarea></label>
                @else
                    <label class="crm-field"><span>Tipo di attività</span>
                        <select wire:model="type">
                            @foreach (array_values(array_filter(\App\Gestionale\Activities\ActivityCatalog::TYPES, fn ($t) => ! in_array($t, \App\Gestionale\Activities\ActivityCatalog::NOT_SELECTABLE, true))) as $option)
                                <option>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="crm-field"><span>{{ $recording ? 'Esito' : 'Esito · da compilare quando svolta' }}</span>
                        <input wire:model="outcome" @required($recording) list="reko-daily-outcomes" placeholder="Scegli o scrivi l’esito" maxlength="300">
                        <datalist id="reko-daily-outcomes">@foreach ($this->outcomes as $value)<option value="{{ $value }}"></option>@endforeach</datalist>
                    </label>
                    <label class="crm-field"><span>Commento</span><textarea wire:model="note" @required($recording) maxlength="4000" placeholder="Che cosa è stato fatto? Che cosa è emerso?"></textarea></label>
                    @if (! $activityId)
                        <label class="crm-check"><input type="checkbox" wire:model.live="withReminder">Crea anche un promemoria</label>
                        @if ($withReminder)
                            <fieldset class="crm-fieldset crm-followup-fields">
                                <legend>Promemoria collegato</legend>
                                <label class="crm-field"><span>Titolo promemoria</span><input wire:model="reminderTitle" required maxlength="180" placeholder="Es. richiamare per una risposta"></label>
                                <label class="crm-field"><span>Data e ora del promemoria</span><input type="datetime-local" wire:model="reminderAt" required></label>
                            </fieldset>
                        @endif
                    @endif
                @endif
                @if ($confirmed)
                    <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="reason" required maxlength="4000"></textarea></label>
                @endif
                <p class="crm-activity-audit">{{ $this->author }}</p>
                @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                <div class="crm-actions">
                    <button class="crm-btn" wire:loading.attr="disabled" wire:target="save">{{ $reminder ? 'Salva promemoria' : ($withReminder ? 'Registra attività e promemoria' : ($recording ? 'Registra attività' : 'Salva modifiche')) }}</button>
                    <button type="button" class="crm-btn secondary" wire:click="close" wire:loading.attr="disabled" wire:target="save">Annulla</button>
                </div>
            </form>
            @endif
        </dialog>
    @elseif ($dialog === 'response')
        @php $stored = $this->stored; @endphp
        <dialog class="proto-dialog reko-prototype" wire:key="dialog-response-{{ $activityId }}-{{ $response }}" aria-labelledby="activity-dialog-title" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.close()" x-on:click="if ($event.target === $el) $wire.close()">
            <div class="proto-dialog-heading">
                <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="activity-dialog-title">{{ \App\Gestionale\Activities\ActivityCatalog::responseLabel($response) }}</h2></div>
                <button type="button" class="crm-dialog-close" aria-label="Chiudi" wire:click="close"><x-gestionale.activity-icon name="x" :size="16" /></button>
            </div>
            <form class="crm-form" wire:submit="respond">
                <p>{{ $stored?->subject }}</p>
                @if ($response === 'Chiedi chiarimenti')
                    <p class="crm-muted">Registra una domanda per il referente dell’attività. Rimane nello storico; non invia email o messaggi esterni.</p>
                @endif
                @if ($response === 'Rinvia')
                    <label class="crm-field"><span>Nuova data e ora</span><input type="datetime-local" wire:model="responseDueAt" required></label>
                @endif
                <label class="crm-field"><span>{{ $response === 'Chiedi chiarimenti' ? 'Chiarimento richiesto' : 'Motivo' }}</span><textarea wire:model="reason" required></textarea></label>
                @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                <button class="crm-btn" wire:loading.attr="disabled" wire:target="respond">Conferma</button>
            </form>
        </dialog>
    @endif
    @if ($dialog === null)
        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
    @endif
</div>
