<?php

use App\Gestionale\Actions\Census\RemoveCensusUnits;
use App\Gestionale\Actions\Census\AssociateCensusOwner;
use App\Gestionale\Actions\Census\RemoveCensusOwnership;
use App\Gestionale\Actions\Census\SaveCensusProposal;
use App\Gestionale\Actions\Census\SaveCadastral;
use App\Gestionale\Actions\Census\SaveLocation;
use App\Gestionale\Actions\Census\SaveOwner;
use App\Gestionale\Actions\Census\SaveOwnerContact;
use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityContext;
use App\Gestionale\Census\CensusQuery;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\Census\CensusImporter;
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
    public int $page = 1;
    /** @var list<int> */
    public array $selectedUnitIds = [];
    public bool $bulkRemovalOpen = false;
    public string $bulkRemovalReason = '';
    public string $notice = '';
    public ?int $openOwnerId = null;
    public int $ownerUnitsPage = 1;
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
    public ?int $locationUnitId = null;
    public bool $locationEditorOpen = false;
    public string $locationStreet = '';
    public string $locationCivic = '';
    public string $locationLocality = '';
    public string $locationReason = '';
    public ?int $associateUnitId = null;
    public bool $associationOpen = false;
    public string $ownerSearch = '';
    public ?int $selectedOwnerId = null;
    public string $associationRight = '';
    public string $associationFraction = '';
    public string $associationSource = '';
    public string $associationDate = '';
    public string $associationNote = '';
    public bool $censusRemovalOpen = false;
    public string $censusRemovalMode = '';
    public ?int $censusRemovalUnitId = null;
    public ?int $censusRemovalOwnerId = null;
    public string $censusRemovalReason = '';
    public string $censusRemovalDate = '';
    public bool $censusRemovalCorrection = false;
    public bool $censusRemovalConfirmed = false;
    public ?int $editingOwnerId = null;
    public bool $ownerEditorOpen = false;
    public string $ownerEditName = '';
    public string $ownerEditPid = '';
    public string $ownerEditFirstName = '';
    public string $ownerEditLastName = '';
    public string $ownerEditCompanyName = '';
    public string $ownerEditNotes = '';
    public string $ownerEditTags = '';
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
        if ($request->boolean('importSister') && $this->membership->allows('sister.import')) {
            $this->openSisterImport();
        }
    }

    public function openSisterImport(?int $targetUnitId = null): void
    {
        abort_unless($this->membership->allows('sister.import'), 403);
        $this->resetSisterImport();
        if ($targetUnitId !== null) {
            abort_unless(CensusScope::unitVisible($this->membership, $targetUnitId), 403);
            $entry = (new CensusReader($this->membership))->entries(['unitId' => $targetUnitId])->first() ?? abort(404);
            $this->sisterTargetUnitId = (string) $targetUnitId;
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

    public function openImportedSisterResults(): void
    {
        abort_unless($this->membership->allows('sister.import'), 403);
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
    public function updatedSisterProvince(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterMunicipality(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterCode(): void { $this->invalidateSisterPreview(); unset($this->sisterTargetUnits); }
    public function updatedSisterKind(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterSection(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterSituationDate(): void { $this->invalidateSisterPreview(); }
    public function updatedSisterTargetUnitId(): void { $this->invalidateSisterPreview(); }

    #[Computed]
    public function sisterTargetUnits(): array
    {
        abort_unless($this->membership->allows('sister.import'), 403);
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
                'label' => $unit->municipality.' · F. '.$unit->sheet.' · P. '.$unit->number.' · Sub. '.($unit->subalterno ?: '—').($unit->address ? ' · '.$unit->address : ''),
            ])->all();
    }

    public function loadSisterTextFile(string $field): void
    {
        abort_unless($this->membership->allows('sister.import'), 403);
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

    public function previewSisterImport(): void
    {
        $membership = $this->membership;
        abort_unless($membership->allows('sister.import'), 403);
        $sourceText = trim($this->sisterEstatesText."\n".$this->sisterOwnersText);
        if (mb_strlen($sourceText) > 500_000) {
            $this->sisterError = 'Dividi il testo in blocchi entro 500.000 caratteri.';

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
        abort_unless($membership->allows('sister.import'), 403);
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
            'sisterResult', 'sisterOwnerDecisions', 'sisterConfirmNameUpdates', 'sisterSkipInvalid', 'sisterToken', 'sisterError');
        $this->sisterKind = 'Fabbricati';
        unset($this->sisterTargetUnits);
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
        return $this->municipalityCode !== '' && ($this->mode !== 'catastale'
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
        if (! $this->archiveReady || ! in_array($this->municipalityCode, array_column($this->municipalities, 'code'), true)) {
            return $empty;
        }

        $filters = $this->currentFilters();

        return (new CensusQuery($this->membership))->run([
            'view' => $this->view, 'search' => mb_substr($this->search, 0, 300), 'filters' => $filters,
            'page' => max(1, $this->page), 'size' => 20,
            'order' => in_array($this->order, ['Da contattare', 'Ultima attività', 'Esito'], true) ? $this->order : 'Da contattare',
            'firstOutcome' => in_array($this->firstOutcome, CensusQuery::outcomes(), true) ? $this->firstOutcome : 'Interessato',
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

    /** @return array<string,list<string>> */
    private function currentFilters(): array
    {
        $filters = ['municipalityCode' => [$this->municipalityCode], '_mode' => [$this->mode]];
        $modeFields = match ($this->mode) {
            'catastale' => ['sheet' => $this->sheet, 'parcel' => $this->parcel, 'sub' => $this->sub],
            'frazione' => ['locality' => $this->locality],
            'indirizzo' => ['address' => $this->address, 'civic' => $this->civic, 'floor' => $this->floor],
            'proprietario' => ['name' => $this->firstName, 'lastName' => $this->lastName, 'cf' => $this->taxCode],
            default => [],
        };
        foreach ($modeFields as $key => $value) {
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
    public function updatedWithoutSub(): void { $this->resetResults(); }
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
        $this->ownerUnitsPage = 1;
        $this->selectedUnitIds = [];
        $this->openOwnerId = null;
        unset($this->results, $this->ownerUnits);
    }

    private function resetPage(): void
    {
        $this->page = 1;
        unset($this->results);
    }

    public function setPage(int $page): void
    {
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

    public function showOwnerProperties(int $ownerId): void
    {
        abort_unless(CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->openOwnerId = $this->openOwnerId === $ownerId ? null : $ownerId;
        $this->ownerUnitsPage = 1;
        unset($this->ownerUnits);
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
        unset($this->results, $this->ownerUnits);
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

    public function openLocationEditor(int $unitId): void
    {
        abort_unless($this->membership->role === 'admin' && CensusScope::unitVisible($this->membership, $unitId), 403);
        $entry = (new CensusReader($this->membership))->entries(['unitId' => $unitId])->first() ?? abort(404);
        $this->locationUnitId = $unitId;
        $this->locationStreet = $entry['street'];
        $this->locationCivic = $entry['civic'];
        $this->locationLocality = $entry['locality'];
        $this->locationReason = '';
        $this->notice = '';
        $this->locationEditorOpen = true;
    }

    public function saveLocation(): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        if ($this->locationUnitId === null) {
            abort(404);
        }
        try {
            app(SaveLocation::class)->handle($this->membership, $this->locationUnitId, [
                'street' => $this->locationStreet,
                'civic' => $this->locationCivic,
                'locality' => $this->locationLocality,
                'reason' => $this->locationReason,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->locationEditorOpen = false;
        $this->locationUnitId = null;
        $this->notice = 'Indirizzo aggiornato; testo originale conservato.';
        unset($this->results);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function closeLocationEditor(): void
    {
        $this->locationEditorOpen = false;
        $this->locationUnitId = null;
        $this->locationReason = '';
    }

    #[Computed]
    public function ownerOptions(): array
    {
        if (mb_strlen(trim($this->ownerSearch)) < 2) {
            return [];
        }
        $term = trim($this->ownerSearch);
        $owners = Contact::query()->where('agency_id', $this->membership->agency_id)->whereNull('removed_at')
            ->where(fn ($q) => $q->where('display_name', 'ilike', '%'.$term.'%')->orWhere('tax_code', 'ilike', '%'.$term.'%')->orWhere('vat_number', 'ilike', '%'.$term.'%')->orWhere('recapito', 'ilike', '%'.$term.'%'))
            ->orderBy('display_name')->limit(40)->get(['id', 'display_name', 'tax_code', 'vat_number']);

        return $owners->filter(fn (Contact $owner) => CensusScope::ownerVisible($this->membership, (int) $owner->id))
            ->take(15)->map(fn (Contact $owner) => ['id' => (int) $owner->id, 'name' => $owner->display_name, 'pid' => $owner->tax_code ?: $owner->vat_number])->values()->all();
    }

    public function updatedOwnerSearch(): void { unset($this->ownerOptions); $this->selectedOwnerId = null; }

    public function openAssociation(int $unitId): void
    {
        abort_unless(CensusScope::unitVisible($this->membership, $unitId), 403);
        $this->associateUnitId = $unitId;
        $this->ownerSearch = '';
        $this->selectedOwnerId = null;
        $this->associationRight = '';
        $this->associationFraction = '';
        $this->associationSource = '';
        $this->associationDate = '';
        $this->associationNote = '';
        $this->associationOpen = true;
    }

    public function closeAssociation(): void
    {
        $this->associationOpen = false;
        $this->associateUnitId = null;
        $this->selectedOwnerId = null;
        $this->ownerSearch = '';
        unset($this->ownerOptions);
    }

    public function proposeNewOwner(): void
    {
        $unitId = $this->associateUnitId;
        $this->closeAssociation();
        $this->openProposal($unitId);
        $this->proposalNewOwner = true;
    }

    public function associateOwner(): void
    {
        if ($this->associateUnitId === null || $this->selectedOwnerId === null) {
            $this->notice = 'Seleziona un proprietario esistente.';

            return;
        }
        try {
            app(AssociateCensusOwner::class)->handle($this->membership, $this->associateUnitId, $this->selectedOwnerId, [
                'right' => $this->associationRight, 'fraction' => $this->associationFraction, 'source' => $this->associationSource,
                'effectiveDate' => $this->associationDate, 'note' => $this->associationNote,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->closeAssociation();
        $this->notice = 'Proprietario associato senza duplicati.';
        unset($this->results, $this->ownerUnits);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function openUnlink(int $unitId, int $ownerId): void
    {
        abort_unless($this->membership->role === 'admin', 403);
        abort_unless(CensusScope::unitVisible($this->membership, $unitId) && CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->censusRemovalMode = 'unlink';
        $this->censusRemovalUnitId = $unitId;
        $this->censusRemovalOwnerId = $ownerId;
        $this->censusRemovalReason = '';
        $this->censusRemovalDate = '';
        $this->censusRemovalCorrection = false;
        $this->censusRemovalConfirmed = false;
        $this->censusRemovalOpen = true;
    }

    public function openOwnerRemoval(int $ownerId): void
    {
        abort_unless($this->membership->role === 'admin' && CensusScope::ownerVisible($this->membership, $ownerId), 403);
        $this->censusRemovalMode = 'owner';
        $this->censusRemovalUnitId = null;
        $this->censusRemovalOwnerId = $ownerId;
        $this->censusRemovalReason = '';
        $this->censusRemovalDate = '';
        $this->censusRemovalCorrection = false;
        $this->censusRemovalConfirmed = false;
        $this->censusRemovalOpen = true;
    }

    public function closeCensusRemoval(): void
    {
        $this->censusRemovalOpen = false;
        $this->censusRemovalMode = '';
        $this->censusRemovalUnitId = null;
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
        $action = app(RemoveCensusOwnership::class);
        try {
            if ($this->censusRemovalMode === 'unlink' && $this->censusRemovalUnitId !== null) {
                $action->unlink($this->membership, $this->censusRemovalUnitId, $this->censusRemovalOwnerId, [
                    'reason' => $this->censusRemovalReason, 'effectiveDate' => $this->censusRemovalDate,
                    'correction' => $this->censusRemovalCorrection, 'confirmed' => $this->censusRemovalConfirmed,
                ]);
            } elseif ($this->censusRemovalMode === 'owner') {
                $action->removeOwner($this->membership, $this->censusRemovalOwnerId, [
                    'reason' => $this->censusRemovalReason, 'confirmed' => $this->censusRemovalConfirmed,
                ]);
            } else {
                abort(422);
            }
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
        $this->ownerEditTags = implode(', ', $owner->tags ?? []);
        $this->ownerEditReason = '';
        $this->ownerEditRevision = $owner->updated_at?->toISOString();
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
                'tags' => array_values(array_filter(array_map('trim', explode(',', $this->ownerEditTags)))),
                'reason' => $this->ownerEditReason, 'expected_updated_at' => $this->ownerEditRevision,
            ]);
        } catch (CommandRejected $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->ownerEditorOpen = false;
        $this->editingOwnerId = null;
        $this->notice = 'Anagrafica proprietario aggiornata.';
        unset($this->results, $this->ownerUnits);
        $this->dispatch('crm-notice', type: 'success', text: $this->notice);
    }

    public function closeOwnerEditor(): void
    {
        $this->ownerEditorOpen = false;
        $this->editingOwnerId = null;
        $this->ownerEditReason = '';
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

<div class="crm-archive-page">
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Archivio catastale</h1>
            <p class="crm-muted">Schede del censimento dell’agenzia, con proprietari e riscontri collegati. Il Comune limita ogni ricerca e i risultati sono paginati.</p>
        </div>
        <div class="crm-actions">
            @can('agency-permission', 'sister.import')
                <button type="button" class="crm-btn" wire:click="openSisterImport"><x-gestionale.lucide name="upload" :size="16" />Importa da SISTER</button>
            @endcan
            @can('agency-permission', 'exports')<a class="crm-btn secondary" href="{{ route('gestionale.archive.export.missing-phone') }}">Esporta CF / P.IVA senza telefono · CSV</a>@endcan
            <a class="crm-btn secondary" href="{{ route('gestionale.scouting.index') }}" wire:navigate><x-gestionale.lucide name="map" :size="16" />Apri mappa e zone</a>
        </div>
    </div>

    @if ($notice !== '')<p class="crm-form-error" role="status">{{ $notice }}</p>@endif

    @if ($this->municipalities === [])
        <section class="crm-panel">
            <h2>Nessuna unità censita</h2>
            <p>In questo database non risultano schede di censimento attive. Le sagome e le unità del catalogo si consultano in Mappa e zone; entrano nell’Archivio quando vengono aggiunte al censimento.</p>
            <a class="crm-btn" href="{{ route('gestionale.scouting.index') }}" wire:navigate>Apri mappa e zone</a>
        </section>
    @else
        <section class="crm-panel census-filters" aria-label="Filtri Archivio catastale">
            <h2>Filtra l’Archivio</h2>
            <div class="crm-form-grid census-primary-filters">
                <label class="crm-field"><span>Comune</span>
                    <span class="census-municipality-picker" x-data="{ open: @entangle('municipalityPickerOpen').live }" x-on:click.outside="open = false; $wire.closeMunicipalityPicker()">
                        <input wire:model.live.debounce.250ms="municipalitySearch" type="search" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="archive-municipality-options" aria-activedescendant="archive-municipality-option-{{ $municipalityOptionIndex }}" aria-expanded="{{ $municipalityPickerOpen ? 'true' : 'false' }}" autocomplete="off" placeholder="Es. Milano, Cuneo…" wire:focus="$set('municipalityPickerOpen', true)" wire:blur="closeMunicipalityPicker" wire:keydown.arrow-down.prevent="moveMunicipalityOption(1)" wire:keydown.arrow-up.prevent="moveMunicipalityOption(-1)" wire:keydown.enter.prevent="chooseMunicipalityOption" wire:keydown.escape="closeMunicipalityPicker">
                        @if ($municipalityPickerOpen)
                            <span class="census-municipality-options" id="archive-municipality-options" role="listbox" aria-label="Comuni presenti nell’archivio">
                                @forelse ($this->municipalityOptions as $index => $municipality)
                                    <button type="button" role="option" aria-selected="{{ $index === $municipalityOptionIndex ? 'true' : 'false' }}" id="archive-municipality-option-{{ $index }}" wire:click="selectMunicipality('{{ $municipality['code'] }}')" x-on:mousedown.prevent>
                                        <span>{{ $municipality['name'] }}</span><small>{{ $municipality['province'] ?: 'Provincia non indicata' }} · {{ $municipality['code'] }}</small>
                                    </button>
                                @empty
                                    <span role="status">{{ trim($municipalitySearch) === '' ? 'Nessun Comune con immobili presenti.' : 'Nessun Comune con questo nome.' }}</span>
                                @endforelse
                            </span>
                        @endif
                    </span>
                </label>
                @if ($municipalityCode !== '')
                    <label class="crm-field"><span>Ricerca libera</span><input type="search" wire:model.live.debounce.300ms="search" maxlength="300" placeholder="Es. Cenisio 13, Rossi oppure 262/73"></label>
                    <label class="crm-field"><span>Esito</span>
                        <select wire:model.live="outcome"><option value="">Tutti</option>@foreach (array_unique([...\App\Gestionale\Census\CensusQuery::outcomes(), ...$this->results['options']['outcome'], $outcome]) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                    </label>
                @endif
            </div>
            @if ($municipalityCode === '')
                <p class="crm-info">Seleziona il Comune per aprire i filtri e le schede dell’archivio.</p>
            @else
                <nav class="crm-tabs" aria-label="Vista archivio">
                    @foreach (['Immobili', 'Proprietari'] as $tab)<button type="button" wire:click="$set('view', '{{ $tab }}')" @if ($view === $tab) aria-current="page" @endif>{{ $tab }}</button>@endforeach
                </nav>
                <details class="crm-archive-advanced-filters">
                    <summary>Filtri avanzati</summary>
                    <div class="crm-tabs" aria-label="Cerca per">
                        @foreach (['catastale' => 'Riferimenti catastali', 'frazione' => 'Frazione', 'indirizzo' => 'Indirizzo', 'proprietario' => 'Proprietario'] as $key => $label)
                            <button type="button" wire:click="$set('mode', '{{ $key }}')" @if ($mode === $key) aria-current="page" @endif>{{ $label }}</button>
                        @endforeach
                    </div>
            @if ($mode === 'catastale')
                <div class="crm-form-grid crm-archive-mode-fields">
                    <label class="crm-field"><span>Foglio</span><input wire:model.live.debounce.250ms="sheet" inputmode="numeric" required></label>
                    <label class="crm-field"><span>Particella</span><input wire:model.live.debounce.250ms="parcel" required></label>
                    @unless ($withoutSub)<label class="crm-field"><span>Subalterno</span><input wire:model.live.debounce.250ms="sub" required></label>@endunless
                    <label class="crm-check"><input type="checkbox" wire:model.live="withoutSub">Senza subalterno nella fonte</label>
                </div>
                <p class="crm-muted">Per una ricerca precisa inserisci foglio, particella e subalterno, oppure indica che il subalterno è assente.</p>
                <details><summary>Mostra dettagli aggiuntivi</summary><div class="crm-form-grid crm-archive-mode-fields"><label class="crm-field"><span>Categoria</span><input wire:model.live.debounce.250ms="filterValues.category" placeholder="Es. A/2"></label></div></details>
            @elseif ($mode === 'frazione')
                <div class="crm-form-grid crm-archive-mode-fields"><label class="crm-field"><span>Frazione</span><input wire:model.live.debounce.250ms="locality" placeholder="Es. San Rocco Castagnaretta"></label></div>
            @elseif ($mode === 'indirizzo')
                <div class="crm-form-grid crm-archive-mode-fields">
                    <label class="crm-field"><span>Via</span><input wire:model.live.debounce.250ms="address" placeholder="Nome della via"></label>
                    <label class="crm-field"><span>Civico</span><input wire:model.live.debounce.250ms="civic"></label>
                    <label class="crm-field"><span>Piano</span><input wire:model.live.debounce.250ms="floor"></label>
                </div>
            @else
                <div class="crm-form-grid crm-archive-mode-fields">
                    <label class="crm-field"><span>Nome o ragione sociale</span><input wire:model.live.debounce.250ms="firstName"></label>
                    <label class="crm-field"><span>Cognome</span><input wire:model.live.debounce.250ms="lastName"></label>
                    <label class="crm-field"><span>Codice fiscale / P. IVA</span><input wire:model.live.debounce.250ms="taxCode"></label>
                </div>
            @endif
                </details>
                <details class="census-operational-filters"><summary>Filtri operativi</summary>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Con proprietario</span><select wire:model.live="filterValues.hasOwner"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Con recapito</span><select wire:model.live="filterValues.hasContact"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Comproprietà</span><select wire:model.live="filterValues.coownership"><option value="">Tutte</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Multi proprietario</span><select wire:model.live="filterValues.multiOwner"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Mai contattato</span><select wire:model.live="filterValues.never"><option value="">Tutti</option><option>Sì</option><option>No</option></select></label>
                        <label class="crm-field"><span>Con attività futura</span><select wire:model.live="filterValues.upcoming"><option value="">Tutte</option><option>Sì</option><option>No</option></select></label>
                        @if ($view === 'Proprietari')<label class="crm-field"><span>Numero di immobili</span><select wire:model.live="filterValues.count"><option value="">Tutti</option><option value="0">0</option><option value="1">1</option><option>Più di uno</option></select></label>@endif
                    </div>
                    <p class="crm-muted">Multi proprietario: più di due unità distinte nei Comuni a cui hai accesso. Comproprietà: più proprietari collegati alla stessa unità.</p>
                </details>
            @if ($this->archiveReady)
                <div class="crm-toolbar">
                    <label class="crm-field"><span>Mostra prima</span><select wire:model.live="order"><option value="Da contattare">Mai contattati, poi attività meno recenti</option><option value="Ultima attività">Attività più recenti prima</option><option value="Esito">Esito scelto prima</option></select></label>
                    @if ($order === 'Esito')<label class="crm-field"><span>Esito da mostrare per primo</span><select wire:model.live="firstOutcome"><option>Nessun esito</option>@foreach (\App\Gestionale\Census\CensusQuery::outcomes() as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>@endif
                </div>
            @elseif ($mode === 'catastale')
                <p class="crm-info">Per avviare la ricerca catastale inserisci foglio, particella e subalterno, oppure usa la ricerca libera.</p>
            @endif
            <div class="crm-toolbar"><button type="button" class="crm-link" wire:click="resetFilters">Azzera filtri</button></div>
            @endif
        </section>

        @if ($this->archiveReady)
        @if ($view === 'Immobili')
            @if ($this->membership->role === 'admin')
                <div class="crm-toolbar crm-archive-bulk-tools">
                    <label class="crm-check"><input type="checkbox" x-on:change="$wire.selectCurrentPage($event.target.checked)" @checked(count($selectedUnitIds) > 0 && collect($this->results['items'])->every(fn ($row) => in_array((int) $row['unitId'], $selectedUnitIds, true)))>Seleziona la pagina</label>
                    <span>{{ count($selectedUnitIds) }} selezionati</span>
                    <button type="button" class="crm-btn secondary" wire:click="openBulkRemoval" @disabled($selectedUnitIds === [])>Rimuovi selezionati dall’archivio</button>
                </div>
            @endif
            <section class="crm-panel crm-archive-results" aria-label="Risultati immobili">
                <div class="crm-between"><h2>{{ number_format($this->results['total'], 0, ',', '.') }} {{ $this->results['total'] === 1 ? 'immobile' : 'immobili' }} · {{ $this->municipalityName }}</h2></div>
                @forelse ($this->results['items'] as $entry)
                    <article class="census-unit-record" wire:key="census-unit-{{ $entry['unitId'] }}">
                        <div class="census-unit-head">
                            <div><strong>{{ $entry['address'] ?: $entry['parcelAddress'] ?: 'Indirizzo non presente nella fonte' }}</strong><small>{{ $entry['context']['municipality'] }} · {{ $entry['unit']['source'] === 'sister-text' ? 'Censimento Sister' : ($entry['unit']['source'] === 'demo' ? 'Dati di esempio' : 'Archivio catastale') }} @if ($entry['geographyStatus'] === 'missing')· Foglio catastale non caricato @endif</small></div>
                            @if ($this->membership->role === 'admin')<label class="crm-check"><input type="checkbox" aria-label="Seleziona immobile {{ $entry['context']['sheet'] }} · {{ $entry['context']['parcel'] }} · subalterno {{ $entry['unit']['sub'] ?: 'non indicato' }}" wire:model.live="selectedUnitIds" value="{{ $entry['unitId'] }}">Seleziona</label>@endif
                            <details class="census-actions-menu"><summary aria-label="Azioni per {{ $entry['address'] ?: $entry['parcelAddress'] }}">⋯ Azioni</summary>
                                <button type="button" wire:click="openOutcomeHistory({{ $entry['unitId'] }})">Storico catastale e attività</button>
                                <button type="button" wire:click="openProposal({{ $entry['unitId'] }})">Segnala una correzione</button>
                                @can('agency-permission', 'sister.import')<button type="button" wire:click="openSisterImport({{ $entry['unitId'] }})">Importa o aggiorna proprietari</button>@else<button type="button" wire:click="openProposal({{ $entry['unitId'] }})">Richiedi aggiornamento intestazioni</button>@endcan
                                @if ($this->membership->role === 'admin')<button type="button" wire:click="openCadastralEditor({{ $entry['unitId'] }})">Correggi dati catastali</button>@endif
                            </details>
                        </div>
                        <div class="census-extra-facts"><dl class="census-unit-facts">
                            @foreach (['Foglio' => $entry['context']['sheet'], 'Particella' => $entry['context']['parcel'], 'Subalterno' => $entry['unit']['sub'] ?: '—', 'Piano' => $entry['unit']['floor'] ?: '—', 'Categoria' => $entry['unit']['category'] ?: '—', 'Consistenza' => $entry['unit']['consistency'] ?: '—'] as $label => $value)
                                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endforeach
                        </dl></div>
                        <div class="census-unit-body">
                            <section><h4>{{ count($entry['owners']) ? 'Proprietari · '.count($entry['owners']) : 'Proprietario non associato' }}</h4>
                                @forelse ($entry['owners'] as $owner)
                                    <div class="census-owner-in-unit" wire:key="census-owner-{{ $entry['unitId'] }}-{{ $owner['id'] }}">
                                        <strong>{{ $owner['name'] }}</strong><small>{{ $owner['pid'] ?: 'Codice fiscale non indicato' }} · {{ $owner['holding']['right'] ?? 'Diritto non indicato' }} {{ $owner['holding']['fraction'] ?? '' }}</small>
                                        @if (($owner['unitCount'] ?? 0) > \App\Gestionale\Census\CensusReader::SOGLIA_MULTI_PROPRIETARIO)<span class="crm-pill">Multi proprietario · {{ $owner['unitCount'] }} unità</span>@endif
                                        <x-gestionale.census-contact-editor :owner-id="$owner['id']" :text="\App\Gestionale\Census\CensusReader::contactText($owner)" :context-unit-id="$entry['unitId']" :editing-id="$contactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" />
                                        <div class="crm-actions"><button type="button" class="crm-link" wire:click="openProposal(null, {{ $owner['id'] }}, @js($owner['name']), @js($owner['pid']))">Segnala una correzione</button></div>
                                    </div>
                                @empty<p class="crm-muted">Nessun proprietario collegato.</p>@endforelse
                            </section>
                            <section><h4>Ultimo riscontro</h4>
                                @if ($entry['latest'])
                                    <p><strong>{{ $entry['latest']['outcome'] }}</strong><small>{{ $entry['latest']['type'] }} · {{ $entry['latest']['author'] }} · {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($entry['latest']['at'], true) }}</small></p>
                                    @if ($entry['latest']['contactOperation'])<p>Sentito per {{ mb_strtolower($entry['latest']['contactOperation']) }}</p>@endif
                                    @if ($entry['latest']['next'] !== '')<p>Prossimo contatto: {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($entry['latest']['next'], true) }}</p>@endif
                                    @if ($entry['latest']['corrected'])<x-gestionale.crm-pill>Corretto con motivazione</x-gestionale.crm-pill>@endif
                                @else<p class="crm-muted">Nessun riscontro registrato.</p>@endif
                                @if ($entry['upcoming'])<p>Prossimo impegno: {{ $entry['upcoming']['type'] }} · {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($entry['upcoming']['dueAt'], true) }}</p>@endif
                                <x-gestionale.record-activities :context="['parcel_id' => $entry['parcelId'], 'unit_ids' => [$entry['unitId']]]" compact hide-summary :allow-create="\App\Gestionale\Census\CensusQuery::active($entry['unit'])" />
                            </section>
                        </div>
                        @if ($entry['needsReview'])<p class="crm-info">{{ $entry['locationWarning'] }}</p>@endif
                        <details class="census-source"><summary>Dati di provenienza e testo originale</summary><p>Comune {{ $entry['context']['municipality'] }} · {{ $entry['context']['kind'] }} · Fonte {{ $entry['unit']['source'] ?: 'non indicata' }} · Data fonte {{ $entry['unit']['situationDate'] ?: $entry['context']['situationDate'] ?: 'non indicata' }}<br>Indirizzo fonte: {{ $entry['unit']['rawAddress'] ?: 'non indicato' }} · Classe {{ $entry['unit']['cadastralClass'] ?: '—' }} · Rendita {{ $entry['unit']['income'] === null ? 'non indicata' : number_format((float) $entry['unit']['income'], 2, ',', '.').' €' }}</p><pre>{{ $entry['unit']['rawText'] ?: 'Nessun testo importato' }}</pre></details>
                        @if ($entry['holdings'] !== [])
                            <details class="census-history-row"><summary>Storico intestazioni · {{ count($entry['holdings']) }}</summary>
                                @foreach ($entry['holdings'] as $holding)
                                    <article><p><strong>{{ $holding['owner'] }}</strong> · {{ $holding['kind'] ?: ($holding['current'] ? 'Intestazione attuale' : 'Variazione catastale') }}</p><p>{{ $holding['holding']['right'] ?? 'Diritto non indicato' }} {{ $holding['holding']['fraction'] ?? '' }} · {{ $holding['current'] ? 'Attuale' : 'Precedente' }} · {{ $holding['start'] ?: 'Inizio non noto' }} → {{ $holding['end'] ?: 'in corso' }}</p><small>{{ $holding['source'] ?: 'Fonte non indicata' }} · {{ $holding['author'] }}</small>@if ($holding['reason'] !== '')<p>{{ $holding['reason'] }}</p>@endif@if ($holding['exactDateUnknown'])<small>Data esatta della variazione da verificare.</small>@endif</article>
                                @endforeach
                            </details>
                        @endif
                    </article>
                @empty
                    <div class="crm-empty">Nessun immobile trovato con questi criteri.</div>
                @endforelse
            </section>
        @else
            <section class="crm-panel crm-archive-results" aria-label="Risultati proprietari">
                <h2>{{ number_format($this->results['total'], 0, ',', '.') }} {{ $this->results['total'] === 1 ? 'proprietario' : 'proprietari' }} · {{ $this->municipalityName }}</h2>
                @forelse ($this->results['people'] as $person)
                    @php($owner = $person['owner'])
                    <article class="census-person-record" wire:key="census-owner-{{ $owner['id'] }}">
                        <div class="census-person-head"><div><h3>{{ $owner['name'] }}</h3><small>{{ $person['count'] }} {{ $person['count'] === 1 ? 'unità collegata' : 'unità collegate' }}</small><small>Codice fiscale / P. IVA: {{ $owner['pid'] ?: 'non indicato' }}</small><x-gestionale.census-contact-editor :owner-id="$owner['id']" :text="\App\Gestionale\Census\CensusReader::contactText($owner)" :editing-id="$contactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" /></div>
                            <button type="button" class="crm-btn secondary" wire:click="openProposal(null, {{ $owner['id'] }}, @js($owner['name']), @js($owner['pid']))">Segnala una correzione</button>
                            <button type="button" class="crm-btn secondary" wire:click="showOwnerProperties({{ $owner['id'] }})">{{ $openOwnerId === $owner['id'] ? 'Chiudi immobili' : 'Mostra immobili ('.$person['count'].')' }}</button>
                            @if ($this->membership->role === 'admin')<details class="census-actions-menu"><summary>⋯ Azioni</summary><button type="button" wire:click="openOwnerEditor({{ $owner['id'] }})">Gestisci anagrafica</button><button type="button" wire:click="openOwnerRemoval({{ $owner['id'] }})">Rimuovi anagrafica dall’archivio</button></details>@endif
                        </div>
                        <x-gestionale.record-activities :context="['owner_contact_id' => $owner['id']]" compact hide-summary />
                        @if (($owner['contactHistory'] ?? []) !== [])<details class="census-history-row"><summary>Variazioni del recapito · {{ count($owner['contactHistory']) }}</summary>@foreach ($owner['contactHistory'] as $contactChange)<article><p>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($contactChange['at'] ?? null, true) }} · {{ $contactChange['actorId'] ?? 'Operatore precedente' }}</p><small>Prima</small><p class="census-contact-text">{{ $contactChange['before'] ?: 'Recapito assente' }}</p><small>Dopo</small><p class="census-contact-text">{{ $contactChange['after'] ?: 'Recapito assente' }}</p></article>@endforeach</details>@endif
                    </article>
                    @if ($openOwnerId === $owner['id'])
                        <div class="census-owner-units">
                            @forelse ($this->ownerUnits['items'] as $unit)
                                @php($mainOwner = collect($unit['owners'])->firstWhere('id', $owner['id']))
                                <div class="{{ in_array($unit['unitId'], $person['matching'] ?? [], true) ? 'census-matching-unit' : '' }}" wire:key="owner-unit-{{ $owner['id'] }}-{{ $unit['unitId'] }}">
                                    @if (in_array($unit['unitId'], $person['matching'] ?? [], true))<small class="census-match-label">Corrisponde ai filtri</small>@endif
                                    <article class="census-unit-record {{ \App\Gestionale\Census\CensusQuery::active($unit['unit']) ? '' : 'census-inactive' }}" data-census-unit="{{ $unit['parcelId'] }}:{{ $unit['unitId'] }}">
                                        <div class="census-unit-head"><div><strong>{{ $unit['address'] ?: $unit['parcelAddress'] }}</strong><small>{{ $unit['context']['municipality'] }} · {{ $unit['unit']['source'] === 'sister-text' ? 'Censimento Sister' : 'Dati di esempio' }} @if ($unit['geographyStatus'] === 'missing')· Foglio catastale non caricato @endif</small></div>
                                            @if (!\App\Gestionale\Census\CensusQuery::active($unit['unit']))<span class="crm-pill">{{ $unit['unit']['state'] }}</span>@endif
                                            <details class="census-actions-menu"><summary aria-label="Azioni per {{ $unit['address'] ?: $unit['parcelAddress'] }} · foglio {{ $unit['context']['sheet'] }} · particella {{ $unit['context']['parcel'] }} · subalterno {{ $unit['unit']['sub'] ?: 'non indicato' }}">⋯ Azioni</summary>
                                                <button type="button" wire:click="openProposal({{ $unit['unitId'] }})">Segnala una correzione</button>
                                                @can('agency-permission', 'sister.import')<button type="button" wire:click="openSisterImport({{ $unit['unitId'] }})">Importa o aggiorna proprietari</button>@else<button type="button" wire:click="openProposal({{ $unit['unitId'] }})">Richiedi aggiornamento intestazioni</button>@endcan
                                                @if ($this->membership->role === 'admin')<button type="button" wire:click="openCadastralEditor({{ $unit['unitId'] }})">Correggi dati catastali</button>@endif
                                            </details>
                                        </div>
                                        <div class="census-extra-facts"><dl class="census-unit-facts">
                                            @foreach (['Foglio' => $unit['context']['sheet'], 'Particella' => $unit['context']['parcel'], 'Subalterno' => $unit['unit']['sub'] ?: '—', 'Piano' => $unit['unit']['floor'] ?: '—', 'Categoria' => $unit['unit']['category'] ?: '—', 'Consistenza' => $unit['unit']['consistency'] ?: '—'] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
                                        </dl></div>
                                        @if ($mainOwner && $mainOwner['holding'])<p class="crm-muted">{{ $mainOwner['holding']['right'] ?: 'Diritto non indicato' }} · quota {{ $mainOwner['holding']['fraction'] ?: 'non indicata' }}</p>@endif
                                        <div class="census-unit-body">
                                            <section><h4>{{ count($unit['owners']) > 1 ? 'Comproprietari · '.(count($unit['owners']) - 1) : 'Nessun comproprietario' }}</h4>
                                                @foreach ($unit['owners'] as $coOwner)
                                                    @if ($coOwner['id'] !== $owner['id'])<div class="census-owner-in-unit"><strong>{{ $coOwner['name'] }}</strong><small>{{ $coOwner['pid'] ?: 'Codice fiscale non indicato' }} · {{ $coOwner['holding']['right'] ?? 'Diritto non indicato' }} {{ $coOwner['holding']['fraction'] ?? '' }}</small><x-gestionale.census-contact-editor :owner-id="$coOwner['id']" :text="\App\Gestionale\Census\CensusReader::contactText($coOwner)" :context-unit-id="$unit['unitId']" :editing-id="$contactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" /></div>@endif
                                                @endforeach
                                            </section>
                                            <section><h4>Ultimo riscontro</h4>
                                                @if ($unit['latest'])
                                                    <p><strong>{{ $unit['latest']['outcome'] }}</strong><small>{{ $unit['latest']['type'] }} · {{ $unit['latest']['author'] }} · {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($unit['latest']['at'], true) }}</small></p>
                                                    @if ($unit['latest']['contactOperation'])<p>Sentito per {{ mb_strtolower($unit['latest']['contactOperation']) }}</p>@endif
                                                    @if ($unit['latest']['next'] !== '')<p>Prossimo contatto: {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($unit['latest']['next'], true) }}</p>@endif
                                                    @if ($unit['latest']['corrected'])<x-gestionale.crm-pill>Corretto con motivazione</x-gestionale.crm-pill>@endif
                                                @else<p class="crm-muted">Nessun riscontro registrato.</p>@endif
                                                @if ($unit['upcoming'])<p>Prossimo impegno: {{ $unit['upcoming']['type'] }} · {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($unit['upcoming']['dueAt'], true) }}</p>@endif
                                                <x-gestionale.record-activities :context="['parcel_id' => $unit['parcelId'], 'unit_ids' => [$unit['unitId']]]" compact hide-summary :allow-create="\App\Gestionale\Census\CensusQuery::active($unit['unit'])" />
                                            </section>
                                        </div>
                                        @if ($unit['needsReview'])<p class="crm-info">{{ $unit['locationWarning'] }}</p>@endif
                                        @if ($unit['holdings'] !== [])<details class="census-history-row"><summary>Storico intestazioni · {{ count($unit['holdings']) }}</summary>@foreach ($unit['holdings'] as $holding)<article><p><strong>{{ $holding['owner'] }}</strong> · {{ $holding['kind'] ?: ($holding['current'] ? 'Intestazione attuale' : 'Variazione catastale') }}</p><p>{{ $holding['holding']['right'] ?? 'Diritto non indicato' }} {{ $holding['holding']['fraction'] ?? '' }} · {{ $holding['current'] ? 'Attuale' : 'Precedente' }} · {{ $holding['start'] ?: 'Inizio non noto' }} → {{ $holding['end'] ?: 'in corso' }}</p></article>@endforeach</details>@endif
                                        <details class="census-source"><summary>Dati di provenienza e testo originale</summary><p>Catasto {{ $unit['context']['kind'] }} · {{ $unit['context']['code'] }} · Sezione {{ $unit['context']['section'] ?: 'non indicata' }} · Data fonte {{ $unit['unit']['situationDate'] ?: $unit['context']['situationDate'] ?: 'non indicata' }}<br>Classe {{ $unit['unit']['cadastralClass'] ?: '—' }} · Rendita {{ $unit['unit']['income'] === null ? 'non indicata' : number_format((float) $unit['unit']['income'], 2, ',', '.').' €' }}</p><pre>{{ $unit['unit']['rawText'] ?: 'Nessun testo importato' }}</pre></details>
                                    </article>
                                </div>
                            @empty<p class="crm-muted">Nessuna unità visibile per questo proprietario.</p>@endforelse
                            @if ($this->ownerUnits['total'] > 0)
                                @php($ownerUnitsLastPage = max(1, (int) ceil($this->ownerUnits['total'] / $this->ownerUnits['size'])))
                                <nav class="census-pager" aria-label="Pagine immobili del proprietario"><button type="button" class="crm-btn secondary" wire:click="setOwnerUnitsPage({{ $this->ownerUnits['page'] - 1 }})" @disabled($this->ownerUnits['page'] <= 1)>Precedente</button><span>{{ $this->ownerUnits['total'] }} immobili · pagina {{ $this->ownerUnits['page'] }} di {{ $ownerUnitsLastPage }}</span><button type="button" class="crm-btn secondary" wire:click="setOwnerUnitsPage({{ $this->ownerUnits['page'] + 1 }})" @disabled($this->ownerUnits['page'] >= $ownerUnitsLastPage)>Successiva</button></nav>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="crm-empty">Nessun proprietario trovato con questi criteri.</div>
                @endforelse
            </section>
        @endif

        @php($lastPage = max(1, (int) ceil($this->results['total'] / 20)))
        <nav class="census-pager" aria-label="Pagine risultati">
            <button type="button" class="crm-btn secondary" wire:click="setPage({{ $page - 1 }})" @disabled($page <= 1)>Precedente</button>
            <span>{{ $this->results['total'] }} risultati · pagina {{ $page }} di {{ $lastPage }}</span>
            <button type="button" class="crm-btn secondary" wire:click="setPage({{ $page + 1 }})" @disabled($page >= $lastPage)>Successiva</button>
        </nav>
        @endif
    @endif

    @if ($bulkRemovalOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeBulkRemoval()" x-on:click="if ($event.target === $el) $wire.closeBulkRemoval()" class="proto-dialog reko-prototype" aria-labelledby="bulk-census-removal-title">
            <form class="crm-form" wire:submit="removeSelected">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="bulk-census-removal-title">Rimuovi {{ count($selectedUnitIds) }} immobili</h2></div><button type="button" class="crm-dialog-close" wire:click="closeBulkRemoval" aria-label="Chiudi">×</button></div>
                <p>L’operazione li toglie dall’elenco attivo e conserva unità, intestatari, attività e cronologia. Gli immobili di portafoglio collegati verranno segnalati per una verifica catastale.</p>
                <label class="crm-field"><span>Motivo</span><textarea wire:model="bulkRemovalReason" maxlength="4000" required></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Conferma rimozione logica</button><button type="button" class="crm-btn secondary" wire:click="closeBulkRemoval">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($proposalOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeProposal()" x-on:click="if ($event.target === $el) $wire.closeProposal()" class="proto-dialog reko-prototype" aria-labelledby="census-proposal-title">
            <form class="crm-form" wire:submit="submitProposal">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="census-proposal-title">Proponi correzione</h2></div><button type="button" class="crm-dialog-close" wire:click="closeProposal" aria-label="Chiudi">×</button></div>
                @if ($proposalUnitId)<p>La proposta sarà collegata all’unità catastale selezionata.</p>@endif
                <label class="crm-field"><span>Nominativo / denominazione{{ $proposalNewOwner ? '' : ' · facoltativo' }}</span><input wire:model="proposalName" maxlength="255" @required($proposalNewOwner)></label>
                <label class="crm-field"><span>Codice fiscale / P. IVA · facoltativo</span><input wire:model="proposalPid" maxlength="32"></label>
                <label class="crm-field"><span>Dati da verificare</span><textarea wire:model="proposalNote" maxlength="4000" required placeholder="Descrivi cosa va controllato o corretto."></textarea></label>
                <p class="crm-muted">L’Amministratore registrerà l’esito della verifica. L’approvazione non modifica automaticamente intestazioni, quote o dati catastali.</p>
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Invia proposta</button><button type="button" class="crm-btn secondary" wire:click="closeProposal">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($associationOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeAssociation()" x-on:click="if ($event.target === $el) $wire.closeAssociation()" class="proto-dialog reko-prototype" aria-labelledby="census-association-title">
            <form class="crm-form" wire:submit="associateOwner">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="census-association-title">Associa proprietario</h2></div><button type="button" class="crm-dialog-close" wire:click="closeAssociation" aria-label="Chiudi">×</button></div>
                <label class="crm-field"><span>Cerca proprietario esistente</span><input type="search" wire:model.live.debounce.250ms="ownerSearch" maxlength="255" placeholder="Nome, codice fiscale o partita IVA"></label>
                @if (mb_strlen(trim($ownerSearch)) >= 2)
                    <div class="census-link-grid" role="group" aria-label="Proprietari trovati">
                        @forelse ($this->ownerOptions as $ownerOption)
                            <label class="crm-check"><input type="radio" wire:model="selectedOwnerId" value="{{ $ownerOption['id'] }}">{{ $ownerOption['name'] }} · {{ $ownerOption['pid'] ?: 'codice non indicato' }}</label>
                        @empty<p class="crm-muted">Nessun proprietario accessibile trovato.</p>@endforelse
                    </div>
                @else<p class="crm-muted">Inserisci almeno due caratteri per cercare le anagrafiche accessibili.</p>@endif
                <div class="crm-form-grid"><label class="crm-field"><span>Diritto</span><input wire:model="associationRight" maxlength="255" required placeholder="Proprietà, usufrutto…"></label><label class="crm-field"><span>Quota</span><input wire:model="associationFraction" maxlength="20" required placeholder="1/1, 1/2…"></label><label class="crm-field"><span>Fonte</span><input wire:model="associationSource" maxlength="255" required placeholder="Visura, dichiarazione…"></label><label class="crm-field"><span>Data</span><input type="date" wire:model="associationDate"></label></div>
                <label class="crm-field"><span>Nota</span><textarea wire:model="associationNote" maxlength="4000"></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled" @disabled($selectedOwnerId === null)>Associa</button><button type="button" class="crm-btn secondary" wire:click="closeAssociation">Annulla</button><button type="button" class="crm-link" wire:click="proposeNewOwner">Proponi nuova anagrafica</button></div>
            </form>
        </dialog>
    @endif

    @if ($censusRemovalOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeCensusRemoval()" x-on:click="if ($event.target === $el) $wire.closeCensusRemoval()" class="proto-dialog reko-prototype" aria-labelledby="census-removal-title">
            <form class="crm-form" wire:submit="confirmCensusRemoval">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="census-removal-title">{{ $censusRemovalMode === 'unlink' ? 'Rimuovi associazione' : 'Rimuovi proprietario' }}</h2></div><button type="button" class="crm-dialog-close" wire:click="closeCensusRemoval" aria-label="Chiudi">×</button></div>
                <p>L’operazione è logica: nessun dato viene cancellato fisicamente e le variazioni restano nello storico.</p>
                @if ($censusRemovalMode === 'unlink')
                    <label class="crm-field"><span>Data variazione · facoltativa</span><input type="date" wire:model="censusRemovalDate"></label>
                    <label class="crm-check"><input type="checkbox" wire:model="censusRemovalCorrection">Associazione originaria errata</label>
                @endif
                <label class="crm-field"><span>Motivo{{ $censusRemovalMode === 'owner' ? ' · facoltativo' : '' }}</span><textarea wire:model="censusRemovalReason" maxlength="4000" @required($censusRemovalMode === 'unlink')></textarea></label>
                <label class="crm-check"><input type="checkbox" wire:model="censusRemovalConfirmed" required>Confermo la variazione sulla scheda indicata</label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Conferma variazione</button><button type="button" class="crm-btn secondary" wire:click="closeCensusRemoval">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($ownerEditorOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeOwnerEditor()" x-on:click="if ($event.target === $el) $wire.closeOwnerEditor()" class="proto-dialog reko-prototype" aria-labelledby="owner-editor-title">
            <form class="crm-form" wire:submit="saveOwner">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="owner-editor-title">Gestisci anagrafica</h2></div><button type="button" class="crm-dialog-close" wire:click="closeOwnerEditor" aria-label="Chiudi">×</button></div>
                <label class="crm-field"><span>Nominativo / denominazione</span><input wire:model="ownerEditName" maxlength="255" required></label>
                <label class="crm-field"><span>Codice fiscale / P. IVA</span><input wire:model="ownerEditPid" maxlength="32" required></label>
                <div class="crm-form-grid"><label class="crm-field"><span>Nome</span><input wire:model="ownerEditFirstName" maxlength="255"></label><label class="crm-field"><span>Cognome</span><input wire:model="ownerEditLastName" maxlength="255"></label><label class="crm-field"><span>Ragione sociale</span><input wire:model="ownerEditCompanyName" maxlength="255"></label></div>
                <label class="crm-field"><span>Tag separati da virgola</span><input wire:model="ownerEditTags" maxlength="1000"></label>
                <label class="crm-field"><span>Note interne</span><textarea wire:model="ownerEditNotes" maxlength="10000"></textarea></label>
                <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="ownerEditReason" maxlength="4000"></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Salva anagrafica</button><button type="button" class="crm-btn secondary" wire:click="closeOwnerEditor">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($locationEditorOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeLocationEditor()" x-on:click="if ($event.target === $el) $wire.closeLocationEditor()" class="proto-dialog reko-prototype" aria-labelledby="census-location-title">
            <form class="crm-form" wire:submit="saveLocation">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Correzione della scheda</p><h2 id="census-location-title">Indirizzo e frazione</h2></div><button type="button" class="crm-dialog-close" wire:click="closeLocationEditor" aria-label="Chiudi">×</button></div>
                <p>Il testo importato da SISTER resta conservato come dato di provenienza.</p>
                <label class="crm-field"><span>Via</span><input wire:model="locationStreet" maxlength="255" required></label>
                <label class="crm-field"><span>Civico</span><input wire:model="locationCivic" maxlength="30"></label>
                <label class="crm-field"><span>Frazione</span><input wire:model="locationLocality" maxlength="120"></label>
                <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="locationReason" maxlength="4000" required></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Salva indirizzo</button><button type="button" class="crm-btn secondary" wire:click="closeLocationEditor">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($cadastralEditorOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeCadastralEditor()" x-on:click="if ($event.target === $el) $wire.closeCadastralEditor()" class="proto-dialog reko-prototype" aria-labelledby="cadastral-editor-title">
            <form class="crm-form" wire:submit="saveCadastral">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="cadastral-editor-title">Correggi dati catastali</h2></div><button type="button" class="crm-dialog-close" wire:click="closeCadastralEditor" aria-label="Chiudi">×</button></div>
                <div class="crm-form-grid"><label class="crm-field"><span>Indirizzo</span><input wire:model="cadastralAddress" maxlength="1000"></label><label class="crm-field"><span>Categoria</span><input wire:model="cadastralCategory" maxlength="20" placeholder="A/2" required></label><label class="crm-field"><span>Piano</span><input wire:model="cadastralFloor" maxlength="100"></label><label class="crm-field"><span>Zona censuaria</span><input wire:model="cadastralZone" maxlength="40"></label><label class="crm-field"><span>Classe catastale</span><input wire:model="cadastralClass" maxlength="40"></label><label class="crm-field"><span>Consistenza</span><input wire:model="cadastralConsistency" maxlength="60"></label><label class="crm-field"><span>Rendita (€)</span><input type="number" min="0" step="0.01" wire:model="cadastralIncome"></label><label class="crm-field"><span>Ultima verifica</span><input type="date" wire:model="cadastralLastVerified"></label></div>
                <label class="crm-field"><span>Motivo della correzione</span><textarea wire:model="cadastralReason" maxlength="4000" required></textarea></label>
                @if ($notice !== '')<p class="crm-form-error" role="alert">{{ $notice }}</p>@endif
                <div class="crm-actions"><button class="crm-btn" wire:loading.attr="disabled">Salva correzione</button><button type="button" class="crm-btn secondary" wire:click="closeCadastralEditor">Annulla</button></div>
            </form>
        </dialog>
    @endif

    @if ($sisterImportOpen)
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeSisterImport()" x-on:click="if ($event.target === $el) $wire.closeSisterImport()" class="proto-dialog reko-prototype" aria-labelledby="sister-import-title">
            <div class="crm-form">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Archivio catastale</p><h2 id="sister-import-title">Importa da SISTER</h2></div>
                    <button type="button" class="crm-dialog-close" wire:click="closeSisterImport" aria-label="Chiudi">×</button>
                </div>
                @if ($sisterResult)
                    <section class="crm-panel" role="status">
                        <h3>{{ $sisterResult['title'] ?? 'Importazione completata' }}</h3>
                        <p>{{ $sisterResult['detail'] ?? 'Dati catastali salvati.' }}</p>
                        <div class="crm-actions"><button type="button" class="crm-btn" wire:click="openImportedSisterResults">Apri l’Archivio aggiornato</button></div>
                    </section>
                @else
                    <form wire:submit="previewSisterImport">
                        <div class="crm-form-grid">
                            <label class="crm-field"><span>Comune</span><input wire:model="sisterMunicipality" maxlength="150" required></label>
                            <label class="crm-field"><span>Provincia</span><input wire:model="sisterProvince" maxlength="2" placeholder="CN" required></label>
                            <label class="crm-field"><span>Codice catastale</span><input wire:model.live="sisterCode" maxlength="4" placeholder="F205" required></label>
                            <label class="crm-field"><span>Catasto</span><select wire:model="sisterKind"><option>Fabbricati</option><option>Terreni</option></select></label>
                            <label class="crm-field"><span>Sezione · se presente</span><input wire:model="sisterSection" maxlength="12"></label>
                            <label class="crm-field"><span>Data della visura · se non presente nel testo</span><input type="date" wire:model="sisterSituationDate"></label>
                        </div>
                        <section class="crm-panel" aria-label="Dati immobili SISTER">
                            <label class="crm-field"><span>Testo immobili</span><textarea wire:model="sisterEstatesText" rows="6" maxlength="500000" placeholder="Incolla l’elenco immobili mantenendo le colonne: foglio, particella, subalterno e dati catastali."></textarea></label>
                            <div class="crm-actions">
                                <label class="crm-field"><span>Carica testo immobili (.txt, max 1 MB)</span><input type="file" accept=".txt,text/plain" wire:model="sisterEstateFile"></label>
                                <button type="button" class="crm-btn secondary" wire:click="loadSisterTextFile('estates')" wire:loading.attr="disabled" wire:target="sisterEstateFile">Usa file immobili</button>
                            </div>
                            <p class="crm-muted">Le righe soppresse e i beni comuni non censibili vengono scartati. I file RTF non sono supportati.</p>
                        </section>
                        <section class="crm-panel" aria-label="Dati proprietari SISTER">
                            <label class="crm-field"><span>Testo proprietari</span><textarea wire:model="sisterOwnersText" rows="5" maxlength="150000" placeholder="Incolla nominativo, codice fiscale o P. IVA, diritto e quota."></textarea></label>
                            <div class="crm-actions">
                                <label class="crm-field"><span>Carica testo proprietari (.txt, max 1 MB)</span><input type="file" accept=".txt,text/plain" wire:model="sisterOwnerFile"></label>
                                <button type="button" class="crm-btn secondary" wire:click="loadSisterTextFile('owners')" wire:loading.attr="disabled" wire:target="sisterOwnerFile">Usa file proprietari</button>
                            </div>
                            <p class="crm-muted">Per collegare solo intestatari a un’unità già censita, seleziona qui l’unità. Se importi immobili, puoi lasciare vuoto il campo.</p>
                            <label class="crm-field"><span>Unità già censita · facoltativa</span>
                            <select wire:model="sisterTargetUnitId"><option value="">Nessuna unità selezionata</option>@foreach ($this->sisterTargetUnits as $unit)<option value="{{ $unit['id'] }}">{{ $unit['label'] }}</option>@endforeach</select>
                            </label>
                        </section>
                        <p class="crm-muted">Prima di salvare vedrai le unità riconosciute, i proprietari collegati e gli aggiornamenti. Le intestazioni chiuse restano nello storico.</p>
                        @if ($sisterError !== '')<p class="crm-form-error" role="alert">{{ $sisterError }}</p>@endif
                        @error('sisterEstateFile')<p class="crm-form-error" role="alert">{{ $message }}</p>@enderror
                        @error('sisterOwnerFile')<p class="crm-form-error" role="alert">{{ $message }}</p>@enderror

                        @if ($sisterPreview)
                            <section class="crm-panel" aria-label="Anteprima importazione">
                                <h3>{{ $sisterPreview['detected'] }}</h3>
                                <p>{{ $sisterPreview['context']['municipality'] }} · {{ count($sisterPreview['valid']) }} unità · {{ count($sisterPreview['owners']) }} {{ count($sisterPreview['owners']) === 1 ? 'proprietario' : 'proprietari' }}</p>
                                @foreach ($sisterPreview['warnings'] as $warning)<p class="crm-info" role="status">{{ $warning }}</p>@endforeach
                                @foreach ($sisterPreview['unresolvedCommunes'] as $commune)<p class="crm-form-error" role="alert">Comune non riconosciuto: {{ $commune['municipality'] }} ({{ $commune['province'] }}). Controlla provincia e codice catastale prima di importare.</p>@endforeach
                                @if ($sisterPreview['ownerPreview'] !== [])
                                    <div class="crm-table-wrap"><table><thead><tr><th>Nominativo</th><th>CF / P. IVA</th><th>Diritto</th><th>Quota</th><th>Esito</th></tr></thead><tbody>
                                        @foreach ($sisterPreview['ownerPreview'] as $owner)
                                            <tr><td>{{ $owner['name'] }}</td><td>{{ $owner['pid'] }}</td><td>{{ $owner['right'] ?: 'Da indicare per immobile' }}</td><td>{{ $owner['fraction'] ?: 'Vedi immobili' }}</td><td>{{ $owner['outcome'] }}
                                                @if ($owner['outcome'] === 'Nome diverso')
                                                    <small>Nome già salvato: {{ $owner['previousName'] }}</small>
                                                    @if ($this->membership->role === 'admin')<label class="crm-check"><input type="checkbox" wire:model.live="sisterConfirmNameUpdates" value="{{ $owner['pid'] }}">Conferma aggiornamento nome</label>@else<small>Solo il Responsabile può aggiornare il nome.</small>@endif
                                                @endif
                                            </td></tr>
                                        @endforeach
                                    </tbody></table></div>
                                @endif
                                <div class="crm-table-wrap"><table><thead><tr><th>Comune</th><th>Unità</th><th>Categoria</th><th>Esito</th></tr></thead><tbody>
                                    @foreach ($sisterPreview['rows'] as $row)<tr><td>{{ $row['context']['municipality'] }}</td><td>F. {{ $row['context']['sheet'] }} · P. {{ $row['context']['parcel'] }} · Sub. {{ $row['unit']['sub'] ?: '—' }}</td><td>{{ $row['unit']['category'] ?: '—' }}</td><td>{{ $row['status'] }}{{ $row['message'] !== '' ? ' · '.$row['message'] : '' }}</td></tr>@endforeach
                                </tbody></table></div>
                                @if ($sisterPreview['missingOwners'] !== [])
                                    <fieldset class="crm-fieldset"><legend>Intestatari assenti dalla nuova visura</legend>
                                        @foreach ($sisterPreview['missingOwners'] as $owner)
                                            <div><strong>{{ $owner['name'] }} · {{ $owner['pid'] ?: 'CF non presente' }}</strong><p>Non compare più nella visura. Scegli se mantenere o chiudere l’intestazione.</p>
                                                <label class="crm-check"><input type="radio" name="owner-decision-{{ $owner['key'] }}" wire:model.live="sisterOwnerDecisions.{{ $owner['key'] }}" value="maintain">Mantieni l’intestazione attuale</label>
                                                <label class="crm-check"><input type="radio" name="owner-decision-{{ $owner['key'] }}" wire:model.live="sisterOwnerDecisions.{{ $owner['key'] }}" value="close">Chiudi e conserva nello storico</label>
                                            </div>
                                        @endforeach
                                        <small>La chiusura riguarda solo questa unità. La data esatta del trasferimento resta da verificare se non indicata.</small>
                                    </fieldset>
                                @endif
                                @if (collect($sisterPreview['rows'])->contains(fn ($row) => in_array($row['status'], ['Conflitto', 'Incompleto', 'Non riconosciuto'], true)))
                                    <label class="crm-check"><input type="checkbox" wire:model.live="sisterSkipInvalid">Importa solo le righe valide, lasciando invariate quelle escluse.</label>
                                @endif
                            </section>
                        @endif
                        <div class="crm-actions">
                            @if ($sisterPreview)
                                <button type="button" class="crm-btn" wire:click="applySisterImport" wire:loading.attr="disabled" @disabled($sisterPreview['errors'] !== [] || $sisterPreview['unresolvedCommunes'] !== [])>Conferma e salva i collegamenti</button>
                                <button type="submit" class="crm-btn secondary" wire:loading.attr="disabled">Aggiorna anteprima</button>
                            @else
                                <button type="submit" class="crm-btn" wire:loading.attr="disabled">Controlla il testo</button>
                            @endif
                            <button type="button" class="crm-btn secondary" wire:click="closeSisterImport">Annulla</button>
                        </div>
                    </form>
                @endif
            </div>
        </dialog>
    @endif

    @if ($historyUnitId !== null && $this->outcomeHistory)
        @php($historyEntry = $this->outcomeHistory['entry'])
        @php($historyRows = $this->outcomeHistory['rows'])
        <dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })" x-on:cancel.prevent="$wire.closeOutcomeHistory()" x-on:click="if ($event.target === $el) $wire.closeOutcomeHistory()" class="proto-dialog proto-dialog-wide reko-prototype" aria-labelledby="census-history-title">
            <div class="crm-form">
                <div class="proto-dialog-heading"><div><p class="proto-eyebrow">Archivio catastale</p><h2 id="census-history-title">Storico catastale e attività</h2></div><button type="button" class="crm-dialog-close" wire:click="closeOutcomeHistory" aria-label="Chiudi">×</button></div>
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
        </dialog>
    @endif
</div>
