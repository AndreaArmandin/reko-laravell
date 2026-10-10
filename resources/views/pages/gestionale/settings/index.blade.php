<?php

use App\Gestionale\Actions\Settings\SaveMatchingWeights;
use App\Gestionale\Actions\Settings\SaveMembership;
use App\Gestionale\Actions\Settings\SaveQuestionSet;
use App\Gestionale\AgencySettings;
use App\Gestionale\Audit;
use App\Gestionale\Livewire\HandlesCommands;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Impostazioni CRM (settings.tsx): Abbinamenti (pesi e configurazioni di partenza), Domande (questionario
 * configurabile e versioni), Utenti e ruoli (ruolo, accesso, permessi, pacchetto catalogo) e Registro operazioni.
 * Riservata al Responsabile: per gli altri ruoli la schermata lo spiega, e le azioni sono comunque rifiutate dal server.
 * "Pulizia esempi" non è portata: sono dati dimostrativi.
 */
new #[Layout('layouts::gestionale'), Title('Impostazioni CRM')] class extends Component {
    use HandlesCommands;

    public const TABS = ['Abbinamenti', 'Domande', 'Utenti e ruoli', 'Registro operazioni'];

    public const BUCKET_LABELS = ['zone' => 'Zona', 'budget' => 'Budget', 'type' => 'Tipologia e finalità', 'surface' => 'Superficie', 'rooms' => 'Locali / camere / bagni', 'required' => 'Requisiti indispensabili', 'preferred' => 'Preferenze', 'availability' => 'Disponibilità'];

    public const TYPE_LABELS = ['single' => 'Scelta singola', 'multi' => 'Scelta multipla', 'boolean' => 'Sì / No', 'range' => 'Intervallo', 'amount' => 'Importo / quantità', 'date' => 'Data', 'zone' => 'Punto e raggio', 'priority' => 'Priorità', 'text' => 'Testo libero', 'financing' => 'Mutuo: importo o percentuale'];

    public const OPERATOR_LABELS = ['equal' => 'Corrispondenza', 'includes' => 'Contiene', 'range' => 'Intervallo', 'maximum' => 'Massimo', 'minimum' => 'Minimo', 'date' => 'Data entro', 'distance' => 'Distanza entro'];

    public const PERMISSIONS = [
        'sister.import' => 'Importa da Sister',
        'activities.assign' => 'Assegna attività ad altri operatori',
        'activities.share' => 'Condivide attività con altri operatori',
        'exports' => 'Esporta i contatti',
    ];

    public string $tab = 'Abbinamenti';

    /** @var array<string, int|string> draft of the weights: choosing a preset never saves it */
    public array $weights = [];

    /** 'new', a question id, or null (dialog closed) */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $editingUser = null;

    /** @var array<string, mixed> */
    public array $userForm = [];

    public function mount(): void
    {
        if ($this->isAdmin) {
            $this->weights = $this->saved()['weights'];
        }
    }

    private function requireAdmin(): void
    {
        abort_unless($this->actor()->isAdmin(), 403);
    }

    /** @return array{weights: array<string, int>, version: int} */
    private function saved(): array
    {
        return AgencySettings::for($this->actor()->agency_id);
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return $this->actor()->role === 'admin';
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function questions(): array
    {
        $this->requireAdmin();

        return Questionnaire::forAgency((int) $this->actor()->agency_id)->questions();
    }

    #[Computed]
    public function version(): int
    {
        $this->requireAdmin();

        return $this->saved()['version'];
    }

    #[Computed]
    public function members()
    {
        $this->requireAdmin();

        return AgencyMembership::query()->where('agency_id', $this->actor()->agency_id)->with('user')->orderBy('id')->get();
    }

    #[Computed]
    public function audit()
    {
        $this->requireAdmin();

        return AuditEvent::query()->with('user')->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get();
    }

    public function showTab(string $tab): void
    {
        abort_unless($this->isAdmin && in_array($tab, self::TABS, true), 403);
        $this->tab = $tab;
        $this->resetErrorBag();
        // The access event is recorded without content (engine.ts audit.view).
        if ($tab === 'Registro operazioni') {
            app(Audit::class)->record('audit.view');
        }
    }

    // ---- Abbinamenti ----

    public function applyPreset(string $name): void
    {
        abort_unless($this->isAdmin && isset(AgencySettings::PRESETS[$name]), 403);
        $this->weights = AgencySettings::PRESETS[$name];
    }

    public function restoreWeights(): void
    {
        $this->requireAdmin();
        $this->weights = $this->saved()['weights'];
    }

    public function saveWeights(): void
    {
        $this->requireAdmin();
        $this->command(fn () => app(SaveMatchingWeights::class)->handle($this->actor(), $this->weights), 'Pesi salvati. Tutti gli abbinamenti sono stati ricalcolati.');
        unset($this->version);
        $this->weights = $this->saved()['weights'];
    }

    // ---- Domande ----

    public function toggleQuestion(string $id): void
    {
        $this->requireAdmin();
        $questions = array_map(fn ($q) => $q['id'] === $id ? [...$q, 'active' => ! ($q['active'] ?? true)] : $q, $this->questions);
        $this->command(fn () => app(SaveQuestionSet::class)->handle($this->actor(), $questions));
        unset($this->questions, $this->version);
    }

    public function newQuestion(): void
    {
        $this->requireAdmin();
        $this->resetErrorBag();
        $this->editing = 'new';
        $this->form = $this->formFor(null);
    }

    public function editQuestion(string $id): void
    {
        $this->requireAdmin();
        $question = collect($this->questions)->firstWhere('id', $id);
        abort_if($question === null, 404);
        $this->resetErrorBag();
        $this->editing = $id;
        $this->form = $this->formFor($question);
    }

    public function closeQuestion(): void
    {
        $this->requireAdmin();
        $this->editing = null;
        $this->resetErrorBag();
    }

    /** @param array<string, mixed>|null $q */
    private function formFor(?array $q): array
    {
        return [
            'text' => $q['text'] ?? '',
            'explanation' => $q['explanation'] ?? '',
            'type' => $q['type'] ?? 'text',
            'collection' => $q['collection'] ?? 'manual',
            'path' => ($q['paths'] ?? 'all') === 'all' ? 'all' : ($q['paths'][0] ?? 'all'),
            'order' => $q['order'] ?? count($this->questions) + 1,
            'weight' => $q['weight'] ?? 1,
            // A new question starts on the first option of the select, as in the old form.
            'classification' => $q['classification'] ?? Questionnaire::CLASSIFICATIONS[0],
            'field' => $q['field'] ?? '',
            'bucket' => $q['bucket'] ?? 'preferred',
            'operator' => $q['operator'] ?? 'equal',
            'options' => implode("\n", $q['options'] ?? []),
            'conditionField' => $q['condition']['field'] ?? '',
            'conditionValues' => implode('|', array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $q['condition']['values'] ?? [])),
            'skippable' => $q['skippable'] ?? true,
            'active' => $q['active'] ?? true,
        ];
    }

    public function saveQuestion(): void
    {
        $this->requireAdmin();
        $f = $this->form;
        $existing = $this->editing === 'new' ? null : collect($this->questions)->firstWhere('id', $this->editing);
        $question = array_merge($existing ?? [], [
            'id' => $existing['id'] ?? 'custom-'.Str::uuid(),
            'text' => (string) ($f['text'] ?? ''),
            'explanation' => (string) ($f['explanation'] ?? ''),
            'type' => $f['type'] ?? null,
            'paths' => ($f['path'] ?? 'all') === 'all' ? 'all' : [$f['path']],
            'order' => is_numeric($f['order'] ?? null) ? $f['order'] + 0 : null,
            'options' => array_values(array_filter(array_map('trim', explode("\n", (string) ($f['options'] ?? ''))), fn ($v) => $v !== '')),
            'skippable' => (bool) ($f['skippable'] ?? false),
            'active' => (bool) ($f['active'] ?? false),
            'collection' => $f['collection'] ?? null,
            'bucket' => $f['bucket'] ?? null,
            'operator' => $f['operator'] ?? null,
            'weight' => is_numeric($f['weight'] ?? null) ? $f['weight'] + 0 : null,
            'classification' => $f['classification'] ?? null,
        ]);
        unset($question['field'], $question['condition']);
        if (empty($existing['sensitive']) && ($f['field'] ?? '') !== '') {
            $question['field'] = $f['field'];
        }
        if (($f['conditionField'] ?? '') !== '') {
            $question['condition'] = ['field' => $f['conditionField'], 'values' => array_map(fn ($v) => match (trim($v)) { 'true' => true, 'false' => false, default => trim($v) }, explode('|', (string) ($f['conditionValues'] ?? '')))];
        }
        $questions = $existing === null
            ? [...$this->questions, $question]
            : array_map(fn ($q) => $q['id'] === $question['id'] ? $question : $q, $this->questions);

        if ($this->command(fn () => app(SaveQuestionSet::class)->handle($this->actor(), $questions)) !== null) {
            unset($this->questions, $this->version);
            $this->editing = null;
        }
    }

    // ---- Utenti e ruoli ----

    public function editUser(int $id): void
    {
        $this->requireAdmin();
        $member = $this->members->firstWhere('id', $id);
        abort_if($member === null, 404);
        $package = $member->catalog_package ?? [];
        $this->resetErrorBag();
        $this->editingUser = $id;
        $this->userForm = [
            'role' => $member->role,
            'active' => $member->isActive(),
            'permissions' => array_values(array_intersect(array_keys(self::PERMISSIONS), $member->permissions ?? AgencyMembership::DEFAULT_PERMISSIONS)),
            'group' => $member->group_name ?? '',
            'branch' => $member->branch_name ?? '',
            'packageName' => $package['name'] ?? '',
            'packageUnlimited' => ($package['unlimited'] ?? false) === true,
            'packageLimit' => $package['maxParcels'] ?? 0,
            'packageEnabled' => ($package['enabled'] ?? false) === true,
        ];
    }

    public function closeUser(): void
    {
        $this->requireAdmin();
        $this->editingUser = null;
        $this->resetErrorBag();
    }

    public function saveUser(): void
    {
        $this->requireAdmin();
        $target = $this->members->firstWhere('id', $this->editingUser);
        abort_if($target === null, 404);
        $f = $this->userForm;
        $unlimited = (bool) ($f['packageUnlimited'] ?? false);
        // A saved owner.edit grant is kept: the form only offers the permissions that can be given.
        $permissions = array_values(array_unique([...array_intersect(array_keys(self::PERMISSIONS), (array) ($f['permissions'] ?? [])), ...array_intersect(['owner.edit'], $target->permissions ?? [])]));

        $saved = $this->command(fn () => app(SaveMembership::class)->handle($this->actor(), $target, [
            'role' => $f['role'] ?? null,
            'active' => (bool) ($f['active'] ?? false),
            'group' => $f['group'] ?? '',
            'branch' => $f['branch'] ?? '',
            'permissions' => $permissions,
            'catalogPackage' => [
                'name' => (string) ($f['packageName'] ?? ''),
                'enabled' => (bool) ($f['packageEnabled'] ?? false),
                'maxParcels' => $unlimited ? 0 : (int) ($f['packageLimit'] ?? 0),
                'unlimited' => $unlimited,
            ],
        ]), 'Profilo operatore aggiornato');
        if ($saved !== null) {
            unset($this->members);
            $this->editingUser = null;
        }
    }
}; ?>

