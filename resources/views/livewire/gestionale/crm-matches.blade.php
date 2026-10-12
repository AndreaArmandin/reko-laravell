<?php

use App\Gestionale\Actions\Matches\UpdateMatchState;
use App\Gestionale\Crm\Presenter;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\AnswerPresenter;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\PropertyRequest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * matches.tsx Matches: abbinamenti di UNA richiesta (scheda richiesta) o di UN immobile (tab «Clienti compatibili»),
 * con i filtri, la finestra «Valuta l’abbinamento» e l’anteprima in due passi della proposta (match.state).
 * La visibilità segue visibleTo di immobile e richiesta; la paginazione (10) non esiste nell'originale.
 */
new class extends Component {
    use HandlesCommands;
    use WithPagination;

    #[Locked]
    public ?int $requestId = null;

    #[Locked]
    public ?int $propertyId = null;

    public string $minimum = '0';

    public string $status = 'Tutti';

    #[Locked]
    public ?int $selectedId = null;

    /** @var array<string, string> */
    public array $form = ['state' => '', 'next_action' => '', 'visit_at' => '', 'rejection_reason' => '', 'feedback' => '', 'note' => ''];

    /** Anteprima della proposta già mostrata (il secondo invio conferma). */
    public bool $confirmed = false;

    public function mount(?int $requestId = null, ?int $propertyId = null): void
    {
        abort_unless(($requestId === null) !== ($propertyId === null), 404);
        if ($requestId !== null) {
            PropertyRequest::query()->visibleTo($this->actor())->findOrFail($requestId);
        } else {
            Property::query()->visibleTo($this->actor())->findOrFail($propertyId);
        }
        $this->requestId = $requestId;
        $this->propertyId = $propertyId;
    }

    private function visibleMatches()
    {
        $actor = $this->actor();

        return PropertyMatch::query()->where('agency_id', $actor->agency_id)
            ->when($this->requestId !== null, fn ($q) => $q->where('property_request_id', $this->requestId))
            ->when($this->propertyId !== null, fn ($q) => $q->where('property_id', $this->propertyId))
            ->whereHas('property', fn ($q) => $q->visibleTo($actor))
            ->whereHas('propertyRequest', fn ($q) => $q->visibleTo($actor))
            ->with(['property', 'propertyRequest.contact']);
    }

    #[Computed]
    public function matches()
    {
        $minimum = in_array($this->minimum, ['60', '80'], true) ? (int) $this->minimum : 0;

        return $this->visibleMatches()
            ->when($minimum > 0, fn ($q) => $q->where('score', '>=', $minimum))
            ->when($this->status !== 'Tutti', fn ($q) => $q->where('status', $this->status))
            ->orderByRaw('score DESC NULLS LAST')->orderByDesc('id')->paginate(10);
    }

    public function updatedMinimum(): void
    {
        if (! in_array($this->minimum, ['0', '60', '80'], true)) {
            $this->minimum = '0';
        }
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if ($this->status !== 'Tutti' && ! in_array($this->status, PropertyMatch::STATUSES, true)) {
            $this->status = 'Tutti';
        }
        $this->resetPage();
    }

    #[Computed]
    public function selected(): ?PropertyMatch
    {
        return $this->selectedId === null ? null : $this->visibleMatches()->find($this->selectedId);
    }

    #[Computed]
    public function editable(): bool
    {
        return $this->selected !== null && auth()->user()->can('update', $this->selected->propertyRequest);
    }

    public function openMatch(int $id): void
    {
        $match = $this->visibleMatches()->findOrFail($id);
        $this->selectedId = $match->id;
        $this->form = [
            'state' => $match->status,
            'next_action' => (string) $match->next_action,
            'visit_at' => Presenter::localDateTime($match->visit_at),
            'rejection_reason' => (string) $match->rejection_reason,
            'feedback' => (string) $match->feedback,
            'note' => (string) $match->note,
        ];
        $this->confirmed = false;
        $this->resetErrorBag();
        unset($this->selected, $this->editable);
    }

    public function closeMatch(): void
    {
        $this->selectedId = null;
        $this->confirmed = false;
        $this->resetErrorBag();
        unset($this->selected, $this->editable);
    }

    /** Cambiare stato o nota azzera la conferma dell'anteprima. */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['state', 'note'], true)) {
            $this->confirmed = false;
        }
    }

    public function save(): void
    {
        $match = $this->selected;
        abort_unless($match !== null, 404);
        $this->authorize('update', $match->propertyRequest);

        $proposal = $this->form['state'] === 'Proposto al cliente';
        if ($proposal && ! $this->confirmed) {
            $this->confirmed = true;

            return;
        }

        // Una data di visita invariata conserva l'istante originale.
        $visitAt = $this->form['visit_at'] === Presenter::localDateTime($match->visit_at) ? $match->visit_at : $this->form['visit_at'];
        $done = $this->command(fn () => app(UpdateMatchState::class)->handle($this->actor(), $match, [
            ...$this->form, 'visit_at' => $visitAt, 'confirmed' => $proposal && $this->confirmed,
        ]), $proposal ? 'Proposta registrata nella cronologia. Nessun messaggio inviato.' : 'Abbinamento e attività aggiornati');
        if ($done) {
            $this->closeMatch();
            unset($this->matches);
            $this->dispatch('activities-changed');
        }
    }

    /** Zona · prezzo(/mese) · m² · stato dell'immobile. */
    protected function propertyLine(Property $property): string
    {
        $features = $property->features ?? [];
        $price = $features['price'] ?? $property->asking_price;

        return implode(' · ', array_filter([
            $property->zone,
            Questionnaire::hasAnswer($price) ? AnswerPresenter::money(is_numeric($price) ? $price + 0 : $price).(($features['operation'] ?? null) === 'Locazione' ? '/mese' : '') : '',
            Questionnaire::hasAnswer($features['area'] ?? null) ? AnswerPresenter::answerLabel('area', $features['area']) : '',
            $property->status,
        ]));
    }
}; ?>

