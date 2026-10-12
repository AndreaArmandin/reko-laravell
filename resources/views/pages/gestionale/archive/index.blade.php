<?php

use App\Gestionale\Actions\Census\RemoveCensusUnits;
use App\Gestionale\Actions\Census\RemoveCensusOwnership;
use App\Gestionale\Actions\Census\RecordCensusOutcome;
use App\Gestionale\Actions\Census\SaveCensusProposal;
use App\Gestionale\Actions\Census\SaveCadastral;
use App\Gestionale\Actions\Census\SaveOwner;
use App\Gestionale\Actions\Census\SaveOwnerContact;
use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityContext;
use App\Gestionale\Census\CensusQuery;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\Census\TerrainParcelReader;
use App\Gestionale\Census\CensusImporter;
use App\Gestionale\Census\SisterText;
use App\Gestionale\CommandRejected;
use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Activity;
use App\Models\Municipality;
use Illuminate\Http\Request;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Archivio catastale: filtri principali dell'originale (Comune obbligatorio, riferimenti,
 * frazione, indirizzo, proprietario), risultati paginati e schede del censimento agenzia.
 * Le informazioni del catalogo REKO restano sulla Mappa e zone e non diventano censimento da sole.
 */
new #[Layout('layouts::gestionale'), Title('Archivio catastale')] class extends Component {
    use WithFileUploads;

    public string $municipalityCode = '';
    public string $municipalitySearch = '';
    public bool $municipalityPickerOpen = false;
    public int $municipalityOptionIndex = 0;
    #[Url(as: 'vista')]
    public string $view = 'Immobili';
    public string $mode = 'indirizzo';
    public string $search = '';
    public string $outcome = '';
    public string $sheet = '';
    public string $parcel = '';
    public string $sub = '';
    public bool $withoutSub = false;
    /** Collegamento diretto (?parcel=, ?unit=, ?owner=): mostra solo quella particella, unità o proprietario. */
    public ?int $focusParcelId = null;
    public ?int $focusUnitId = null;
    public ?int $focusOwnerId = null;
    public string $locality = '';
    public string $address = '';
    public string $civic = '';
    public string $floor = '';
    public string $firstName = '';
    public string $lastName = '';
    public string $taxCode = '';
    public array $filterValues = [];
    public string $order = 'Da contattare';
    public string $firstOutcome = 'Interessato';
    public ?array $after = null;

    /** @var list<?array<string, mixed>> */
    public array $cursorStack = [];

    public int $page = 1;
    /** @var list<int> */
    public array $selectedUnitIds = [];
    public bool $bulkRemovalOpen = false;
    public string $bulkRemovalReason = '';
    public string $notice = '';
    public ?int $openOwnerId = null;
    public int $ownerUnitsPage = 1;
    public ?int $historyOwnerId = null;
    public ?int $historyUnitId = null;
    public string $historyOutcomeFilter = '';
    public string $historyTypeFilter = '';
    public string $historyAuthorFilter = '';
    public string $historyFrom = '';
    public string $historyTo = '';
    public ?int $contactId = null;

    public ?int $contactContextUnitId = null;
    public string $contactDraft = '';
    public bool $confirmRepeatedContact = false;
    public bool $proposalOpen = false;
    public ?int $proposalUnitId = null;
    public ?int $proposalOwnerId = null;
    public string $proposalName = '';
    public string $proposalPid = '';
    public string $proposalNote = '';
    public bool $proposalNewOwner = false;

    // census.update: riscontro del contatto con il proprietario (census-controls.tsx)
    public bool $outcomeOpen = false;
    public ?int $outcomeUnitId = null;
    public ?int $outcomeOwnerId = null;
    public string $outcomeOperation = '';
    public string $outcomeValue = '';
    public string $outcomeNote = '';
    public ?int $outcomeExistingId = null;
    public bool $outcomeConfirmNew = false;
    public bool $outcomeSchedule = false;
    public string $outcomeNextAt = '';
    public string $outcomeNextReason = '';
    public string $outcomeToken = '';
    public bool $censusRemovalOpen = false;
    public string $censusRemovalMode = '';
    public ?int $censusRemovalOwnerId = null;
    public string $censusRemovalReason = '';
    public bool $censusRemovalConfirmed = false;
    public ?int $editingOwnerId = null;
    public bool $ownerEditorOpen = false;
    public string $ownerEditName = '';
    public string $ownerEditPid = '';
    public string $ownerEditFirstName = '';
    public string $ownerEditLastName = '';
    public string $ownerEditCompanyName = '';
    public string $ownerEditNotes = '';
    /** @var list<string> */
    public array $ownerEditTags = [];
    public string $ownerEditReason = '';
    public ?string $ownerEditRevision = null;
    public ?int $cadastralUnitId = null;
    public bool $cadastralEditorOpen = false;
    public string $cadastralAddress = '';
    public string $cadastralCategory = '';
    public string $cadastralFloor = '';
    public string $cadastralZone = '';
    public string $cadastralClass = '';
    public string $cadastralConsistency = '';
    public string $cadastralIncome = '';
    public string $cadastralLastVerified = '';
    public string $cadastralReason = '';
    public ?string $cadastralRevision = null;

    public bool $sisterImportOpen = false;
    public string $sisterEstatesText = '';
    public string $sisterOwnersText = '';
    public string $sisterProvince = '';
    public string $sisterMunicipality = '';
    public string $sisterCode = '';
    public string $sisterKind = 'Fabbricati';
    public string $sisterSection = '';
    public string $sisterSituationDate = '';
    public string $sisterTargetUnitId = '';
    public mixed $sisterEstateFile = null;
    public mixed $sisterOwnerFile = null;
    public ?array $sisterPreview = null;
    public ?array $sisterResult = null;
    public array $sisterOwnerDecisions = [];
    public array $sisterConfirmNameUpdates = [];
    public bool $sisterSkipInvalid = false;
    public string $sisterToken = '';
    public string $sisterError = '';
    /** Unità prefissata dalla scheda (immobile collegato) oppure scelta dall'elenco «Unità a cui collegare i proprietari». */
    public bool $sisterTargetLocked = false;
    public string $sisterTargetQuery = '';
    public bool $sisterPickerOpen = false;
    public int $sisterOptionIndex = 0;
    /** @var list<int> unità con i comproprietari espansi («Mostra altri N») */
    public array $expandedUnits = [];

    public function mount(Request $request): void
    {
        $this->guard();
        $requestedView = (string) $request->query('vista', 'Immobili');
        if (in_array($requestedView, ['Immobili', 'Proprietari'], true)) {
            $this->view = $requestedView;
        }
        $requestedCode = strtoupper((string) $request->query('municipalityCode', ''));
        $requestedMunicipality = collect($this->municipalities)->firstWhere('code', $requestedCode);
        if (preg_match('/^[A-Z]\d{3}$/', $requestedCode) && $requestedMunicipality !== null) {
            $this->municipalityCode = $requestedCode;
            $this->municipalitySearch = $requestedMunicipality['name'];
        }
        $this->openFocus(
            $this->positiveInt($request->query('parcel')),
            $this->positiveInt($request->query('unit')),
            $this->positiveInt($request->query('owner')),
        );
        if ($request->boolean('importSister') && $this->membership->allows('sister.import')) {
            $this->openSisterImport();
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * scouting-area.tsx: an id in the link selects one parcel, one unit of it or one owner, whatever the
     * municipality. Only what the role can reach is shown: an unreachable id is ignored.
     */
    private function openFocus(?int $parcelId, ?int $unitId, ?int $ownerId): void
    {
        if ($parcelId === null && $unitId === null && $ownerId === null) {
            return;
        }
        $entry = (new CensusReader($this->membership))->entries(array_filter(['parcelId' => $parcelId, 'unitId' => $unitId, 'ownerId' => $ownerId]))->first();
        $terrain = $entry === null && $parcelId !== null && CensusScope::parcelVisible($this->membership, $parcelId)
            ? (TerrainParcelReader::archiveRows($this->membership, null, $parcelId)[0] ?? null) : null;
        if ($entry === null && $terrain === null) {
            return;
        }
        $this->focusParcelId = $parcelId;
        $this->focusUnitId = $unitId;
        $this->focusOwnerId = $ownerId;
        $this->view = $ownerId !== null ? 'Proprietari' : 'Immobili';
        $code = (string) ($entry['context']['code'] ?? $terrain['context']['code'] ?? '');
        $municipality = collect($this->municipalities)->firstWhere('code', $code);
        if ($municipality !== null) {
            $this->municipalityCode = $code;
            $this->municipalitySearch = $municipality['name'];
        }
    }

    private function hasFocus(): bool
    {
        return $this->focusParcelId !== null || $this->focusUnitId !== null || $this->focusOwnerId !== null;
    }

    /** “Torna all’elenco completo”. */
    public function clearFocus(): void
    {
        $this->focusParcelId = $this->focusUnitId = $this->focusOwnerId = null;
        $this->page = 1;
        $this->ownerUnitsPage = 1;
        unset($this->results, $this->ownerUnits);
    }


    public function openSisterImport(?int $targetUnitId = null): void
    {
        abort_unless($this->membership->allows('sister.import') || ($targetUnitId !== null && CensusImporter::scoutMayReplaceOwners($this->membership, $targetUnitId)), 403);
        $this->resetSisterImport();
        if ($targetUnitId !== null) {
            abort_unless(CensusScope::unitVisible($this->membership, $targetUnitId), 403);
            $entry = (new CensusReader($this->membership))->entries(['unitId' => $targetUnitId])->first() ?? abort(404);
            $this->sisterTargetUnitId = (string) $targetUnitId;
            $this->sisterTargetLocked = true;
            $this->sisterProvince = $entry['context']['province'];
            $this->sisterMunicipality = $entry['context']['municipality'];
            $this->sisterCode = $entry['context']['code'];
            $this->sisterKind = $entry['context']['kind'];
            $this->sisterSection = $entry['context']['section'];
            $this->sisterSituationDate = $entry['unit']['situationDate'] ?: $entry['context']['situationDate'];
        }
        $municipality = collect($this->municipalities)->firstWhere('code', $this->municipalityCode);
        if (! $municipality && $this->municipalityCode !== '') {
            $record = Municipality::query()->with('province')->where('cadastral_code', $this->municipalityCode)->first();
            if ($record) {
                $municipality = ['province' => $record->province?->abbreviation ?? '', 'name' => $record->name, 'code' => $record->cadastral_code];
            }
        }
        if ($municipality) {
            $this->sisterProvince = $municipality['province'];
            $this->sisterMunicipality = $municipality['name'];
            $this->sisterCode = $municipality['code'];
        }
        $this->sisterImportOpen = true;
    }

    public function closeSisterImport(): void
    {
        $this->sisterImportOpen = false;
        $this->sisterEstateFile = null;
        $this->sisterOwnerFile = null;
    }

    /** Permesso “Importa da Sister”, oppure scout sull'unità di una particella assegnata (census.owners.replace). */
    private function canSisterImport(): bool
    {
        return $this->membership->allows('sister.import')
            || ($this->sisterTargetUnitId !== '' && CensusImporter::scoutMayReplaceOwners($this->membership, (int) $this->sisterTargetUnitId));
    }

    public function openImportedSisterResults(): void
    {
        abort_unless($this->canSisterImport(), 403);
        if (! $this->sisterResult) {
            return;
        }

        $unitId = (int) ($this->sisterResult['unitIds'][0] ?? 0);
        if ($unitId === 0) {
            $unitId = (int) $this->sisterTargetUnitId;
        }
        $entry = $unitId > 0 ? (new CensusReader($this->membership))->entries(['unitId' => $unitId])->first() : null;
        $this->closeSisterImport();
        $this->view = 'Immobili';
        $this->municipalityCode = $this->sisterCode;
        $this->mode = $entry ? 'catastale' : 'indirizzo';
        $this->search = '';
        $this->outcome = '';
        $this->filterValues = [];
        $this->selectedUnitIds = [];
        $this->page = 1;
        $this->ownerUnitsPage = 1;
        if ($entry) {
            $this->sheet = $entry['context']['sheet'];
            $this->parcel = $entry['context']['parcel'];
            $this->sub = $entry['unit']['sub'];
            $this->withoutSub = $this->sub === '';
        } else {
            $this->sheet = '';
            $this->parcel = '';
            $this->sub = '';
            $this->withoutSub = false;
        }
        $this->locality = $this->address = $this->civic = $this->floor = '';
        $this->firstName = $this->lastName = $this->taxCode = '';
        $this->order = 'Da contattare';
        $this->firstOutcome = 'Interessato';
        unset($this->municipalities, $this->results, $this->ownerUnits);
    }

    public function updatedSisterEstatesText(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterOwnersText(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterMunicipality(): void
    {
        // Come nell'originale: digitando si scarta il Comune scelto; provincia e codice seguono solo la selezione.
        $this->invalidateSisterPreview();
        if ($this->sisterCode !== '') {
            $this->sisterProvince = $this->sisterCode = '';
        }
        if (! $this->sisterTargetLocked) {
            $this->sisterTargetUnitId = '';
        }
        $this->sisterPickerOpen = true;
        $this->sisterOptionIndex = 0;
        unset($this->sisterMunicipalityOptions, $this->sisterTargetUnits);
    }

    /** Caricato il file, il testo entra subito nel campo (come l'originale, che lo legge al cambio del file). */
    public function updatedSisterEstateFile(): void { $this->loadSisterTextFile('estates'); }
    public function updatedSisterOwnerFile(): void { $this->loadSisterTextFile('owners'); }

    /** @return list<array{name:string,province:string,code:string}> Comuni italiani (MunicipalityAutocomplete senza elenco proprio). */
    #[Computed]
    public function sisterMunicipalityOptions(): array
    {
        abort_unless($this->canSisterImport(), 403);
        $term = $this->normalizeMunicipalitySearch($this->sisterMunicipality);
        $query = Municipality::query()->with('province')->whereNotNull('cadastral_code');
        if ($term !== '') {
            $query->where(fn ($q) => $q->whereRaw("translate(lower(name), 'àáâèéêìíîòóôùúû', 'aaaeeeiiiooouuu') like ?", ['%'.str_replace(['%', '_'], '', $term).'%'])
                ->orWhere('cadastral_code', strtoupper(trim($this->sisterMunicipality))));
        }

        return $query->orderBy('name')->limit(20)->get()
            ->map(fn (Municipality $m) => ['name' => $m->name, 'province' => (string) ($m->province?->abbreviation ?? ''), 'code' => $m->cadastral_code])->all();
    }

    public function selectSisterMunicipality(string $code): void
    {
        abort_unless($this->canSisterImport(), 403);
        $municipality = collect($this->sisterMunicipalityOptions)->firstWhere('code', strtoupper(trim($code)));
        abort_unless($municipality !== null, 422);
        $this->invalidateSisterPreview();
        $this->sisterMunicipality = $municipality['name'];
        $this->sisterProvince = $municipality['province'];
        $this->sisterCode = $municipality['code'];
        $this->sisterTargetUnitId = '';
        $this->sisterPickerOpen = false;
        $this->sisterOptionIndex = 0;
        unset($this->sisterTargetUnits);
    }

    public function moveSisterOption(int $direction): void
    {
        $count = count($this->sisterMunicipalityOptions);
        $this->sisterPickerOpen = $count > 0 || $this->sisterPickerOpen;
        $this->sisterOptionIndex = $count === 0 ? 0 : max(0, min($count - 1, $this->sisterOptionIndex + ($direction < 0 ? -1 : 1)));
    }

    public function chooseSisterOption(): void
    {
        $municipality = $this->sisterMunicipalityOptions[$this->sisterOptionIndex] ?? null;
        if ($municipality !== null) {
            $this->selectSisterMunicipality($municipality['code']);
        }
    }

    public function closeSisterPicker(): void
    {
        $this->sisterPickerOpen = false;
    }

    /** «Unità a cui collegare i proprietari»: scelta (o «Cambia unità» con stringa vuota) dall'elenco del Comune. */
    public function chooseSisterTarget(string $unitId): void
    {
        abort_unless($this->canSisterImport() && ! $this->sisterTargetLocked, 403);
        abort_unless($unitId === '' || collect($this->sisterTargetUnits)->contains('id', (int) $unitId), 422);
        $this->invalidateSisterPreview();
        $this->sisterTargetUnitId = $unitId;
        $this->sisterTargetQuery = '';
    }
    public function updatedSisterKind(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterSection(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterSituationDate(): void { $this->invalidateSisterPreview(); }

    #[Computed]
    public function sisterTargetUnits(): array
    {
        abort_unless($this->canSisterImport(), 403);
        if ($this->sisterCode === '') {
            return [];
        }

        return CensusScope::units($this->membership)
            ->where('mu.cadastral_code', strtoupper($this->sisterCode))
            ->where('o.state', 'Attivo')->whereNull('o.removed')
            ->select('o.cadastral_unit_id', 'mu.name as municipality', 'p.sheet', 'p.number', 'cu.subalterno', 'o.address')
            ->orderBy('p.sheet')->orderBy('p.number')->orderBy('cu.subalterno')->limit(300)->get()
            ->map(fn ($unit) => [
                'id' => (int) $unit->cadastral_unit_id,
                'label' => $unit->municipality.' · F.'.$unit->sheet.' · P.'.$unit->number.' · Sub.'.($unit->subalterno ?: '—').($unit->address ? ' · '.$unit->address : ''),
            ])->all();
    }

    /** Unità del Comune che contengono tutte le parole cercate (census-import.tsx: matches, al massimo 30). @return list<array{id:int,label:string}> */
    #[Computed]
    public function sisterTargetMatches(): array
    {
        $terms = array_filter(preg_split('/[\s·,]+/u', mb_strtolower(trim($this->sisterTargetQuery), 'UTF-8')) ?: []);

        return array_slice(array_values(array_filter($this->sisterTargetUnits, function (array $unit) use ($terms): bool {
            $label = mb_strtolower($unit['label'], 'UTF-8');

            return collect($terms)->every(fn ($term) => str_contains($label, $term));
        })), 0, 30);
    }

    /** Etichetta dell'unità collegata (prefissata o scelta), anche se fuori dal primo elenco. */
    #[Computed]
    public function sisterTargetLabel(): string
    {
        if ($this->sisterTargetUnitId === '') {
            return '';
        }
        $entry = $this->canSisterImport() ? (new CensusReader($this->membership))->entries(['unitId' => (int) $this->sisterTargetUnitId])->first() : null;
        if ($entry === null) {
            return '';
        }

        return $entry['context']['municipality'].' · F.'.$entry['context']['sheet'].' · P.'.$entry['context']['parcel'].' · Sub.'.($entry['unit']['sub'] ?: '—')
            .' · '.($entry['unit']['address'] ?: $entry['parcelAddress']);
    }

    public function loadSisterTextFile(string $field): void
    {
        abort_unless($this->canSisterImport(), 403);
        $property = match ($field) {
            'estates' => 'sisterEstateFile',
            'owners' => 'sisterOwnerFile',
            default => abort(422),
        };
        $file = $this->{$property};
        if (! $file) {
            return;
        }
        if ($file->getSize() > 1_000_000 || strtolower($file->getClientOriginalExtension()) !== 'txt') {
            $this->sisterError = 'Usa un file di testo .txt entro 1 MB.';

            return;
        }
        $bytes = $file->get();
        if (str_starts_with($bytes, "\xFF\xFE")) {
            $text = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $text = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } else {
            $text = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        }
        if (str_starts_with(ltrim($text), '{\\rtf') || ! mb_check_encoding($text, 'UTF-8')) {
            $this->sisterError = 'Usa testo semplice (.txt), non RTF o un file con codifica non riconosciuta.';

            return;
        }
        if ($field === 'estates') {
            $this->sisterEstatesText = $text;
        } else {
            $this->sisterOwnersText = $text;
        }
        $this->{$property} = null;
        $this->invalidateSisterPreview();
    }

    /** Un solo pulsante, come l'originale: la prima volta controlla il testo, la seconda conferma e salva. */
    public function submitSisterImport(): void
    {
        $this->sisterPreview === null ? $this->previewSisterImport() : $this->applySisterImport();
    }

    public function previewSisterImport(): void
    {
        $membership = $this->membership;
        abort_unless($this->canSisterImport(), 403);
        $sourceText = trim($this->sisterEstatesText."\n".$this->sisterOwnersText);
        if (mb_strlen($sourceText) > 500_000) {
            $this->sisterError = 'Dividi il testo in blocchi entro 500.000 caratteri.';

            return;
        }
        // census-import.tsx submit(): controlli sui campi prima dell'anteprima.
        $misplaced = fn (string $row) => trim($row) !== '' && ! preg_match('/^(?:Situazione|Sezione)\b/iu', trim($row));
        $error = match (true) {
            $sourceText === '' => 'Incolla il testo da SISTER.',
            $this->sisterTargetLocked && trim($this->sisterOwnersText) === '' => 'Incolla gli intestatari nel campo Testo proprietari.',
            SisterText::split($this->sisterEstatesText)['owners'] !== '' => 'Nel campo Testo immobili ci sono intestatari: spostali nel campo Testo proprietari.',
            collect(preg_split('/\r\n|\n|\r/', SisterText::split($this->sisterOwnersText)['estates']))->contains($misplaced) => 'Nel campo Testo proprietari ci sono immobili: spostali nel campo Testo immobili.',
            default => '',
        };
        if ($error !== '') {
            $this->sisterError = $error;

            return;
        }
        try {
            $this->sisterPreview = app(CensusImporter::class)->preview($membership, [
                'sourceText' => $sourceText,
                'targetUnitId' => $this->sisterTargetUnitId !== '' ? (int) $this->sisterTargetUnitId : null,
                'context' => $this->sisterContext(),
            ]);
            $this->sisterToken = (string) \Illuminate\Support\Str::uuid();
            $this->sisterResult = null;
            $this->sisterError = $this->sisterPreview['errors'] ? implode(' ', $this->sisterPreview['errors']) : '';
            $this->sisterSituationDate = $this->sisterPreview['context']['situationDate'] ?: $this->sisterSituationDate;
        } catch (CommandRejected $e) {
            $this->sisterPreview = null;
            $this->sisterError = $e->getMessage();
        }
    }

    public function applySisterImport(): void
    {
        $membership = $this->membership;
        abort_unless($this->canSisterImport(), 403);
        if (! $this->sisterPreview || $this->sisterError !== '') {
            $this->sisterError = 'Controlla il testo e genera un’anteprima valida prima di salvare.';

            return;
        }
        if (collect($this->sisterPreview['missingOwners'])->contains(fn ($owner) => empty($this->sisterOwnerDecisions[$owner['key']]))) {
            $this->sisterError = 'Per ogni intestatario assente dalla visura, scegli se mantenerlo o chiuderne l’intestazione.';

            return;
        }
        $hasInvalid = collect($this->sisterPreview['rows'])->contains(fn ($row) => in_array($row['status'], ['Conflitto', 'Incompleto', 'Non riconosciuto'], true));
        if ($hasInvalid && ! $this->sisterSkipInvalid) {
            $this->sisterError = 'Conferma l’importazione parziale oppure correggi le righe escluse.';

            return;
        }
        try {
            $this->sisterResult = app(CensusImporter::class)->apply($membership, [
                'sourceText' => trim($this->sisterEstatesText."\n".$this->sisterOwnersText),
                'targetUnitId' => $this->sisterTargetUnitId !== '' ? (int) $this->sisterTargetUnitId : null,
                'context' => $this->sisterContext(),
                'token' => $this->sisterToken ?: (string) \Illuminate\Support\Str::uuid(),
                'ownerDecisions' => $this->sisterOwnerDecisions,
                'confirmNameUpdates' => $this->sisterConfirmNameUpdates,
                'skipInvalid' => $this->sisterSkipInvalid,
                'confirmUpdates' => true,
            ]);
            $this->sisterError = '';
            $this->sisterToken = (string) \Illuminate\Support\Str::uuid();
            unset($this->municipalities, $this->results, $this->sisterTargetUnits);
        } catch (CommandRejected $e) {
            $this->sisterError = $e->getMessage();
        }
    }

    private function sisterContext(): array
    {
        return [
            'province' => strtoupper(trim($this->sisterProvince)),
            'municipality' => trim($this->sisterMunicipality),
            'code' => strtoupper(trim($this->sisterCode)),
            'kind' => $this->sisterKind,
            'section' => strtoupper(trim($this->sisterSection)),
            'situationDate' => $this->sisterSituationDate,
        ];
    }

    private function invalidateSisterPreview(): void
    {
        $this->sisterPreview = null;
        $this->sisterResult = null;
        $this->sisterOwnerDecisions = [];
        $this->sisterConfirmNameUpdates = [];
        $this->sisterSkipInvalid = false;
        $this->sisterToken = '';
        $this->sisterError = '';
    }

    private function resetSisterImport(): void
    {
        $this->reset('sisterEstatesText', 'sisterOwnersText', 'sisterProvince', 'sisterMunicipality', 'sisterCode', 'sisterKind',
            'sisterSection', 'sisterSituationDate', 'sisterTargetUnitId', 'sisterEstateFile', 'sisterOwnerFile', 'sisterPreview',
            'sisterResult', 'sisterOwnerDecisions', 'sisterConfirmNameUpdates', 'sisterSkipInvalid', 'sisterToken', 'sisterError',
            'sisterTargetLocked', 'sisterTargetQuery', 'sisterPickerOpen', 'sisterOptionIndex');
        $this->sisterKind = 'Fabbricati';
        unset($this->sisterTargetUnits, $this->sisterMunicipalityOptions);
    }

    private function guard(): AgencyMembership
    {
        $membership = app(CurrentAgency::class)->membership();
        abort_unless(CensusScope::canUse($membership), 403, CensusScope::NOT_ALLOWED);

        return $membership;
    }

    #[Computed]
    public function membership(): AgencyMembership
    {
        return $this->guard();
    }

    /** @return list<array{name:string,province:string,code:string}> */
    #[Computed]
    public function municipalities(): array
    {
        return (new CensusReader($this->membership))->municipalities();
    }

    #[Computed]
    public function municipalityName(): string
    {
        return collect($this->municipalities)->firstWhere('code', $this->municipalityCode)['name'] ?? '';
    }

    #[Computed]
    public function archiveReady(): bool
    {
        return $this->hasFocus() || $this->municipalityCode !== '' && ($this->mode !== 'catastale'
            || trim($this->search) !== ''
            || (trim($this->sheet) !== '' && trim($this->parcel) !== '' && (trim($this->sub) !== '' || $this->withoutSub)));
    }

    /** @return list<array{name:string,province:string,code:string}> */
    #[Computed]
    public function municipalityOptions(): array
    {
        $term = $this->normalizeMunicipalitySearch($this->municipalitySearch);

        return collect($this->municipalities)
            ->filter(fn (array $municipality) => $term === ''
                || str_contains($this->normalizeMunicipalitySearch($municipality['name']), $term)
                || strtoupper($municipality['code']) === strtoupper(trim($this->municipalitySearch)))
            ->take(20)
            ->values()
            ->all();
    }

    private function normalizeMunicipalitySearch(string $value): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii(trim($value)), 'UTF-8');
    }

    public function updatedMunicipalitySearch(): void
    {
        $selected = $this->municipalityName;
        if ($selected === '' || $this->normalizeMunicipalitySearch($this->municipalitySearch) !== $this->normalizeMunicipalitySearch($selected)) {
            $this->municipalityCode = '';
            $this->resetResults();
        }

        $this->municipalityPickerOpen = true;
        $this->municipalityOptionIndex = 0;
        unset($this->municipalityOptions);
    }

    public function selectMunicipality(string $code): void
    {
        $municipality = collect($this->municipalities)->firstWhere('code', strtoupper(trim($code)));
        abort_unless($municipality !== null, 403);

        $this->municipalityCode = $municipality['code'];
        $this->municipalitySearch = $municipality['name'];
        $this->municipalityPickerOpen = false;
        $this->municipalityOptionIndex = 0;
        $this->resetResults();
    }

    public function moveMunicipalityOption(int $direction): void
    {
        $count = count($this->municipalityOptions);
        if ($count === 0) {
            $this->municipalityOptionIndex = 0;
            return;
        }

        $this->municipalityPickerOpen = true;
        $this->municipalityOptionIndex = max(0, min($count - 1, $this->municipalityOptionIndex + ($direction < 0 ? -1 : 1)));
    }

    public function chooseMunicipalityOption(): void
    {
        $municipality = $this->municipalityOptions[$this->municipalityOptionIndex] ?? null;
        if ($municipality !== null) {
            $this->selectMunicipality($municipality['code']);
        }
    }

    /** @return array{page:int,size:int,total:int,items:list<array<string,mixed>>,people:list<array<string,mixed>>,options:array<string,list<string>>} */
    #[Computed]
    public function results(): array
    {
        $empty = ['page' => $this->page, 'size' => 20, 'total' => 0, 'items' => [], 'people' => [], 'options' => ['outcome' => ['Nessun esito']]];
        if (! $this->archiveReady || (! $this->hasFocus() && ! in_array($this->municipalityCode, array_column($this->municipalities, 'code'), true))) {
            return $empty;
        }

        $filters = $this->currentFilters();

        return (new CensusQuery($this->membership))->run([
            'view' => $this->view, 'search' => mb_substr($this->search, 0, 300), 'filters' => $filters,
            'parcelId' => $this->focusParcelId, 'unitId' => $this->focusUnitId, 'ownerId' => $this->focusOwnerId,
            'page' => max(1, $this->page), 'size' => 20, 'after' => $this->after,
            'order' => in_array($this->order, ['Da contattare', 'Ultima attività', 'Esito'], true) ? $this->order : 'Da contattare',
            'firstOutcome' => in_array($this->firstOutcome, ['Nessun esito', ...CensusQuery::outcomes()], true) ? $this->firstOutcome : 'Interessato',
        ]);
    }

    /** @return array{page:int,size:int,total:int,items:list<array<string,mixed>>} */
    #[Computed]
    public function ownerUnits(): array
    {
        if ($this->openOwnerId === null || ! CensusScope::ownerVisible($this->membership, $this->openOwnerId)) {
            return ['page' => 1, 'size' => 10, 'total' => 0, 'items' => []];
        }

        return (new CensusReader($this->membership))->ownerEntriesPage($this->openOwnerId, $this->ownerUnitsPage, 10);
    }

    /** @return array{holdings:list<array<string,mixed>>,contacts:list<array<string,mixed>>,outcomes:list<array<string,mixed>>}|null */
    #[Computed]
    public function ownerHistory(): ?array
    {
        if ($this->historyOwnerId === null || ! CensusScope::ownerVisible($this->membership, $this->historyOwnerId)) {
            return null;
        }

        return (new CensusReader($this->membership))->ownerHistory($this->historyOwnerId);
    }

    /** @return array<string,list<string>> */
    private function currentFilters(): array
    {
        // Con un collegamento diretto vale solo l'id, qualunque sia il Comune (come l'originale).
        $filters = ($this->hasFocus() ? [] : ['municipalityCode' => [$this->municipalityCode]]) + ['_mode' => [$this->mode]];
        $modeFields = match ($this->mode) {
            'catastale' => ['sheet' => $this->sheet, 'parcel' => $this->parcel, 'sub' => $this->sub],
            'frazione' => ['locality' => $this->locality],
            'indirizzo' => ['address' => $this->address, 'civic' => $this->civic, 'floor' => $this->floor],
            'proprietario' => ['name' => $this->firstName, 'lastName' => $this->lastName, 'cf' => $this->taxCode],
            default => [],
        };
        foreach ($modeFields as $key => $value) {
            if ($key === 'sub' && $this->withoutSub) {
                continue;
            }
            if (trim($value) !== '') {
                $filters[$key] = [trim($value)];
            }
        }
        if ($this->mode === 'catastale' && $this->withoutSub) {
            $filters['withoutSub'] = ['Sì'];
        }
        if ($this->outcome !== '') {
            $filters['outcome'] = [$this->outcome];
        }
        $allowedOperationalFilters = ['category', 'hasOwner', 'hasContact', 'coownership', 'multiOwner', 'never', 'upcoming', 'count'];
        foreach ($this->filterValues as $key => $value) {
            if (! in_array($key, $allowedOperationalFilters, true)) {
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = [trim($value)];
            }
        }

        return $filters;
    }

    public function updatedFilterValues(): void { $this->resetResults(); }
    public function updatedMunicipalityCode(): void
    {
        $municipality = collect($this->municipalities)->firstWhere('code', $this->municipalityCode);
        $this->municipalitySearch = $municipality['name'] ?? '';
        $this->municipalityPickerOpen = false;
        $this->municipalityOptionIndex = 0;
        $this->resetResults();
    }

    public function closeMunicipalityPicker(): void
    {
        $this->municipalityPickerOpen = false;
    }
    public function updatedMode(): void { if (! in_array($this->mode, CensusQuery::MODES, true)) $this->mode = 'indirizzo'; $this->resetResults(); }
    public function updatedView(): void { if (! in_array($this->view, ['Immobili', 'Proprietari'], true)) $this->view = 'Immobili'; $this->resetResults(); }
    public function updatedSearch(): void { $this->resetResults(); }
    public function updatedOutcome(): void { $this->resetResults(); }
    public function updatedSheet(): void { $this->resetResults(); }
    public function updatedParcel(): void { $this->resetResults(); }
    public function updatedSub(): void { $this->resetResults(); }
    public function updatedWithoutSub(): void
    {
        if ($this->withoutSub) {
            $this->sub = '';
        }
        $this->resetResults();
    }
    public function updatedLocality(): void { $this->resetResults(); }
    public function updatedAddress(): void { $this->resetResults(); }
    public function updatedCivic(): void { $this->resetResults(); }
    public function updatedFloor(): void { $this->resetResults(); }
    public function updatedFirstName(): void { $this->resetResults(); }
    public function updatedLastName(): void { $this->resetResults(); }
    public function updatedTaxCode(): void { $this->resetResults(); }
    public function updatedOrder(): void { $this->resetPage(); }
    public function updatedFirstOutcome(): void { $this->resetPage(); }

    private function resetResults(): void
    {
        $this->page = 1;
        $this->after = null;
        $this->cursorStack = [];
        $this->ownerUnitsPage = 1;
        $this->selectedUnitIds = [];
        $this->openOwnerId = null;
        unset($this->results, $this->ownerUnits);
    }

    private function resetPage(): void
    {
        $this->page = 1;
        $this->after = null;
        $this->cursorStack = [];
        unset($this->results);
    }

    public function nextPage(?array $cursor): void
    {
        if ($cursor === null || $this->page >= (int) ceil($this->results['total'] / 20)) {
            return;
        }
        $this->cursorStack[] = $this->after;
        $this->after = $cursor;
        $this->page++;
        $this->selectedUnitIds = [];
        unset($this->results);
    }

    public function prevPage(): void
    {
        if ($this->page <= 1) {
            return;
        }
        $this->after = array_pop($this->cursorStack);
        $this->page--;
        $this->selectedUnitIds = [];
        unset($this->results);
    }

    public function setPage(int $page): void
    {
        $this->after = null;
        $this->cursorStack = [];
        $this->page = max(1, min($page, max(1, (int) ceil($this->results['total'] / 20))));
        $this->selectedUnitIds = [];
        unset($this->results);
    }

    public function resetFilters(): void
    {
        $this->reset('municipalityCode', 'municipalitySearch', 'municipalityPickerOpen', 'municipalityOptionIndex', 'search', 'outcome', 'sheet', 'parcel', 'sub', 'withoutSub', 'locality', 'address', 'civic', 'floor', 'firstName', 'lastName', 'taxCode', 'order', 'firstOutcome', 'filterValues');
        $this->mode = 'indirizzo';
        $this->order = 'Da contattare';
        $this->firstOutcome = 'Interessato';
        $this->resetResults();
    }

    public function selectCurrentPage(bool $selected): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        $ids = array_map(fn ($row) => (int) $row['unitId'], $this->results['items']);
        $this->selectedUnitIds = $selected ? array_values(array_unique([...$this->selectedUnitIds, ...$ids])) : array_values(array_diff($this->selectedUnitIds, $ids));
    }

    public function openBulkRemoval(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if ($this->selectedUnitIds === []) {
            $this->notice = 'Seleziona almeno un immobile.';

            return;
        }
        $this->bulkRemovalReason = '';
        $this->notice = '';
        $this->bulkRemovalOpen = true;
    }

    public function closeBulkRemoval(): void
    {
        $this->bulkRemovalOpen = false;
        $this->bulkRemovalReason = '';
    }

    public function removeSelected(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        try {
            $count = app(RemoveCensusUnits::class)->handle($this->membership, $this->selectedUnitIds, $this->bulkRemovalReason);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->bulkRemovalOpen = false;
        $this->bulkRemovalReason = '';
        $this->selectedUnitIds = [];
        $this->notice = $count.' '.($count === 1 ? 'immobile rimosso dall’elenco attivo.' : 'immobili rimossi dall’elenco attivo.');
        unset($this->results, $this->municipalities, $this->ownerUnits);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    /** «Mostra altri N» / «Mostra meno» oltre i due comproprietari. */
    public function toggleCoowners(int $unitId): void
    {
        $this->expandedUnits = in_array($unitId, $this->expandedUnits, true)
            ? array_values(array_diff($this->expandedUnits, [$unitId]))
            : [...$this->expandedUnits, $unitId];
    }

    public function showOwnerProperties(int $ownerId): void
    {
        abort_unless(CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->openOwnerId = $this->openOwnerId === $ownerId ? null : $ownerId;
        $this->ownerUnitsPage = 1;
        unset($this->ownerUnits);
    }

    public function openOwnerHistory(int $ownerId): void
    {
        abort_unless(CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->historyOwnerId = $ownerId;
        unset($this->ownerHistory);
    }

    public function closeOwnerHistory(): void
    {
        $this->historyOwnerId = null;
        unset($this->ownerHistory);
    }

    public function setOwnerUnitsPage(int $page): void
    {
        $total = (int) $this->ownerUnits['total'];
        $this->ownerUnitsPage = max(1, min($page, max(1, (int) ceil($total / 10))));
        unset($this->ownerUnits);
    }

    public function openOutcomeHistory(int $unitId): void
    {
        abort_unless(CensusScope::unitVisible($this->membership, $unitId), 403);
        $this->historyUnitId = $unitId;
        $this->reset('historyOutcomeFilter', 'historyTypeFilter', 'historyAuthorFilter', 'historyFrom', 'historyTo');
        unset($this->outcomeHistory, $this->filteredOutcomeHistory);
    }

    public function closeOutcomeHistory(): void
    {
        $this->historyUnitId = null;
        $this->reset('historyOutcomeFilter', 'historyTypeFilter', 'historyAuthorFilter', 'historyFrom', 'historyTo');
        unset($this->outcomeHistory, $this->filteredOutcomeHistory);
    }

    public function correctOutcomeFromHistory(int $activityId): void
    {
        abort_unless($this->membership->role === 'admin' && $this->historyUnitId !== null, 403);
        abort_unless(CensusScope::unitVisible($this->membership, $this->historyUnitId), 403);

        $activity = Activity::query()->where('agency_id', $this->membership->agency_id)
            ->with('units')->findOrFail($activityId);
        abort_unless($activity->units->contains(fn ($unit) => (int) $unit->cadastral_unit_id === $this->historyUnitId), 404);
        abort_unless(app(ActivityAccess::class)->canSee($this->membership, $activity)
            && app(ActivityAccess::class)->canEdit($this->membership, $activity), 403);

        $entry = $this->outcomeHistory['entry'] ?? abort(404);
        $context = ActivityContext::normalize(['parcel_id' => $entry['parcelId'], 'unit_ids' => [$this->historyUnitId]]);
        $scope = 'rec-'.substr(md5(json_encode($context)), 0, 10);
        $this->dispatch('activity-form', scope: $scope, id: $activityId);
    }

    #[Computed]
    public function outcomeHistory(): ?array
    {
        if ($this->historyUnitId === null || ! CensusScope::unitVisible($this->membership, $this->historyUnitId)) {
            return null;
        }

        $entry = (new CensusReader($this->membership))->entries(['unitId' => $this->historyUnitId])->first();

        return $entry ? ['entry' => $entry, 'rows' => $entry['history']] : null;
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function filteredOutcomeHistory(): array
    {
        $rows = $this->outcomeHistory['rows'] ?? [];

        return array_values(array_filter($rows, function (array $row): bool {
            $date = substr((string) $row['at'], 0, 10);

            return ($this->historyOutcomeFilter === '' || $row['outcome'] === $this->historyOutcomeFilter)
                && ($this->historyTypeFilter === '' || $row['type'] === $this->historyTypeFilter)
                && ($this->historyAuthorFilter === '' || (string) $row['authorId'] === $this->historyAuthorFilter)
                && ($this->historyFrom === '' || $date >= $this->historyFrom)
                && ($this->historyTo === '' || $date <= $this->historyTo);
        }));
    }

    public function editOwnerContact(int $ownerId, ?int $unitId = null): void
    {
        abort_unless(CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $owner = Contact::query()->where('agency_id', $this->membership->agency_id)->whereNull('removed_at')->findOrFail($ownerId);
        $phones = DB::table('contact_channels')->where('contact_id', $ownerId)->where('kind', 'phone')->orderByDesc('is_primary')->orderBy('id')->pluck('value')->all();
        $this->contactId = $ownerId;
        $this->contactContextUnitId = $unitId;
        $this->contactDraft = $owner->recapito ?? implode(' / ', $phones);
        $this->confirmRepeatedContact = false;
        $this->notice = '';
    }

    public function saveOwnerContact(): void
    {
        if ($this->contactId === null) {
            return;
        }
        try {
            app(SaveOwnerContact::class)->handle($this->membership, $this->contactId, [
                'recapito' => $this->contactDraft, 'confirmRepeated' => $this->confirmRepeatedContact,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->contactId = null;
        $this->contactContextUnitId = null;
        $this->notice = 'Recapito aggiornato in tutte le unità del proprietario';
        unset($this->results, $this->ownerUnits, $this->editingOwnerContact);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function closeContact(): void
    {
        $this->contactId = null;
        $this->contactContextUnitId = null;
        $this->contactDraft = '';
        $this->confirmRepeatedContact = false;
    }

    public function openProposal(?int $unitId = null, ?int $ownerId = null, string $name = '', string $pid = ''): void
    {
        abort_unless($unitId !== null || $ownerId !== null, 404);
        if ($unitId !== null) {
            abort_unless(CensusScope::unitVisible($this->membership, $unitId), 403);
        }
        if ($ownerId !== null) {
            abort_unless(CensusScope::ownerVisible($this->membership, $ownerId), 403);
        }
        $this->proposalUnitId = $unitId;
        $this->proposalOwnerId = $ownerId;
        $this->proposalName = mb_substr($name, 0, 255);
        $this->proposalPid = mb_substr($pid, 0, 32);
        $this->proposalNote = '';
        $this->proposalNewOwner = false;
        $this->notice = '';
        $this->proposalOpen = true;
    }

    public function closeProposal(): void
    {
        $this->proposalOpen = false;
        $this->proposalUnitId = null;
        $this->proposalOwnerId = null;
        $this->proposalName = '';
        $this->proposalPid = '';
        $this->proposalNote = '';
    }

    public function openOutcome(int $unitId, ?int $ownerId = null): void
    {
        abort_if($this->membership->role === 'crm', 403);
        abort_unless(CensusScope::unitVisible($this->membership, $unitId), 403);
        $this->reset('outcomeOperation', 'outcomeValue', 'outcomeNote', 'outcomeExistingId', 'outcomeConfirmNew', 'outcomeSchedule', 'outcomeNextAt', 'outcomeNextReason');
        $this->outcomeUnitId = $unitId;
        $this->outcomeOwnerId = $ownerId;
        $this->outcomeToken = (string) \Illuminate\Support\Str::uuid();
        $this->notice = '';
        $this->outcomeOpen = true;
    }

    public function closeOutcome(): void
    {
        $this->outcomeOpen = false;
        $this->outcomeUnitId = null;
        $this->outcomeOwnerId = null;
    }

    /** Proprietari attuali dell'unità e attività da completare oggi (per non duplicare il contatto). */
    #[Computed]
    public function outcomeContext(): array
    {
        if (! $this->outcomeOpen || $this->outcomeUnitId === null) {
            return ['owners' => [], 'pending' => []];
        }
        $owners = \App\Models\Ownership::query()->where('agency_id', $this->membership->agency_id)->where('cadastral_unit_id', $this->outcomeUnitId)
            ->whereNull('valid_to')->with('contact')->get()->filter(fn ($o) => $o->contact && ! $o->contact->isRemoved() && CensusScope::ownerVisible($this->membership, (int) $o->contact_id))
            ->map(fn ($o) => ['id' => (int) $o->contact_id, 'name' => $o->contact->display_name])->unique('id')->values()->all();
        $pending = [];
        if ($this->outcomeOwnerId) {
            $parcelId = (int) \Illuminate\Support\Facades\DB::table('cadastral_units')->where('id', $this->outcomeUnitId)->value('parcel_id');
            $pending = RecordCensusOutcome::pendingToday($this->membership, $this->outcomeOwnerId, $parcelId, $this->outcomeUnitId)
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->kind.' · '.$a->subject, 'selectable' => in_array($a->kind, ['Telefonata', 'Prossima attività'], true)])->all();
        }

        return ['owners' => $owners, 'pending' => $pending];
    }

    public function submitOutcome(): void
    {
        if (! $this->outcomeOwnerId || $this->outcomeOperation === '' || $this->outcomeValue === '') {
            $this->notice = 'Scegli il proprietario, vendita o affitto e un esito.';

            return;
        }
        try {
            app(RecordCensusOutcome::class)->handle($this->membership, (int) $this->outcomeUnitId, [
                'owner_id' => $this->outcomeOwnerId, 'type' => 'Chiamata', 'contact_operation' => $this->outcomeOperation,
                'outcome' => $this->outcomeValue, 'notes' => $this->outcomeNote, 'existing_activity_id' => $this->outcomeExistingId,
                'confirm_new' => $this->outcomeConfirmNew, 'token' => $this->outcomeToken,
                'next_type' => $this->outcomeSchedule ? 'Prossima attività' : 'Nessuno', 'next_at' => $this->outcomeNextAt, 'next_reason' => $this->outcomeNextReason,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->closeOutcome();
        $this->notice = 'Riscontro di contatto registrato.';
        unset($this->results);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function submitProposal(): void
    {
        try {
            app(SaveCensusProposal::class)->handle($this->membership, $this->proposalUnitId, $this->proposalOwnerId, [
                'name' => $this->proposalName,
                'pid' => $this->proposalPid,
                'note' => $this->proposalNote,
                'newOwner' => $this->proposalNewOwner,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->closeProposal();
        $this->notice = 'Proposta inviata all’Amministratore.';
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function openOwnerRemoval(int $ownerId): void
    {
        abort_unless($this->membership->role === 'admin' && CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->censusRemovalMode = 'owner';
        $this->censusRemovalOwnerId = $ownerId;
        $this->censusRemovalReason = '';
        $this->censusRemovalConfirmed = false;
        $this->censusRemovalOpen = true;
    }

    /** @return array{name:string,links:int,portfolio:int,activities:int} collegamenti riportati nella finestra «Elimina proprietario» */
    #[Computed]
    public function censusRemovalSummary(): array
    {
        if (! $this->censusRemovalOpen || $this->censusRemovalOwnerId === null) {
            return ['name' => '', 'links' => 0, 'portfolio' => 0, 'activities' => 0];
        }
        $agency = $this->membership->agency_id;
        $id = $this->censusRemovalOwnerId;

        return [
            'name' => (string) Contact::query()->where('agency_id', $agency)->whereKey($id)->value('display_name'),
            'links' => \App\Models\Ownership::query()->where('agency_id', $agency)->where('contact_id', $id)->whereNull('valid_to')->distinct()->count('cadastral_unit_id'),
            'portfolio' => DB::table('property_contacts')->where('agency_id', $agency)->where('contact_id', $id)->count(),
            'activities' => Activity::query()->where('agency_id', $agency)->where('contact_id', $id)->count(),
        ];
    }

    public function closeCensusRemoval(): void
    {
        $this->censusRemovalOpen = false;
        $this->censusRemovalMode = '';
        $this->censusRemovalOwnerId = null;
        $this->censusRemovalReason = '';
    }

    public function confirmCensusRemoval(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if (! $this->censusRemovalConfirmed || $this->censusRemovalOwnerId === null) {
            $this->notice = 'Conferma la variazione sulla scheda indicata.';

            return;
        }
        try {
            if ($this->censusRemovalMode !== 'owner') {
                abort(422);
            }
            app(RemoveCensusOwnership::class)->removeOwner($this->membership, $this->censusRemovalOwnerId, [
                'reason' => $this->censusRemovalReason, 'confirmed' => $this->censusRemovalConfirmed,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->closeCensusRemoval();
        $this->notice = 'Variazione registrata nello storico; nessun dato cancellato fisicamente.';
        unset($this->results, $this->ownerUnits);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function openOwnerEditor(int $ownerId): void
    {
        abort_unless($this->membership->role === 'admin' && CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $owner = Contact::query()->where('agency_id', $this->membership->agency_id)->whereNull('removed_at')->findOrFail($ownerId);
        $this->editingOwnerId = $ownerId;
        $this->ownerEditName = $owner->display_name;
        $this->ownerEditPid = $owner->tax_code ?: $owner->vat_number ?: '';
        $this->ownerEditFirstName = $owner->given_name ?: '';
        $this->ownerEditLastName = $owner->family_name ?: '';
        $this->ownerEditCompanyName = $owner->company_name ?: '';
        $this->ownerEditNotes = $owner->notes ?: '';
        $this->ownerEditTags = array_values($owner->tags ?? []);
        $this->ownerEditReason = '';
        $this->ownerEditRevision = $owner->updated_at?->toISOString();
        $this->notice = '';
        $this->ownerEditorOpen = true;
    }

    public function saveOwner(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if ($this->editingOwnerId === null) {
            abort(404);
        }
        try {
            app(SaveOwner::class)->handle($this->membership, $this->editingOwnerId, [
                'name' => $this->ownerEditName, 'pid' => $this->ownerEditPid,
                'firstName' => $this->ownerEditFirstName, 'lastName' => $this->ownerEditLastName,
                'companyName' => $this->ownerEditCompanyName, 'notes' => $this->ownerEditNotes,
                'tags' => $this->ownerEditTags,
                'reason' => $this->ownerEditReason, 'expected_updated_at' => $this->ownerEditRevision,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->ownerEditorOpen = false;
        $this->editingOwnerId = null;
        $this->notice = 'Anagrafica aggiornata in tutte le unità collegate';
        if ($this->sisterImportOpen) {
            $this->invalidateSisterPreview();
        }
        unset($this->results, $this->ownerUnits);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    /** Recapito attuale del proprietario in modifica, mostrato con ContactEditor nella finestra dell'anagrafica. */
    #[Computed]
    public function editingOwnerContact(): string
    {
        if ($this->editingOwnerId === null) {
            return '';
        }
        $owner = Contact::query()->where('agency_id', $this->membership->agency_id)->whereKey($this->editingOwnerId)->first();
        if ($owner === null) {
            return '';
        }

        return $owner->recapito ?? implode(' / ', DB::table('contact_channels')->where('contact_id', $owner->id)->where('kind', 'phone')->orderByDesc('is_primary')->orderBy('id')->pluck('value')->all());
    }

    public function closeOwnerEditor(): void
    {
        $this->ownerEditorOpen = false;
        $this->editingOwnerId = null;
        $this->ownerEditReason = '';
        if ($this->sisterImportOpen) {
            $this->invalidateSisterPreview();
        }
    }

    public function openCadastralEditor(int $unitId): void
    {
        abort_unless($this->membership->role === 'admin' && CensusScope::unitVisible($this->membership, $unitId), 403);
        $entry = (new CensusReader($this->membership))->entries(['unitId' => $unitId])->first() ?? abort(404);
        $this->cadastralUnitId = $unitId;
        $this->cadastralAddress = $entry['unit']['address'];
        $this->cadastralCategory = $entry['unit']['category'];
        $this->cadastralFloor = $entry['unit']['floor'];
        $this->cadastralZone = $entry['unit']['censusZone'];
        $this->cadastralClass = $entry['unit']['cadastralClass'];
        $this->cadastralConsistency = $entry['unit']['consistency'];
        $this->cadastralIncome = $entry['unit']['income'] === null ? '' : (string) $entry['unit']['income'];
        $this->cadastralLastVerified = $entry['unit']['lastVerified'];
        $this->cadastralReason = '';
        $this->cadastralRevision = \App\Models\AgencyUnitObservation::query()->where('agency_id', $this->membership->agency_id)->where('cadastral_unit_id', $unitId)->firstOrFail()->updated_at?->toISOString();
        $this->cadastralEditorOpen = true;
    }

    public function saveCadastral(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if ($this->cadastralUnitId === null) {
            abort(404);
        }
        try {
            app(SaveCadastral::class)->handle($this->membership, $this->cadastralUnitId, [
                'address' => $this->cadastralAddress, 'category' => $this->cadastralCategory, 'floor' => $this->cadastralFloor,
                'censusZone' => $this->cadastralZone, 'cadastralClass' => $this->cadastralClass, 'consistency' => $this->cadastralConsistency,
                'income' => $this->cadastralIncome, 'lastVerified' => $this->cadastralLastVerified,
                'reason' => $this->cadastralReason, 'expected_updated_at' => $this->cadastralRevision,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->cadastralEditorOpen = false;
        $this->cadastralUnitId = null;
        $this->notice = 'Dati catastali corretti e registrati nello storico.';
        unset($this->results);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function closeCadastralEditor(): void
    {
        $this->cadastralEditorOpen = false;
        $this->cadastralUnitId = null;
        $this->cadastralReason = '';
    }
}; ?>

<div>
    @php
        // Con la finestra «Modifica anagrafica» aperta il recapito si modifica da lì, non anche dalla scheda sotto.
        $cardContactId = $ownerEditorOpen ? null : $contactId;
        // Richieste che mostrano lo stato «Caricamento archivio catastale…» (useCensusQuery: loading).
        $loadingTargets = 'selectMunicipality,chooseMunicipalityOption,view,mode,search,outcome,sheet,parcel,sub,withoutSub,locality,address,civic,floor,firstName,lastName,taxCode,filterValues,order,firstOutcome,setPage,nextPage,prevPage,setOwnerUnitsPage,showOwnerProperties,resetFilters,clearFocus';
    @endphp
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Archivio catastale</h1>
            <p class="crm-muted">{{ $view === 'Proprietari' ? 'Intestatari e recapiti collegati alle unità censite.' : 'Unità catastali, intestazioni e storico. Dati separati dagli immobili commerciali.' }}</p>
        </div>
        <div class="crm-actions">
            @can('agency-permission', 'sister.import')
                <button type="button" class="crm-btn" wire:click="openSisterImport"><x-gestionale.lucide name="upload" :size="16" />Importa da SISTER immobili e proprietari</button>
            @endcan
            {{-- Call 07/10, punto 15: in “Immobili” serve l’importazione; l’esportazione dei codici resta nella vista Proprietari. --}}
            @if ($view === 'Proprietari')@can('agency-permission', 'exports')<a class="crm-btn secondary" href="{{ route('gestionale.archive.export.missing-phone') }}">Esporta CF / P.IVA senza telefono · CSV</a>@endcan @endif
            <a class="crm-btn secondary" href="{{ route('gestionale.scouting.index') }}" wire:navigate><x-gestionale.lucide name="map" :size="16" />Apri mappa e zone</a>
        </div>
    </div>

    @if ($notice !== '')<p class="crm-form-error" role="status">{{ $notice }}</p>@endif

    @if ($focusParcelId !== null || $focusUnitId !== null || $focusOwnerId !== null)
        <button type="button" class="crm-back" wire:click="clearFocus"><x-gestionale.lucide name="arrow-left" :size="16" />Torna all’elenco completo</button>
    @endif

    <nav class="crm-tabs" aria-label="Vista censimento">
        @foreach (['Immobili', 'Proprietari'] as $tab)<button type="button" wire:click="$set('view', '{{ $tab }}')" @if ($view === $tab) aria-current="page" @endif>{{ $tab }}</button>@endforeach
    </nav>

    <section class="crm-panel census-filters" aria-label="Filtri Archivio catastale">
        <h2>Filtra l’Archivio</h2>
        <div class="crm-form-grid census-primary-filters">
            @include('gestionale.archive.municipality-picker', ['id' => 'archive-municipality', 'model' => 'municipalitySearch', 'openProperty' => 'municipalityPickerOpen',
                'options' => $this->municipalityOptions, 'activeIndex' => $municipalityOptionIndex, 'isOpen' => $municipalityPickerOpen,
                'select' => 'selectMunicipality', 'move' => 'moveMunicipalityOption', 'choose' => 'chooseMunicipalityOption', 'close' => 'closeMunicipalityPicker',
                'listLabel' => 'Comuni presenti in archivio', 'emptyText' => $this->municipalities === [] ? 'Nessun Comune con immobili presenti.' : 'Nessun Comune con questo nome.'])
            @if ($municipalityCode !== '')
                <label class="crm-field"><span>Ricerca libera</span><input type="search" wire:model.live.debounce.300ms="search" maxlength="300" placeholder="Es. Cenisio 13, Rossi oppure 262/73"></label>
                <label class="crm-field"><span>Esito</span>
                    <select wire:model.live="outcome"><option value="">Tutti</option>@foreach (array_unique(array_filter(['Nessun esito', ...\App\Gestionale\Census\CensusQuery::outcomes(), ...$this->results['options']['outcome'], $outcome])) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                </label>
            @endif
        </div>
        @if ($municipalityCode === '')
            <p class="crm-info">Seleziona il Comune per aprire i filtri e le schede dell’archivio.</p>
        @else
            <details class="census-advanced-filters" wire:ignore.self wire:key="archive-advanced-filters"><summary>Filtri avanzati</summary>
                <div class="crm-tabs" aria-label="Modalità di ricerca catastale">
                    @foreach (['catastale' => 'Riferimenti catastali', 'frazione' => 'Frazione', 'indirizzo' => 'Indirizzo', 'proprietario' => 'Proprietario'] as $key => $label)
                        <button type="button" wire:click="$set('mode', '{{ $key }}')" aria-pressed="{{ $mode === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="census-mode-fields">
                    @if ($mode === 'catastale')
                        <div class="crm-form-grid">
                            <label class="crm-field"><span>Foglio</span><input wire:model.live.debounce.250ms="sheet" inputmode="numeric" required></label>
                            <label class="crm-field"><span>Particella</span><input wire:model.live.debounce.250ms="parcel" required></label>
                            @unless ($withoutSub)<label class="crm-field"><span>Subalterno</span><input wire:model.live.debounce.250ms="sub" required></label>@endunless
                            <label class="crm-check"><input type="checkbox" wire:model.live="withoutSub">Senza subalterno nella fonte</label>
                        </div>
                        <p class="crm-muted">Per una ricerca precisa inserisci foglio, particella e subalterno, oppure indica che il subalterno è assente.</p>
                        <details wire:ignore.self><summary>Mostra dettagli aggiuntivi</summary><div class="crm-form-grid"><label class="crm-field"><span>Categoria</span><input wire:model.live.debounce.250ms="filterValues.category"></label></div></details>
                    @elseif ($mode === 'frazione')
                        <label class="crm-field"><span>Frazione</span><input wire:model.live.debounce.250ms="locality" placeholder="Es. San Rocco Castagnaretta"></label>
                        <p class="crm-muted">Cerca nelle frazioni compilate nella scheda. Non vengono dedotte dall’indirizzo SISTER.</p>
                    @elseif ($mode === 'indirizzo')
                        <div class="crm-form-grid">
                            <label class="crm-field"><span>Via</span><input wire:model.live.debounce.250ms="address" placeholder="Es. Via Cenisio"></label>
                            <label class="crm-field"><span>Civico</span><input wire:model.live.debounce.250ms="civic" placeholder="Es. 10/A"></label>
                            <label class="crm-field"><span>Piano</span><input wire:model.live.debounce.250ms="floor" placeholder="Es. T, 2, S1"></label>
                        </div>
                    @else
                        <div class="crm-form-grid">
                            <label class="crm-field"><span>Nome o ragione sociale</span><input wire:model.live.debounce.250ms="firstName"></label>
                            <label class="crm-field"><span>Cognome</span><input wire:model.live.debounce.250ms="lastName"></label>
                            <label class="crm-field"><span>Codice fiscale / Partita IVA</span><input wire:model.live.debounce.250ms="taxCode"></label>
                        </div>
                        <p class="crm-muted">Per le anagrafiche non ancora separate, nome e cognome cercano nel nominativo completo. Il codice fiscale distingue gli omonimi.</p>
                    @endif
                </div>
                <details class="census-operational-filters" wire:ignore.self><summary>Filtri operativi</summary>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Con proprietario</span><select wire:model.live="filterValues.hasOwner"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Con recapito</span><select wire:model.live="filterValues.hasContact"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Comproprietà</span><select wire:model.live="filterValues.coownership"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Multi proprietario</span><select wire:model.live="filterValues.multiOwner"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Mai contattato</span><select wire:model.live="filterValues.never"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Con attività futura</span><select wire:model.live="filterValues.upcoming"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        @if ($view === 'Proprietari')<label class="crm-field"><span>Numero di immobili</span><select wire:model.live="filterValues.count"><option value="">Tutti</option><option value="0">0</option><option value="1">1</option><option>Più di uno</option></select></label>@endif
                    </div>
                    <p class="crm-muted">Multi proprietario: più di 2 unità distinte, sommate su tutti i Comuni a cui hai accesso. Comproprietà: più proprietari della stessa unità.</p>
                </details>
            </details>
        @endif
        <div class="crm-toolbar"><button type="button" class="crm-link" wire:click="resetFilters">Azzera filtri</button></div>
    </section>

    @if ($this->archiveReady)
        <div class="crm-toolbar">
            <label class="crm-field"><span>Mostra prima</span><select wire:model.live="order"><option value="Da contattare">Mai contattati, poi attività meno recenti</option><option value="Ultima attività">Attività più recenti prima</option><option value="Esito">Esito scelto prima</option></select></label>
            @if ($order === 'Esito')<label class="crm-field"><span>Esito da mostrare per primo</span><select wire:model.live="firstOutcome">@foreach (['Nessun esito', ...\App\Gestionale\Census\CensusQuery::outcomes()] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>@endif
        </div>
    @elseif ($municipalityCode !== '' && $mode === 'catastale')
        <p class="crm-info">Per avviare la ricerca catastale inserisci foglio, particella e subalterno, oppure usa la ricerca libera.</p>
    @endif

    <div aria-busy="false" wire:loading.attr="aria-busy" wire:target="{{ $loadingTargets }}">
        <div role="status" aria-live="polite" wire:loading.block wire:target="{{ $loadingTargets }}"><p>Caricamento archivio catastale…</p><div class="crm-skeleton-list" aria-hidden="true">@foreach ([1, 2, 3] as $n)<div class="crm-skeleton-card"></div>@endforeach</div></div>
        @if ($this->archiveReady)
            @if ($view === 'Immobili' && $this->membership->role === 'admin')
                <div class="crm-toolbar">
                    <label class="crm-check"><input type="checkbox" x-on:change="$wire.selectCurrentPage($event.target.checked)" @checked(count($selectedUnitIds) > 0 && collect($this->results['items'])->every(fn ($row) => in_array((int) $row['unitId'], $selectedUnitIds, true)))>Seleziona la pagina</label>
                    <span>{{ count($selectedUnitIds) }} selezionati</span>
                    <button type="button" class="crm-btn secondary" wire:click="openBulkRemoval" @disabled($selectedUnitIds === [])>Elimina questi immobili</button>
                </div>
            @endif
            @include('gestionale.archive.pager', ['total' => $this->results['total'], 'size' => $this->results['size'], 'page' => $page, 'setter' => 'setPage', 'label' => 'Pagine risultati', 'cursor' => true, 'nextCursor' => $this->results['nextCursor']])
            @if ($view === 'Immobili')
                @foreach ($this->results['items'] as $entry)
                    @if (($entry['kind'] ?? 'unit') === 'terrain')
                        @include('gestionale.archive.terrain-record', ['entry' => $entry])
                    @else
                        @include('gestionale.archive.unit-record', ['entry' => $entry, 'mainOwnerId' => null])
                    @endif
                @endforeach
            @else
                @foreach ($this->results['people'] as $person)
                    @php($owner = $person['owner'])
                    <article class="census-person-record" wire:key="census-owner-{{ $owner['id'] }}">
                        <div class="census-person-head">
                            <div>
                                <h3>{{ $owner['name'] }}</h3>
                                @if ($person['multiOwner'])<x-gestionale.crm-pill>{{ $person['count'] }} immobili collegati</x-gestionale.crm-pill>@endif
                                <small>P. Iva / Codice Fiscale: {{ $owner['pid'] ?: 'non indicato' }}</small>
                                <x-gestionale.census-contact-editor :owner-id="$owner['id']" :text="\App\Gestionale\Census\CensusReader::contactText($owner)" :editing-id="$cardContactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" />
                                @if (($owner['birthDetails'] ?? '') !== '')<small>{{ $owner['birthDetails'] }}</small>@endif
                            </div>
                            <button type="button" class="crm-btn secondary" aria-expanded="{{ $openOwnerId === $owner['id'] ? 'true' : 'false' }}" wire:click="showOwnerProperties({{ $owner['id'] }})">{{ $openOwnerId === $owner['id'] ? 'Chiudi immobili' : 'Mostra immobili ('.$person['count'].')' }}</button>
                            <button type="button" class="crm-btn secondary" wire:click="openOwnerHistory({{ $owner['id'] }})">Storico catastale e recapiti</button>
                            <button type="button" class="crm-btn secondary" wire:click="openProposal(null, {{ $owner['id'] }}, @js($owner['name']), @js($owner['pid']))">Segnala una correzione</button>
                            @if ($this->membership->role === 'admin')<details class="census-actions-menu"><summary aria-label="Anagrafica di {{ $owner['name'] }}">Gestisci anagrafica</summary><button type="button" wire:click="openOwnerEditor({{ $owner['id'] }})">Modifica anagrafica</button><button type="button" wire:click="openOwnerRemoval({{ $owner['id'] }})">Elimina proprietario</button></details>@endif
                        </div>
                        <x-gestionale.record-activities :context="['owner_contact_id' => $owner['id']]" compact hide-summary :person-actions="true" :menu-label="'Azioni per '.$owner['name']" />
                        @if ($openOwnerId === $owner['id'])
                            <div class="census-owner-units">
                                @foreach ($this->ownerUnits['items'] as $unit)
                                    <div class="{{ in_array($unit['unitId'], $person['matching'] ?? [], true) ? 'census-matching-unit' : '' }}" wire:key="owner-unit-{{ $owner['id'] }}-{{ $unit['unitId'] }}">
                                        @if (in_array($unit['unitId'], $person['matching'] ?? [], true))<small class="census-match-label">Corrisponde ai filtri</small>@endif
                                        @include('gestionale.archive.unit-record', ['entry' => $unit, 'mainOwnerId' => $owner['id']])
                                    </div>
                                @endforeach
                                @if ($this->ownerUnits['total'] > 0)
                                    @include('gestionale.archive.pager', ['total' => $this->ownerUnits['total'], 'size' => $this->ownerUnits['size'], 'page' => $this->ownerUnits['page'], 'setter' => 'setOwnerUnitsPage', 'label' => 'Pagine immobili del proprietario'])
                                @else
                                    <p class="crm-muted">Nessuna unità visibile per questo proprietario.</p>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            @endif
            @if ($this->results['total'] === 0)<div class="crm-empty">Nessun risultato con questi filtri.</div>@endif
            @include('gestionale.archive.pager', ['total' => $this->results['total'], 'size' => $this->results['size'], 'page' => $page, 'setter' => 'setPage', 'label' => 'Pagine risultati', 'cursor' => true, 'nextCursor' => $this->results['nextCursor']])
        @endif
    </div>

    @if ($bulkRemovalOpen)
        <x-gestionale.crm-dialog :title="'Rimuovi '.count($selectedUnitIds).' immobili'" label="Archivio catastale" :close="'$wire.closeBulkRemoval()'">
            <form class="crm-form" wire:submit="removeSelected">
                <p>L’operazione li toglie dall’elenco attivo e conserva unità, intestatari, attività e cronologia. Gli immobili di portafoglio collegati verranno segnalati per una verifica catastale.</p>
                <label class="crm-field"><span>Motivo</span><textarea wire:model="bulkRemovalReason" maxlength="4000" required></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Conferma rimozione logica</button><button type="button" class="crm-btn secondary" wire:click="closeBulkRemoval">Annulla</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($outcomeOpen)
        @php($ctx = $this->outcomeContext)
        <x-gestionale.crm-dialog title="Registra contatto" label="Archivio catastale" :close="'$wire.closeOutcome()'">
            <form class="crm-form" wire:submit="submitOutcome">
                <label class="crm-field"><span>Proprietario</span><select wire:model.live="outcomeOwnerId" required><option value="">Scegli il proprietario</option>@foreach ($ctx['owners'] as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach</select></label>
                @if ($ctx['owners'] === [])<p class="crm-muted">Nessun proprietario attuale collegato all’unità.</p>@endif
                <label class="crm-field"><span>Vendita o affitto</span><select wire:model.live="outcomeOperation" required><option value="">Scegli</option>@foreach (\App\Gestionale\Activities\ActivityCatalog::CONTACT_OPERATIONS as $op)<option>{{ $op }}</option>@endforeach</select></label>
                <label class="crm-field"><span>Esito</span><select wire:model="outcomeValue" required @disabled($outcomeOperation === '')><option value="">Scegli un esito</option>@if ($outcomeOperation !== '')@foreach (\App\Gestionale\Activities\ActivityCatalog::outcomesForContact($outcomeOperation) as $value)@continue(\App\Gestionale\Activities\ActivityCatalog::suspendedAcquisition(['outcome' => $value]))<option>{{ $value }}</option>@endforeach @endif</select></label>
                @if ($ctx['pending'] !== [])
                    <fieldset class="crm-field"><legend>Attività già da completare oggi</legend>
                        @foreach ($ctx['pending'] as $p)<label class="crm-check"><input type="radio" wire:model.live="outcomeExistingId" value="{{ $p['id'] }}" @disabled(! $p['selectable'])>{{ $p['label'] }}</label>@endforeach
                        <label class="crm-check"><input type="checkbox" wire:model="outcomeConfirmNew">Registra comunque un nuovo contatto</label>
                    </fieldset>
                @endif
                <label class="crm-field"><span>Nota · facoltativa</span><textarea wire:model="outcomeNote" maxlength="4000"></textarea></label>
                <label class="crm-check"><input type="checkbox" wire:model.live="outcomeSchedule">Pianifica il prossimo impegno</label>
                @if ($outcomeSchedule)
                    <div class="crm-form-grid"><label class="crm-field"><span>Giorno e ora</span><input type="datetime-local" wire:model="outcomeNextAt" required></label></div>
                    <label class="crm-field"><span>Motivo del prossimo impegno</span><textarea wire:model="outcomeNextReason" maxlength="4000" required></textarea></label>
                @endif
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Registra</button><button type="button" class="crm-btn secondary" wire:click="closeOutcome">Annulla</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($proposalOpen)
        <x-gestionale.crm-dialog title="Proponi correzione" label="Archivio catastale" :close="'$wire.closeProposal()'">
            <form class="crm-form" wire:submit="submitProposal">
                @if ($proposalUnitId)<p>La proposta sarà collegata all’unità catastale selezionata.</p>@endif
                <label class="crm-field"><span>Nominativo / denominazione{{ $proposalNewOwner ? '' : ' · facoltativo' }}</span><input wire:model="proposalName" maxlength="255" @required($proposalNewOwner)></label>
                <label class="crm-field"><span>Codice fiscale / P. IVA · facoltativo</span><input wire:model="proposalPid" maxlength="32"></label>
                <label class="crm-field"><span>Dati da verificare</span><textarea wire:model="proposalNote" maxlength="4000" required placeholder="Descrivi cosa va controllato o corretto."></textarea></label>
                <p class="crm-muted">L’Amministratore registrerà l’esito della verifica. L’approvazione non modifica automaticamente intestazioni, quote o dati catastali.</p>
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Invia proposta</button><button type="button" class="crm-btn secondary" wire:click="closeProposal">Annulla</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($censusRemovalOpen)
        @php($removal = $this->censusRemovalSummary)
        <x-gestionale.crm-dialog title="Elimina proprietario" label="Archivio catastale" :close="'$wire.closeCensusRemoval()'">
            <form class="crm-form" wire:submit="confirmCensusRemoval">
                <p><strong>{{ $removal['name'] }}</strong></p>
                <p>{{ $removal['links'] }} collegamenti catastali · {{ $removal['portfolio'] }} immobili a portafoglio · {{ $removal['activities'] }} attività nello storico</p>
                <p class="crm-muted">L’operazione è logica: attività, vecchie intestazioni e documenti restano conservati. Rimuovere un’associazione non elimina il proprietario.</p>
                <label class="crm-field"><span>Motivo (facoltativo)</span><textarea wire:model="censusRemovalReason" maxlength="4000"></textarea></label>
                <label class="crm-check"><input type="checkbox" wire:model="censusRemovalConfirmed" required>Confermo la variazione sulla scheda indicata</label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Conferma variazione</button><button type="button" class="crm-btn secondary" wire:click="closeCensusRemoval">Annulla</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($ownerEditorOpen)
        <x-gestionale.crm-dialog title="Modifica anagrafica proprietario" label="REKO Gestionale · Amministratore" wide :close="'$wire.closeOwnerEditor()'">
            <form class="crm-form" wire:submit="saveOwner">
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Dati personali / denominazione</span><input wire:model="ownerEditName" required pattern=".*[A-Za-zÀ-ÖØ-öø-ÿ].*" title="Inserisci un nome o una denominazione, non solo numeri o simboli" maxlength="255"></label>
                    <label class="crm-field"><span>Codice fiscale / identificativo</span><input wire:model="ownerEditPid" required maxlength="32"></label>
                </div>
                <details><summary>Nome, cognome o ragione sociale separati</summary><p>Compila solo i campi verificati. Il nominativo acquisito non viene separato automaticamente; la denominazione visibile resta quella indicata sopra.</p>
                    <div class="crm-form-grid"><label class="crm-field"><span>Nome</span><input wire:model="ownerEditFirstName" maxlength="120"></label><label class="crm-field"><span>Cognome</span><input wire:model="ownerEditLastName" maxlength="120"></label><label class="crm-field"><span>Ragione sociale</span><input wire:model="ownerEditCompanyName" maxlength="255"></label></div>
                </details>
                <x-gestionale.crm-tag-input model="ownerEditTags" :value="$ownerEditTags" label="Tag del proprietario" :live="false" />
                <label class="crm-field"><span>Note</span><textarea wire:model="ownerEditNotes" maxlength="10000"></textarea></label>
                <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="ownerEditReason" required></textarea></label>
                <div class="crm-form-footer">
                    @if ($notice !== '')<p class="crm-error" role="alert">{{ $notice }}</p>@endif
                    <button class="crm-btn" type="submit" wire:loading.attr="disabled" wire:target="saveOwner"><span wire:loading.remove wire:target="saveOwner">Salva anagrafica</span><span wire:loading wire:target="saveOwner">Salvataggio…</span></button>
                </div>
            </form>
            <x-gestionale.census-contact-editor :owner-id="$editingOwnerId" :text="$this->editingOwnerContact" scope="-dialog" :editing-id="$contactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" />
        </x-gestionale.crm-dialog>
    @endif

    @if ($cadastralEditorOpen)
        <x-gestionale.crm-dialog title="Correggi dati catastali" label="Archivio catastale" :close="'$wire.closeCadastralEditor()'">
            <form class="crm-form" wire:submit="saveCadastral">
                <div class="crm-form-grid"><label class="crm-field"><span>Indirizzo</span><input wire:model="cadastralAddress" maxlength="1000"></label><label class="crm-field"><span>Categoria</span><input wire:model="cadastralCategory" maxlength="20" placeholder="A/2" required></label><label class="crm-field"><span>Piano</span><input wire:model="cadastralFloor" maxlength="100"></label><label class="crm-field"><span>Zona censuaria</span><input wire:model="cadastralZone" maxlength="40"></label><label class="crm-field"><span>Classe catastale</span><input wire:model="cadastralClass" maxlength="40"></label><label class="crm-field"><span>Consistenza</span><input wire:model="cadastralConsistency" maxlength="60"></label><label class="crm-field"><span>Rendita (€)</span><input type="number" min="0" step="0.01" wire:model="cadastralIncome"></label><label class="crm-field"><span>Ultima verifica</span><input type="date" wire:model="cadastralLastVerified"></label></div>
                <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="cadastralReason" maxlength="4000" required></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Salva correzione</button><button type="button" class="crm-btn secondary" wire:click="closeCadastralEditor">Annulla</button></div>
            </form>
        </x-gestionale.crm-dialog>
    @endif

    @if ($sisterImportOpen)
        @include('gestionale.archive.sister-import')
    @endif

    @if ($historyUnitId !== null && $this->outcomeHistory)
        @php($historyEntry = $this->outcomeHistory['entry'])
        @php($historyRows = $this->outcomeHistory['rows'])
        <x-gestionale.crm-dialog title="Storico attività" label="Archivio catastale" wide :close="'$wire.closeOutcomeHistory()'">
            <div class="crm-form">
                <p>{{ $historyEntry['context']['municipality'] }} · F. {{ $historyEntry['context']['sheet'] }} · P. {{ $historyEntry['context']['parcel'] }} · Sub. {{ $historyEntry['unit']['sub'] ?: '—' }}</p>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Esito</span><select wire:model.live="historyOutcomeFilter"><option value="">Tutti</option>@foreach (collect($historyRows)->pluck('outcome')->unique()->sort()->values() as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
                    <label class="crm-field"><span>Tipo</span><select wire:model.live="historyTypeFilter"><option value="">Tutti</option>@foreach (collect($historyRows)->pluck('type')->unique()->sort()->values() as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
                    <label class="crm-field"><span>Operatore</span><select wire:model.live="historyAuthorFilter"><option value="">Tutti</option>@foreach (collect($historyRows)->unique('authorId')->sortBy('author')->values() as $row)<option value="{{ $row['authorId'] }}">{{ $row['author'] }}</option>@endforeach</select></label>
                    <label class="crm-field"><span>Dal</span><input type="date" wire:model.live="historyFrom"></label>
                    <label class="crm-field"><span>Al</span><input type="date" wire:model.live="historyTo"></label>
                </div>
                @forelse ($this->filteredOutcomeHistory as $history)
                    <article class="census-history-row">
                        <div class="crm-between"><strong>{{ $history['outcome'] }}</strong>@if ($history['contactOperation'])<span>Sentito per {{ mb_strtolower($history['contactOperation']) }}</span>@endif<time>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($history['at'], true) }}</time></div>
                        <p>{{ $history['type'] }} · {{ $history['author'] }} · {{ $history['owner'] }}</p>
                        <small>Comune {{ $historyEntry['context']['municipality'] }} · F. {{ $historyEntry['context']['sheet'] }} · P. {{ $historyEntry['context']['parcel'] }} · Sub. {{ $historyEntry['unit']['sub'] ?: '—' }}</small>
                        @if ($history['note'] !== '')<p>{{ $history['note'] }}</p>@endif
                        @if ($history['next'] !== '')<p>Prossimo contatto: {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($history['next'], true) }}</p>@endif
                        @if ($history['corrected'])<x-gestionale.crm-pill>Corretto con motivazione</x-gestionale.crm-pill>@endif
                        @if ($this->membership->role === 'admin')<button type="button" class="crm-link" wire:click="correctOutcomeFromHistory({{ $history['id'] }})">Correggi esito con motivazione</button>@endif
                    </article>
                @empty
                    <div class="crm-empty">Nessun riscontro confermato con questi filtri.</div>
                @endforelse
                @if ($historyEntry['holdings'] !== [])
                    <details class="census-history-row"><summary>Intestazioni attuali e precedenti · {{ count($historyEntry['holdings']) }}</summary>
                        @foreach ($historyEntry['holdings'] as $holding)
                            <article><p><strong>{{ $holding['owner'] }}</strong> · {{ $holding['kind'] ?: ($holding['current'] ? 'Intestazione attuale' : 'Variazione catastale') }}</p><p>{{ $holding['holding']['right'] ?? 'Diritto non indicato' }} {{ $holding['holding']['fraction'] ?? '' }} · {{ $holding['current'] ? 'Attuale' : 'Precedente' }} · {{ $holding['start'] ?: 'Inizio non noto' }} → {{ $holding['end'] ?: 'in corso' }}</p><small>{{ $holding['source'] ?: 'Fonte non indicata' }} · {{ $holding['author'] }}</small>@if ($holding['reason'] !== '')<p>{{ $holding['reason'] }}</p>@endif @if ($holding['exactDateUnknown'])<small>Data esatta della variazione da verificare.</small>@endif</article>
                        @endforeach
                    </details>
                @endif
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeOutcomeHistory">Chiudi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif
    @if ($historyOwnerId !== null && $this->ownerHistory)
        @php($owner = collect($this->results['people'])->firstWhere('owner.id', $historyOwnerId)['owner'] ?? null)
        <x-gestionale.crm-dialog title="Storico catastale e recapiti" label="Archivio catastale" wide :close="'$wire.closeOwnerHistory()'">
            <div class="crm-form">
                <section>
                    <h3>Storico catastale</h3>
                    @forelse ($this->ownerHistory['holdings'] as $holding)
                        <article class="census-history-row">
                            <div class="crm-between"><strong>{{ $holding['kind'] ?: ($holding['current'] ? 'Intestazione attuale' : 'Variazione catastale') }}</strong><time>{{ $holding['start'] ?: 'Inizio non noto' }} → {{ $holding['end'] ?: 'in corso' }}</time></div>
                            <p>{{ $holding['reference'] }} · {{ $holding['holding']['right'] ?? 'Diritto non indicato' }} {{ $holding['holding']['fraction'] ?? '' }} · {{ $holding['current'] ? 'Attuale' : 'Precedente' }}</p>
                            <small>{{ $holding['owner'] }} · {{ $holding['source'] ?: 'Fonte non indicata' }} · {{ $holding['author'] }}</small>
                            @if ($holding['reason'] !== '')<p>{{ $holding['reason'] }}</p>@endif
                            @if ($holding['rawText'] !== '')<small>{{ $holding['rawText'] }}</small>@endif
                            @if ($holding['exactDateUnknown'])<small>Data esatta della variazione da verificare.</small>@endif
                        </article>
                    @empty
                        <p class="crm-muted">Nessuna variazione catastale registrata.</p>
                    @endforelse
                </section>
                <section>
                    <h3>Esiti dei contatti</h3>
                    @forelse ($this->ownerHistory['outcomes'] as $outcome)
                        <article class="census-history-row">
                            <div class="crm-between"><strong>{{ $outcome['outcome'] }}</strong><time>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($outcome['at'], true) }}</time></div>
                            <p>{{ $outcome['type'] }} · {{ $outcome['author'] }} · {{ $outcome['owner'] }}</p>
                            @if ($outcome['contactOperation'])<small>Sentito per {{ mb_strtolower($outcome['contactOperation']) }}</small>@endif
                            @if ($outcome['note'] !== '')<p>{{ $outcome['note'] }}</p>@endif
                        </article>
                    @empty
                        <p class="crm-muted">Nessun esito confermato.</p>
                    @endforelse
                </section>
                <section>
                    <h3>Variazioni dei recapiti</h3>
                    @forelse ($this->ownerHistory['contacts'] as $change)
                        <article class="census-history-row">
                            <div class="crm-between"><strong>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($change['at'] ?? null, true) }}</strong><small>{{ $change['author'] }}</small></div>
                            <small>Prima</small><p class="census-contact-text">{{ ($change['before'] ?? '') ?: 'Recapito assente' }}</p>
                            <small>Dopo</small><p class="census-contact-text">{{ ($change['after'] ?? '') ?: 'Recapito assente' }}</p>
                        </article>
                    @empty
                        <p class="crm-muted">Nessuna variazione dei recapiti registrata.</p>
                    @endforelse
                </section>
                <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeOwnerHistory">Chiudi</button></div>
            </div>
        </x-gestionale.crm-dialog>
    @endif
</div>