<div>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Impostazioni CRM</h1>
            @if ($this->isAdmin)<p class="crm-muted">Configura il lavoro dell’agenzia. Ogni modifica al matching aggiorna gli abbinamenti esistenti.</p>@endif
        </div>
    </div>

    @if (! $this->isAdmin)
        <section class="crm-panel"><x-gestionale.lucide name="shield-check" /><h2>Configurazione riservata al Responsabile</h2><p>Il controllo è applicato anche alle operazioni sul server. Puoi continuare a lavorare sui clienti e immobili assegnati.</p></section>
    @else
        <nav class="crm-tabs" aria-label="Impostazioni">
            @foreach ($this::TABS as $value)
                <button type="button" wire:key="tab-{{ $value }}" wire:click="showTab('{{ $value }}')" @if ($tab === $value) aria-current="page" @endif>{{ $value }}</button>
            @endforeach
        </nav>

        @if ($tab === 'Abbinamenti')
            @php($total = array_sum(array_map(fn ($v) => is_numeric($v) ? $v + 0 : 0, $weights)))
            <section class="crm-panel">
                <div class="crm-section-head"><h2>Pesi di compatibilità</h2><x-gestionale.activity-pill>Versione {{ $this->version }}</x-gestionale.activity-pill></div>
                <p class="crm-muted">Si normalizzano solo i gruppi con criteri effettivamente confrontabili. Le risposte sconosciute non valgono zero; riducono l’attendibilità.</p>
                <form class="crm-form" wire:submit="saveWeights" wire:confirm="Salvare questa configurazione? Gli abbinamenti saranno ricalcolati con i pesi mostrati.">
                    <fieldset class="crm-fieldset">
                        <legend>Configurazioni di partenza</legend>
                        <div class="crm-actions">
                            @foreach (\App\Gestionale\AgencySettings::PRESETS as $name => $preset)
                                <button type="button" class="crm-btn secondary" wire:click="applyPreset('{{ $name }}')">{{ $name }}</button>
                            @endforeach
                            <button type="button" class="crm-link" wire:click="restoreWeights">Ripristina pesi salvati</button>
                        </div>
                        <p class="crm-muted">Le scelte preparano una bozza. I punteggi attuali restano invariati finché non confermi “Salva pesi e ricalcola”.</p>
                    </fieldset>
                    <div class="crm-weight-grid">
                        @foreach ($this::BUCKET_LABELS as $key => $label)
                            <label class="crm-field" wire:key="weight-{{ $key }}"><span>{{ $label }}</span><input type="number" min="0" max="100" required wire:model.live.debounce.250ms="weights.{{ $key }}"></label>
                        @endforeach
                    </div>
                    <p class="{{ $total == 100 ? 'crm-success' : 'crm-error' }}">Totale: {{ $total }} / 100</p>
                    <div class="crm-form-footer">
                        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                        <button class="crm-btn" wire:loading.attr="disabled" wire:target="saveWeights">Salva pesi e ricalcola</button>
                    </div>
                </form>
            </section>
        @endif

        @if ($tab === 'Domande')
            <section class="crm-panel">
                <div class="crm-section-head">
                    <div>
                        <h2>Questionario configurabile</h2>
                        <p class="crm-muted">{{ collect($this->questions)->filter(fn ($q) => ! empty($q['field']) && empty($q['sensitive']))->count() }} domande collegate a caratteristiche dell’immobile, su {{ count($this->questions) }}.</p>
                    </div>
                    <button type="button" class="crm-btn" wire:click="newQuestion"><x-gestionale.lucide name="plus" :size="16" />Nuova domanda</button>
                </div>
                <div class="crm-question-config-list">
                    @foreach (collect($this->questions)->sortBy('order')->values() as $q)
                        <div class="crm-record-row" wire:key="question-{{ $q['id'] }}">
                            <span class="crm-avatar">{{ ($q['collection'] ?? '') === 'manual' ? 'M' : (($q['collection'] ?? '') === 'archived' ? '—' : $q['order']) }}</span>
                            <div class="crm-grow">
                                <strong>{{ $q['text'] }}</strong>
                                <small>{{ ($q['paths'] ?? 'all') === 'all' ? 'Tutti i percorsi' : implode(', ', array_map(fn ($p) => \App\Gestionale\Questionnaire\Questionnaire::PATH_LABELS[$p] ?? $p, $q['paths'])) }} · {{ ! empty($q['field']) && empty($q['sensitive']) ? 'Campo: '.$q['field'] : 'Solo pratica' }} · {{ ($q['collection'] ?? '') === 'manual' ? 'Specifica manuale' : (($q['collection'] ?? '') === 'archived' ? 'Solo storico' : 'Percorso rapido') }} · {{ ($q['skippable'] ?? true) ? 'Facoltativa' : 'Obbligatoria' }}</small>
                            </div>
                            <button type="button" class="crm-link" wire:click="toggleQuestion('{{ $q['id'] }}')">{{ ($q['active'] ?? true) ? 'Disattiva' : 'Attiva' }}</button>
                            <button type="button" class="crm-icon-button" aria-label="Modifica domanda: {{ $q['text'] }}" wire:click="editQuestion('{{ $q['id'] }}')"><x-gestionale.lucide name="pencil" :size="16" /></button>
                        </div>
                    @endforeach
                </div>
                @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
            </section>
        @endif

        @if ($tab === 'Utenti e ruoli')
            <section class="crm-panel">
                <div class="crm-section-head"><h2>Referenti della demo</h2></div>
                <p class="crm-info">“Vedi come…” mostra un’anteprima in sola lettura, senza cambiare l’accesso. Responsabile, Agente acquisizioni e Segreteria hanno sezioni, azioni e dati distinti. I controlli sono applicati anche sul server.</p>
                @foreach ($this->members as $member)
                    <button type="button" class="crm-record-row" wire:key="member-{{ $member->id }}" wire:click="editUser({{ $member->id }})">
                        <span class="crm-grow"><strong>{{ $member->user->name }}</strong><small>{{ \App\Gestionale\WorkProfile::label($member->role) }} · {{ $member->isActive() ? 'Attivo' : 'Disattivato' }}</small></span>
                        <x-gestionale.lucide name="pencil" :size="17" />
                    </button>
                @endforeach
            </section>
        @endif

        @if ($tab === 'Registro operazioni')
            @php($names = $this->members->mapWithKeys(fn ($m) => [$m->id => $m->user->name]))
            <section class="crm-panel">
                <h2>Traccia delle modifiche</h2>
                <p class="crm-muted">Il registro riservato all’Amministratore conserva autore, motivo e valori precedenti delle correzioni.</p>
                <div class="crm-table-wrap">
                    <table>
                        <thead><tr><th>Data</th><th>Autore</th><th>Operazione</th><th>Riferimento</th></tr></thead>
                        <tbody>
                            @foreach ($this->audit as $event)
                                <tr wire:key="audit-{{ $event->id }}">
                                    <td>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($event->occurred_at, true) }}</td>
                                    <td>{{ $event->user?->name }}</td>
                                    <td>{{ \App\Gestionale\OperationLabels::label($event->action) }}<details><summary>Riferimento tecnico</summary><code>{{ $event->action }}</code></details></td>
                                    <td>
                                        {{ $event->auditable_type === 'agency_membership' ? ($names[$event->auditable_id] ?? $event->auditable_id) : ($event->auditable_id && $event->auditable_type !== \App\Models\GestionaleSetting::class ? $event->auditable_id : 'Configurazione') }}
                                        @if ($event->reason)<p>{{ $event->reason }}</p>@endif
                                        @if (is_array($event->payload) && (array_key_exists('before', $event->payload) || array_key_exists('after', $event->payload)))
                                            <details><summary>Prima e dopo la correzione</summary><pre class="crm-audit-values">{{ json_encode(['prima' => $event->payload['before'] ?? null, 'dopo' => $event->payload['after'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($this->audit->isEmpty())<div class="crm-empty">Le prime operazioni compariranno qui.</div>@endif
            </section>
        @endif

        @if ($editing !== null)
            @php($fields = collect($this->questions)->filter(fn ($q) => empty($q['sensitive']) && ! empty($q['field']))->pluck('field')->unique()->values())
            @php($current = $editing === 'new' ? null : collect($this->questions)->firstWhere('id', $editing))
            <dialog class="proto-dialog reko-prototype proto-dialog-wide" aria-labelledby="question-dialog-title" wire:key="question-dialog" x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeQuestion()" x-on:click="if ($event.target === $el) $wire.closeQuestion()">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="question-dialog-title">{{ $editing === 'new' ? 'Nuova domanda' : 'Configura domanda' }}</h2></div>
                    <button type="button" class="crm-icon-button" aria-label="Chiudi" wire:click="closeQuestion"><x-gestionale.lucide name="x" /></button>
                </div>
                <form class="crm-form" wire:submit="saveQuestion">
                    <label class="crm-field"><span>Testo domanda</span><input required wire:model="form.text"></label>
                    <label class="crm-field"><span>Spiegazione breve</span><textarea wire:model="form.explanation"></textarea></label>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Tipo risposta</span><select wire:model="form.type">@foreach ($this::TYPE_LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Dove raccogliere il dato</span><select wire:model="form.collection"><option value="guided">Percorso rapido</option><option value="manual">Solo su scelta del referente</option><option value="archived">Storico · non richiedere più</option></select></label>
                        <label class="crm-field"><span>Percorso</span><select wire:model="form.path"><option value="all">Tutti</option>@foreach (\App\Gestionale\Questionnaire\Questionnaire::PATH_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Ordine</span><input type="number" min="0" required wire:model="form.order"></label>
                        <label class="crm-field"><span>Importanza del criterio</span><input type="number" min="0.1" step="0.1" required wire:model="form.weight"></label>
                        <label class="crm-field"><span>Classificazione iniziale</span><select wire:model="form.classification">@foreach (\App\Gestionale\Questionnaire\Questionnaire::CLASSIFICATIONS as $value)<option>{{ $value }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Campo immobile confrontato</span><select wire:model="form.field" @disabled(! empty($current['sensitive']))><option value="">Solo pratica · nessun punteggio</option>@foreach ($fields as $value)<option>{{ $value }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Gruppo di punteggio</span><select wire:model="form.bucket">@foreach ($this::BUCKET_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>Confronto</span><select wire:model="form.operator">@foreach ($this::OPERATOR_LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    </div>
                    <label class="crm-field"><span>Opzioni (una per riga)</span><textarea wire:model="form.options"></textarea></label>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Mostra solo se questa domanda…</span><select wire:model="form.conditionField"><option value="">Nessuna condizione</option>@foreach (collect($this->questions)->filter(fn ($q) => $q['id'] !== ($current['id'] ?? null)) as $q)<option value="{{ $q['id'] }}">{{ $q['text'] }}</option>@endforeach</select></label>
                        <label class="crm-field"><span>…ha una di queste risposte (separate da |)</span><input wire:model="form.conditionValues" placeholder="Acquisto | Locazione · true / false"></label>
                    </div>
                    <label class="crm-check"><input type="checkbox" wire:model="form.skippable">Domanda facoltativa / saltabile</label>
                    <label class="crm-check"><input type="checkbox" wire:model="form.active">Domanda attiva</label>
                    @if (! empty($current['sensitive']))<p class="crm-info">Questo dato resta escluso dal matching, anche dopo la modifica.</p>@endif
                    <div class="crm-form-footer">
                        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                        <button class="crm-btn" wire:loading.attr="disabled" wire:target="saveQuestion">Salva domanda e ricalcola</button>
                    </div>
                </form>
            </dialog>
        @endif

        @if ($editingUser !== null)
            @php($target = $this->members->firstWhere('id', $editingUser))
            <dialog class="proto-dialog reko-prototype" aria-labelledby="user-dialog-title" wire:key="user-dialog" x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeUser()" x-on:click="if ($event.target === $el) $wire.closeUser()">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="user-dialog-title">Referente demo</h2></div>
                    <button type="button" class="crm-icon-button" aria-label="Chiudi" wire:click="closeUser"><x-gestionale.lucide name="x" /></button>
                </div>
                <form class="crm-form" wire:submit="saveUser">
                    <label class="crm-field"><span>Nome dimostrativo</span><input value="{{ $target?->user->name }}" disabled></label>
                    <label class="crm-field"><span>Ruolo</span><select wire:model="userForm.role">@foreach (['admin', 'scout', 'crm'] as $role)<option value="{{ $role }}">{{ \App\Gestionale\WorkProfile::label($role) }}</option>@endforeach</select></label>
                    <label class="crm-check"><input type="checkbox" wire:model="userForm.active">Attivo</label>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Gruppo</span><input maxlength="100" wire:model="userForm.group"></label>
                        <label class="crm-field"><span>Filiale</span><input maxlength="100" wire:model="userForm.branch"></label>
                    </div>
                    @foreach ($this::PERMISSIONS as $key => $label)
                        <label class="crm-check"><input type="checkbox" value="{{ $key }}" wire:model="userForm.permissions">{{ $label }}</label>
                    @endforeach
                    <small>Permessi aggiuntivi assegnati a questo utente. La revoca blocca subito menu e API. Il caricamento dei fogli catastali resta riservato all’Amministratore.</small>
                    <fieldset class="crm-fieldset">
                        <legend>Acquisizione dal catalogo · pacchetto</legend>
                        <p>Nessun pacchetto viene attivato automaticamente. Inserisci le condizioni concordate prima di abilitarlo.</p>
                        <label class="crm-field"><span>Nome pacchetto</span><input maxlength="100" wire:model="userForm.packageName"></label>
                        <label class="crm-check"><input type="checkbox" wire:model.live="userForm.packageUnlimited">Senza limite complessivo</label>
                        @if (empty($userForm['packageUnlimited']))
                            <label class="crm-field"><span>Limite particelle</span><input type="number" min="0" step="1" wire:model="userForm.packageLimit"></label>
                        @endif
                        <label class="crm-check"><input type="checkbox" wire:model="userForm.packageEnabled">Abilita acquisizione dal catalogo</label>
                    </fieldset>
                    <div class="crm-form-footer">
                        @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                        <button class="crm-btn" wire:loading.attr="disabled" wire:target="saveUser">Salva referente</button>
                    </div>
                </form>
            </dialog>
        @endif
    @endif
</div>