<div>
    <div class="crm-toolbar">
        <label class="crm-field"><span>Compatibilità minima</span><select wire:model.live="minimum"><option value="0">Tutti, anche da valutare</option><option value="60">Da 60 / 100</option><option value="80">Da 80 / 100</option></select></label>
        <label class="crm-field"><span>Stato abbinamento</span><select wire:model.live="status"><option value="Tutti">Tutti</option>@foreach (App\Models\PropertyMatch::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
        <span>{{ $this->matches->total() }} abbinamenti · ordinati per compatibilità</span>
    </div>

    <div class="crm-match-list">
        @foreach ($this->matches as $match)
            @php
                $property = $match->property;
                $request = $match->propertyRequest;
                $result = $match->result ?? [];
            @endphp
            <article class="crm-match-card" wire:key="match-{{ $match->id }}">
                <x-gestionale.crm-score :match="$match" />
                <div class="crm-grow">
                    <div class="crm-between">
                        @if ($requestId !== null)
                            <a class="crm-title-link" href="{{ route('gestionale.properties.show', $property) }}" wire:navigate>{{ $property->title }}<x-gestionale.lucide name="arrow-up-right" :size="15" /></a>
                        @else
                            <a class="crm-title-link" href="{{ route('gestionale.requests.show', $request) }}" wire:navigate>{{ $request->contact?->display_name }} · {{ $request->title }}<x-gestionale.lucide name="arrow-up-right" :size="15" /></a>
                        @endif
                        <x-gestionale.crm-pill>{{ $match->status }}</x-gestionale.crm-pill>
                    </div>
                    <p>{{ $this->propertyLine($property) }}</p>
                    <x-gestionale.crm-match-status :match="$match" />
                    @if (count($result['reasons'] ?? []) > 0)<p class="crm-muted">{{ AnswerPresenter::operatorReason(Presenter::primaryMatchReason($result)) }}@if (count($result['reasons']) > 1) · altri {{ count($result['reasons']) - 1 }} dettagli @endif</p>@endif
                    @if ($match->visit_at)<small>Visita: {{ Presenter::dateLabel($match->visit_at, true) }}</small>@endif
                </div>
                <button class="crm-btn secondary" type="button" wire:click="openMatch({{ $match->id }})">Apri confronto <x-gestionale.lucide name="chevron-down" :size="15" /></button>
            </article>
        @endforeach
    </div>
    @if ($this->matches->isEmpty())<div class="crm-empty">Nessun abbinamento con questi filtri. Abbassa la soglia o scegli tutti gli stati.</div>@endif
    {{ $this->matches->links() }}

    @if ($this->selected)
        @php
            $match = $this->selected;
            $property = $match->property;
            $request = $match->propertyRequest;
            $result = $match->result ?? [];
            $comparisons = collect($result['comparisons'] ?? []);
            $conditional = (bool) ($result['conditional'] ?? false);
            $proposal = $form['state'] === 'Proposto al cliente';
            $price = ($property->features ?? [])['price'] ?? $property->asking_price;
        @endphp
        <x-gestionale.crm-dialog title="Valuta l’abbinamento" wide :close="'$wire.closeMatch()'">
            <div class="crm-dialog-summary">
                <x-gestionale.crm-score :match="$match" />
                <div><h3>{{ $property->title }}</h3><p>{{ $request->contact?->display_name }} · {{ $request->title }}</p><x-gestionale.crm-match-status :match="$match" /><p>Attendibilità {{ mb_strtolower($result['confidence'] ?? 'indicativa') }} · versione {{ $result['version'] ?? 1 }}</p></div>
            </div>
            <p class="crm-info {{ $conditional ? 'warm' : '' }}"><x-gestionale.lucide name="shield-question-mark" :size="20" />Il punteggio riguarda solo i dati confrontabili: 100/100 non significa che tutti i requisiti siano verificati. Un requisito non rispettato è diverso da un dato mancante. Controlla entrambi prima di proporre l’immobile.</p>
            <div class="crm-match-evidence">
                @foreach ([['satisfied', 'Cosa corrisponde'], ['missing', 'Cosa non coincide'], ['unknown', 'Cosa manca da verificare']] as [$evidenceStatus, $heading])
                    @php
                        $rows = $comparisons->where('status', $evidenceStatus);
                    @endphp
                    <section><h3>{{ $heading }}</h3>
                        @if ($rows->isNotEmpty())
                            <ul>@foreach ($rows as $item)
                                <li><strong>{{ AnswerPresenter::questionLabel(['id' => $item['id'] ?? '', 'text' => $item['label'] ?? 'Criterio']) }}</strong> · {{ $item['classification'] ?? 'Preferibile' }}<br>
                                    Richiesta: {{ AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['expected'] ?? 'Da definire')) }}<br>
                                    Immobile: {{ AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['actual'] ?? 'Da definire')) }}
                                </li>
                            @endforeach</ul>
                        @else<p>Nessun criterio in questa categoria.</p>@endif
                    </section>
                @endforeach
            </div>
            <details class="crm-comparison" wire:ignore.self><summary>Perché questo punteggio?</summary>
                <div class="crm-table-wrap"><table><thead><tr><th>Criterio</th><th>Richiesta</th><th>Immobile</th><th>Esito</th></tr></thead><tbody>
                    @foreach ($comparisons as $item)
                        @php
                            $outcome = match ($item['status'] ?? 'unknown') {
                                'satisfied' => 'Corrisponde',
                                'unknown' => 'Da verificare',
                                default => ($item['classification'] ?? '') === 'Indispensabile' ? 'Requisito indispensabile non soddisfatto' : (($item['classification'] ?? '') === 'Da escludere' ? 'Elemento da escludere presente' : 'Non coincide'),
                            };
                        @endphp
                        <tr><td>{{ AnswerPresenter::questionLabel(['id' => $item['id'] ?? '', 'text' => $item['label'] ?? 'Criterio']) }}<small>{{ $item['classification'] ?? 'Preferibile' }}</small></td>
                            <td>{{ AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['expected'] ?? 'Da definire')) }}</td>
                            <td>{{ AnswerPresenter::comparisonAnswerLabel((string) ($item['id'] ?? ''), (string) ($item['actual'] ?? 'Da definire')) }}</td>
                            <td><x-gestionale.crm-pill :tone="($item['status'] ?? '') === 'satisfied' ? 'green' : 'warm'">{{ $outcome }}</x-gestionale.crm-pill></td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($comparisons->isEmpty())<p>Servono alcune preferenze per un primo confronto. Non è un punteggio zero.</p>@endif
            </details>
            @if ($this->editable)
                <form class="crm-form" wire:submit="save">
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Prossimo stato</span><select wire:model.live="form.state">@foreach (App\Models\PropertyMatch::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Prossima azione</span><input wire:model="form.next_action" maxlength="500"></label>
                    </div>
                    @if ($form['state'] === 'Visita programmata')
                        <label class="crm-field"><span>Data e ora della visita</span><input type="datetime-local" wire:model="form.visit_at" required></label>
                    @endif
                    @if (in_array($form['state'], ['Rifiutato', 'Non compatibile'], true))
                        <label class="crm-field"><span>Motivo del rifiuto</span><select wire:model="form.rejection_reason" required><option value="">Seleziona un motivo</option>@foreach (App\Models\PropertyMatch::REJECTION_REASONS as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach</select></label>
                    @endif
                    <label class="crm-field"><span>Feedback del cliente</span><textarea wire:model="form.feedback" maxlength="4000"></textarea></label>
                    <label class="crm-field"><span>{{ $proposal ? 'Nota della proposta' : 'Nota interna' }}</span><textarea wire:model.live.debounce.300ms="form.note" maxlength="4000"></textarea></label>
                    @if ($confirmed && $proposal)
                        <section class="crm-proposal-preview"><p class="proto-eyebrow">Anteprima da confermare · nessun invio</p><h3>Per {{ $request->contact?->display_name }}</h3>
                            <p>Abbiamo individuato un immobile da valutare insieme: {{ $property->title }}, {{ $property->zone }}, {{ AnswerPresenter::answerLabel('area', ($property->features ?? [])['area'] ?? null) }}, {{ AnswerPresenter::money(is_numeric($price) ? $price + 0 : $price) }}{{ (($property->features ?? [])['operation'] ?? null) === 'Locazione' ? '/mese' : '' }}.</p>
                            @if ($conditional)<p>Ci sono requisiti da approfondire con il tuo referente.</p>@endif
                            <p>{{ $form['note'] }}</p>
                            <p class="crm-muted">La conferma registra una sola attività collegata a cliente, richiesta e immobile. Email e messaggi reali sono disattivati.</p>
                        </section>
                    @endif
                    <div class="crm-form-footer">
                        @if ($errors->any())<p class="crm-error" role="alert">{{ $errors->first() }}</p>@endif
                        <button class="crm-btn" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">{{ $proposal ? ($confirmed ? 'Conferma registrazione proposta' : 'Mostra anteprima proposta') : ($form['state'] === 'Visita programmata' ? 'Conferma e crea appuntamento' : 'Salva stato e riscontro') }}</span><span wire:loading wire:target="save">Salvataggio…</span></button>
                    </div>
                </form>
            @endif
        </x-gestionale.crm-dialog>
    @endif
</div>
