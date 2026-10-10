<?php

namespace App\Gestionale\Census;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Idempotency;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use App\Models\CadastralUnit;
use App\Models\CensusBatch;
use App\Models\Contact;
use App\Models\Municipality;
use App\Models\Ownership;
use App\Models\Parcel;
use App\Trova\CatalogSearch;
use Illuminate\Support\Facades\DB;

/**
 * Importa da SISTER (previewCensusImport / applyCensusImport di census-sister.ts, modalità automatica):
 * l'operatore incolla il testo di immobili e/o intestatari; l'anteprima mostra che cosa succederebbe e
 * "Conferma e salva i collegamenti" lo scrive. Mai doppioni: stessa unità = stessa scheda, stesso CF = stesso
 * proprietario, righe soppresse o non riconosciute non vengono inventate.
 *
 * Differenze dal prototipo (che teneva tutto in memoria): parcels e cadastral_units sono l'identità comune,
 * la scheda dell'agenzia è agency_unit_observations, le intestazioni sono ownerships (chiuse = storico).
 */
final class CensusImporter
{
    public const SUPPRESSED = 'Riga soppressa: scartata automaticamente, nessuna modifica ai dati salvati.';

    private const COMPARE = ['category', 'address', 'cadastralClass', 'consistency', 'floor', 'censusZone', 'income', 'batch'];

    private const BAD = ['Escluso', 'Conflitto', 'Incompleto', 'Non riconosciuto'];

    /** Evita di rileggere dal database la stessa particella o Comune durante un'anteprima. */
    private array $memo = [];

    public function __construct(private readonly Audit $audit, private readonly Idempotency $idempotency) {}

    // ------------------------------------------------------------------ anteprima

    /**
     * previewCensusImport(). Input: sourceText, targetUnitId, context {kind,province,municipality,code,section,situationDate},
     * confirmNameUpdates, ownerDecisions. @return array<string,mixed>
     */
    public function preview(AgencyMembership $actor, array $input): array
    {
        if (! $actor->allows('sister.import')) {
            throw new CommandRejected('Permesso “Importa da Sister” non assegnato.', 403);
        }

        $this->memo = [];
        $in = $this->resolve($actor, $input);
        $context = self::context($in['context'], ! $in['trusted']);
        $sourceDate = SisterText::sourceDate($in['estates']."\n".$in['owners']);
        if ($sourceDate !== '') {
            $context['situationDate'] = $sourceDate;
        }
        if (preg_match('/Sezione\s+urbana\s*:\s*([^\s\t\r\n]+)/iu', $in['estates'], $m)) {
            $context['section'] = strtoupper($m[1]);
        }
        $context = self::context($context, ! $in['trusted']);
        $people = SisterText::owners($in['owners']);
        $rows = [];
        $seen = [];
        $unresolved = [];
        if ($in['mode'] === 'owner' && count($people['owners']) !== 1) {
            $people['errors'][] = 'Questo elenco contiene immobili di un soggetto: incolla anche la sua intestazione. Per più persone, importa separatamente i rispettivi elenchi.';
        }

        foreach (preg_split('/\r\n|\n|\r/', $in['estates']) as $i => $rawText) {
            if (trim($rawText) === '' || preg_match('/^(?:Situazione\s+aggiornata\s+al|Sezione\s+urbana)\b/iu', trim($rawText))) {
                continue;
            }
            $f = SisterText::cells($rawText);
            if (array_filter($f, fn ($v) => ! preg_match('/^:?-+:?$/', $v)) === []) {
                continue;
            }
            if ((array_filter($f, fn ($v) => preg_match('/^Foglio$/iu', $v)) !== [] && array_filter($f, fn ($v) => preg_match('/^Particella$/iu', $v)) !== [])
                || preg_match('/^(?:Elenco (?:degli )?immobili(?: del soggetto)?|Immobili|Proprietà)$/iu', trim($rawText)) || preg_match('/^Euro\b/iu', $f[0] ?? '')) {
                continue;
            }
            $g = fn (int $n) => $f[$n] ?? '';
            $subjectAddress = SisterText::address($g(2));
            $subject = $subjectAddress !== null && self::identifier($g(3)) && self::identifier($g(4));
            [$sheet, $parcel, $sub, $address, $zone, $category, $klass, $consistency, $income, $batch] = [$g(0), $g(1), $g(2), $g(3), $g(4), $g(5), $g(6), $g(7), $g(8), $g(9)];
            $subjectHolding = null;
            if ($subject) {
                [$sheet, $parcel, $sub] = [$g(3), $g(4), $g(5)];
                $address = $subjectAddress['street'].($subjectAddress['number'] !== '' ? ', '.$subjectAddress['number'] : '');
                [$category, $zone, $klass, $consistency, $income, $batch] = [$g(6), '', $g(7), $g(8), $g(9), $g(10)];
                if ($g(1) !== '') {
                    $subjectHolding = Holding::extract($g(1));
                }
            }
            $rowAddress = $subject ? $subjectAddress : SisterText::address($address);
            $rowKind = $subject && preg_match('/^[FT]$/i', $g(0)) ? (strtoupper($g(0)) === 'T' ? 'Terreni' : 'Fabbricati') : $context['kind'];
            $sameMunicipality = $rowAddress === null || (self::norm($rowAddress['city']) === self::norm($context['municipality']) && $rowAddress['province'] === $context['province']);
            $sheetParts = preg_match('/^([A-Z]+)\/(\d+)$/i', $sheet, $sp) ? $sp : null;
            if ($sheetParts) {
                $sheet = $sheetParts[2];
            }
            $matched = $sameMunicipality ? null : $this->municipalityByName($rowAddress['city'], $rowAddress['province']);
            $rowContext = array_merge($matched ?? $context, ['kind' => $rowKind, 'situationDate' => $context['situationDate']], $sheetParts ? ['section' => strtoupper($sheetParts[1])] : []);
            $parcelSuppressed = $sub === '' && array_filter($f, fn ($v) => preg_match('/^particella\s+soppressa$/iu', $v)) !== [];
            $c = array_merge($rowContext, ! $sameMunicipality && ! $matched ? ['municipality' => $rowAddress['city'], 'province' => $rowAddress['province'], 'code' => ''] : [], ['sheet' => $sheet, 'parcel' => $parcel]);
            $classing = SisterText::classing($category);
            $suppressed = $parcelSuppressed || array_filter($f, fn ($v) => preg_match('/^soppress[ao]$/iu', $v)) !== [];
            $value = preg_match('/^([\d.,]+)\s*(.*)$/u', $consistency, $cv) ? $cv : null;
            $rawAddress = $subject ? $g(2) : $address;
            $unit = [
                'sub' => $sub, 'category' => $classing['category'] ?? $category, 'censusZone' => ($classing['censusZone'] ?? '') ?: $zone, 'cadastralClass' => $klass,
                'consistency' => SisterText::formatMeasure($consistency), 'consistencyValue' => $value ? SisterText::italianNumber($value[1]) : null,
                'consistencyUnit' => SisterText::formatMeasure($value[2] ?? ''), 'income' => SisterText::italianNumber($income), 'batch' => $batch,
                'address' => SisterText::normalizeAddress((string) preg_replace('/\s+Piano\b.*$/iu', '', $address)), 'rawAddress' => $rawAddress,
                'floor' => preg_match('/\bPiano\s+(.+)$/iu', $rawAddress, $fl) ? $fl[1] : '', 'source' => 'sister-text', 'rawText' => $rawText,
                'rawClassing' => $category, 'state' => $suppressed ? 'Soppresso' : 'Attivo', 'situationDate' => $rowContext['situationDate'], 'subjectHolding' => $subjectHolding,
            ];
            $knownParcel = $c['code'] !== '' ? $this->agencyParcel($actor, $c) : null;
            $blank = $sub === '' && $knownParcel ? $this->blankUnits($actor, $knownParcel->id) : [];
            $unit['identityDetail'] = $sub === '' && count($blank) === 1 ? (string) $blank[0]->identity_detail : '';
            $key = self::unitKey($c, $sub, $unit['identityDetail']);
            $row = ['line' => $i + 1, 'key' => $key, 'context' => $c, 'unit' => $unit, 'parcelSuppressed' => $parcelSuppressed, 'status' => 'Nuovo', 'message' => '',
                'existingParcelId' => null, 'existingUnitId' => null];

            if (preg_match('/\bBCNC\b|bene\s+comune\s+non\s+censibile/iu', $rawText)) {
                $row['status'] = 'Escluso';
                $row['message'] = 'Bene comune non censibile: scartato automaticamente, nessuna modifica ai dati salvati.';
            } elseif ($suppressed) {
                $row['status'] = 'Escluso';
                $row['message'] = self::SUPPRESSED;
            } elseif (! self::identifier($sheet) || ! self::identifier($parcel) || ($sub !== '' && ! self::identifier($sub))) {
                $row['status'] = 'Non riconosciuto';
                $row['message'] = 'Identificativi catastali non riconosciuti: nessun numero verrà inventato.';
            } elseif (count($blank) > 1) {
                $row['status'] = 'Conflitto';
                $row['message'] = 'Esistono più schede senza subalterno per la stessa particella: verifica le schede già salvate prima di associarle. Nessun doppione creato.';
            } elseif (! $suppressed && trim($category) === '') {
                $row['status'] = 'Incompleto';
                $row['message'] = 'Categoria mancante: riga conservata tra quelle escluse.';
            } elseif (! $sameMunicipality && ! $matched) {
                $row['status'] = 'Conflitto';
                $row['message'] = "Comune {$rowAddress['city']} ({$rowAddress['province']}): conferma codice catastale e sezione in un blocco separato, senza usare quelli della prima riga.";
                if (! array_filter($unresolved, fn ($u) => self::norm($u['municipality']) === self::norm($rowAddress['city']) && $u['province'] === $rowAddress['province'])) {
                    $unresolved[] = ['municipality' => $rowAddress['city'], 'province' => $rowAddress['province']];
                }
            } elseif ($in['mode'] === 'owner' && (! $subject || ! $subjectHolding || ! Holding::valid($subjectHolding))) {
                $row['status'] = 'Conflitto';
                $row['message'] = 'Incolla l’elenco immobili del soggetto dalla colonna Catasto, con diritto e quota per ogni riga: non ereditiamo quelli di un altro immobile.';
            } elseif (count($blank) === 1 && ! self::compatibleBlank($this->unitView($blank[0]), $unit)) {
                $row['status'] = 'Conflitto';
                $row['message'] = 'Stessa chiave senza subalterno, ma categoria, indirizzo o piano discordanti: verifica la scheda esistente. Nessuna copia o sovrascrittura automatica.';
            } else {
                $existing = $knownParcel ? $this->existingUnit($actor, $knownParcel->id, $sub, $blank) : null;
                $row['existingParcelId'] = $knownParcel?->id;
                $row['existingUnitId'] = $existing?->cadastral_unit_id;
                if ($parcelSuppressed) {
                    $already = $knownParcel && $this->parcelSuppressed($actor, $knownParcel->id);
                    $row['status'] = $already ? 'Già soppresso' : 'Soppresso';
                    $row['message'] = 'Soppressione ESPLICITA dell’intera particella: tutte le sue unità saranno archiviate, senza eliminare proprietari o storico.';
                } elseif ($knownParcel && $this->parcelSuppressed($actor, $knownParcel->id)) {
                    $row['status'] = 'Conflitto';
                    $row['message'] = 'Particella soppressa: verifica amministrativa necessaria.';
                } elseif ($existing && $existing->state === 'Eliminato') {
                    $row['status'] = 'Conflitto';
                    $row['message'] = 'Unità eliminata logicamente: verifica amministrativa necessaria prima di ripristinarla.';
                } elseif ($existing && $existing->state === 'Soppresso') {
                    $row['status'] = 'Conflitto';
                    $row['message'] = 'Identificativo soppresso: verifica la visura, non viene riattivato automaticamente.';
                } elseif ($existing) {
                    $row['status'] = self::sameUnit($this->unitView($existing), $unit) ? 'Identico' : 'Aggiornamento';
                }
                if (isset($seen[$key])) {
                    $repeated = &$rows[$seen[$key]];
                    if ($repeated['status'] !== 'Conflitto' && $row['status'] !== 'Conflitto' && self::sameUnit($repeated['unit'], $unit) && $repeated['unit']['state'] === $unit['state']) {
                        if ($repeated['unit']['subjectHolding'] && $unit['subjectHolding']) {
                            $repeated['unit']['subjectHolding'] = Holding::combine($repeated['unit']['subjectHolding'], $unit['subjectHolding']);
                        }
                        $row['status'] = 'Identico';
                        $row['message'] = 'Riga ripetuta nel blocco: unità conteggiata una volta sola, tutti i diritti e le quote sono conservati.';
                        if ($repeated['unit']['subjectHolding'] && Holding::conflictingShares($repeated['unit']['subjectHolding'])) {
                            $row['status'] = $repeated['status'] = 'Conflitto';
                            $row['message'] = $repeated['message'] = 'Quote differenti per lo stesso diritto sulla stessa unità: verifica la fonte.';
                        }
                    } else {
                        $row['status'] = $repeated['status'] = 'Conflitto';
                        $row['message'] = $repeated['message'] = 'Stessa chiave catastale con dati differenti nel blocco.';
                    }
                    unset($repeated);
                } else {
                    $seen[$key] = count($rows);
                }
            }
            $rows[] = $row;
            unset($row);
        }
        if ($in['targetUnitId'] && ! $rows && $people['owners']) {
            $target = $this->targetUnit($actor, $in['targetUnitId']);
            if ($target && $target['unit']->state === 'Attivo' && ! $this->parcelSuppressed($actor, $target['unit']->parcel_id)) {
                $rows[] = ['line' => 1, 'key' => $target['key'], 'context' => array_merge($target['full'], ['situationDate' => $context['situationDate']]),
                    'unit' => $this->unitView($target['unit']), 'parcelSuppressed' => false, 'status' => 'Identico',
                    'message' => 'Unità già presente: vengono aggiunti soltanto i collegamenti mancanti.', 'existingParcelId' => (int) $target['unit']->parcel_id, 'existingUnitId' => (int) $target['unit']->cadastral_unit_id];
                $seen[$target['key']] = 0;
            } else {
                $people['errors'][] = 'Manca un’unità attiva a cui collegare i proprietari.';
            }
        }
        if ($people['owners'] && ! $rows) {
            $people['errors'][] = 'Manca l’unità: cercala nell’archivio oppure incolla anche la sua riga catastale.';
        }
        $usable = array_filter($rows, fn ($r) => ! in_array($r['status'], self::BAD, true));
        if (count($people['owners']) > 1 && count($usable) > 1) {
            $people['errors'][] = 'Il testo contiene più proprietari e più unità. Seleziona l’unità a cui appartiene questo elenco: nessun collegamento viene indovinato.';
        }
        if ($in['targetUnitId'] && $people['owners'] && array_filter($rows, fn ($r) => $r['status'] !== 'Escluso' && $r['existingUnitId'] !== (int) $in['targetUnitId'])) {
            $people['errors'][] = 'L’unità incollata non coincide con quella selezionata. Correggi la selezione prima di collegare i proprietari.';
        }

        $ownerPreview = [];
        foreach ($people['owners'] as $person) {
            $old = DB::table('contacts')->where('agency_id', $actor->agency_id)->where('tax_code', $person['pid'])->first();
            if ($old && $old->removed_at) {
                $people['errors'][] = 'Il codice fiscale '.$person['pid'].' appartiene a una scheda archiviata: occorre una verifica amministrativa.';
            }
            $ownerPreview[] = $person + ['existingOwnerId' => $old?->id, 'previousName' => $old?->display_name,
                'outcome' => ! $old ? 'Nuovo' : (self::norm($old->display_name) !== self::norm($person['name']) ? 'Nome diverso' : 'Già presente')];
        }
        $warnings = [];
        if ($people['owners'] && $in['mode'] !== 'owner') {
            $sums = [];
            foreach ($people['owners'] as $person) {
                if (! Holding::valid($person)) {
                    $people['errors'][] = 'Completa diritto e quota per '.$person['name'].'.';
                }
                foreach (Holding::components($person) as $h) {
                    if (preg_match('/^(\d+)\/(\d+)$/', $h['fraction'], $fm) && (int) $fm[2] > 0) {
                        $sums[Holding::normalized($h['right'])] = ($sums[Holding::normalized($h['right'])] ?? 0) + (int) $fm[1] / (int) $fm[2];
                    }
                }
            }
            foreach ($sums as $right => $sum) {
                if (abs($sum - 1) > 0.000001) {
                    $warnings[] = 'Le quote per '.mb_strtolower($right).' sommano '.rtrim(rtrim(number_format($sum, 4, ',', '.'), '0'), ',').', non 1. Verifica che l’elenco sia completo.';
                }
            }
        }
        foreach ($rows as &$r) {
            if ($in['preserveExisting'] && $r['status'] === 'Aggiornamento' && $r['existingUnitId'] !== null) {
                $r['status'] = 'Identico';
                $r['message'] = 'Dati catastali già presenti conservati. Verranno aggiunti solo i collegamenti mancanti.';
            }
        }
        unset($r);
        if ($in['mode'] === 'owner' && count($people['owners']) === 1) {
            foreach ($seen as $index) {
                $r = &$rows[$index];
                if (in_array($r['status'], self::BAD, true) || ! $r['unit']['subjectHolding'] || $r['existingUnitId'] === null) {
                    continue;
                }
                $current = DB::table('ownerships')->where('agency_id', $actor->agency_id)->where('cadastral_unit_id', $r['existingUnitId'])->whereNull('valid_to')
                    ->whereIn('contact_id', DB::table('contacts')->where('agency_id', $actor->agency_id)->where('tax_code', $people['owners'][0]['pid'])->select('id'))->first();
                if (! $current) {
                    continue;
                }
                $old = CensusReader::holding($current);
                if (Holding::same($old, $r['unit']['subjectHolding'])) {
                    continue;
                }
                if (Holding::isExtension($old, $r['unit']['subjectHolding'])) {
                    $r['status'] = 'Aggiornamento';
                    $r['message'] = 'Ulteriori diritti dello stesso intestatario: saranno aggiunti conservando tutte le quote e lo storico.';
                } else {
                    $r['status'] = 'Conflitto';
                    $r['message'] = 'Diritto o quota già presenti differiscono: usa Aggiorna proprietari per una sostituzione esplicita con storico.';
                }
                unset($r);
            }
        }
        $valid = array_values(array_filter($rows, fn ($r) => ! in_array($r['status'], self::BAD, true) && ! str_starts_with($r['message'], 'Riga ripetuta')));
        $missing = $this->missingOwners($actor, $valid, $people['owners'], $in['mode'] === 'owner');

        return ['missingOwners' => $missing, 'context' => $context,
            'detected' => $in['mode'] === 'owner' ? 'Persona con più immobili' : ($people['owners'] ? 'Unità con intestatari' : 'Elenco immobili'),
            'ownerPreview' => $ownerPreview, 'warnings' => $warnings, 'unresolvedCommunes' => $unresolved, 'rows' => $rows, 'owners' => $people['owners'],
            'errors' => $people['errors'], 'valid' => $valid, 'mode' => $in['mode']];
    }

    // ------------------------------------------------------------------ salvataggio

    /**
     * applyCensusImport(): scrive i collegamenti confermati. Con lo stesso token e gli stessi dati non fa nulla due volte.
     *
     * @return array{id:?int,unchanged:bool,parcelIds:list<int>,unitIds:list<int>,owners:int,units:int,title:string,detail:string}
     */
    public function apply(AgencyMembership $actor, array $input): array
    {
        if (! $actor->allows('sister.import')) {
            throw new CommandRejected('Permesso “Importa da Sister” non assegnato.', 403);
        }
        $token = (string) ($input['token'] ?? '');
        if ($token !== '') {
            if (strlen($token) > 100) {
                throw new CommandRejected('Identificativo operazione non valido.');
            }
            $payload = array_diff_key($input, ['token' => true]);

            return $this->idempotency->run('sister.import', $token, $payload, fn () => $this->write($actor, $input)) ?? [];
        }

        return DB::transaction(fn () => $this->write($actor, $input));
    }

    /** @return array<string,mixed> */
    private function write(AgencyMembership $actor, array $input): array
    {
        $preview = $this->preview($actor, $input);
        $valid = $preview['valid'];
        $selected = $input['selectedParcel'] ?? null;
        if ($selected) {
            $sel = array_merge(self::context($selected), ['sheet' => (string) ($selected['sheet'] ?? ''), 'parcel' => (string) ($selected['parcel'] ?? '')]);
            if (! self::identifier($sel['sheet']) || ! self::identifier($sel['parcel'])) {
                throw new CommandRejected('Riferimento della particella selezionata non valido.');
            }
            foreach ($valid as $r) {
                if (self::parcelKey(array_merge($sel, ['kind' => $r['context']['kind']])) !== self::parcelKey($r['context']) && empty($input['confirmOtherParcels'])) {
                    throw new CommandRejected('Il testo contiene particelle diverse da quella selezionata sulla mappa. Verifica e conferma l’importazione delle altre particelle.');
                }
            }
        }
        if ($preview['errors']) {
            throw new CommandRejected(implode("\n", $preview['errors']));
        }
        if (! $valid && ! $preview['owners']) {
            throw new CommandRejected('Nessuna riga importabile.');
        }
        $zone = ! empty($input['zoneId']) ? DB::table('scouting_zones')->where('agency_id', $actor->agency_id)->where('id', $input['zoneId'])->first() : null;
        if (! empty($input['zoneId']) && (! $zone || ($actor->role !== 'admin' && (int) $zone->operator_user_id !== (int) $actor->user_id))) {
            throw new CommandRejected('Zona di censimento non accessibile.', 403);
        }
        if ($actor->role !== 'admin') {
            foreach ($valid as $r) {
                if ($r['existingParcelId'] !== null && CensusScope::parcelOperator($actor->agency_id, $r['existingParcelId']) !== (int) $actor->user_id) {
                    throw new CommandRejected('Una particella esistente non è assegnata a questo operatore.', 403);
                }
            }
        }
        if ($preview['unresolvedCommunes']) {
            throw new CommandRejected('Conferma i codici catastali dei Comuni rilevati prima di importare.');
        }
        $keysOfActive = array_column(array_filter($valid, fn ($r) => $r['unit']['state'] !== 'Soppresso'), 'key');
        $links = ($preview['mode'] === 'owner' || ($preview['owners'] && count($valid) === 1))
            ? array_fill_keys(array_column($preview['owners'], 'pid'), $keysOfActive) : (array) ($input['links'] ?? []);
        foreach ($links as $pid => $keys) {
            if (! in_array($pid, array_column($preview['owners'], 'pid'), true) || ! is_array($keys) || array_diff($keys, $keysOfActive) !== []) {
                throw new CommandRejected('Collegamenti proprietari non validi. Scegli soltanto unità attive del blocco.');
            }
        }
        $decisions = (array) ($input['ownerDecisions'] ?? []);
        $fingerprint = $this->fingerprint($preview, $links, $decisions, $zone?->id);
        $renamed = collect($preview['ownerPreview'])->contains(fn ($o) => $o['outcome'] === 'Nome diverso' && in_array($o['pid'], (array) ($input['confirmNameUpdates'] ?? []), true));
        $decided = ! $preview['missingOwners'] || collect($preview['missingOwners'])->every(fn ($m) => in_array($decisions[$m['key']] ?? '', ['maintain', 'close'], true));
        if (! $renamed && $decided && CensusBatch::query()->where('fingerprint', $fingerprint)->exists()) {
            return ['id' => null, 'unchanged' => true] + $this->summary($preview, []);
        }
        $this->validateDecisions($preview['missingOwners'], $decisions);
        if (array_filter($preview['rows'], fn ($r) => in_array($r['status'], ['Conflitto', 'Incompleto', 'Non riconosciuto'], true)) && empty($input['skipInvalid'])) {
            throw new CommandRejected('Controlla le righe escluse e conferma l’importazione delle sole righe valide.');
        }
        if (array_filter($valid, fn ($r) => in_array($r['status'], ['Aggiornamento', 'Soppresso'], true)) && empty($input['confirmUpdates'])) {
            throw new CommandRejected('Conferma gli aggiornamenti e le soppressioni mostrati nell’anteprima.');
        }

        $now = now();
        $touched = ['parcels' => [], 'units' => []];
        $people = [];
        $newOwners = 0;
        $renamedAny = false;
        foreach ($preview['owners'] as $person) {
            $contact = Contact::query()->where('tax_code', $person['pid'])->first();
            if ($contact && self::norm($contact->display_name) !== self::norm($person['name']) && in_array($person['pid'], (array) ($input['confirmNameUpdates'] ?? []), true)) {
                if ($actor->role !== 'admin') {
                    throw new CommandRejected('Solo l’Amministratore può confermare l’aggiornamento del nome per '.$person['pid'].'.');
                }
                $before = ['name' => $contact->display_name, 'pid' => $contact->tax_code];
                $contact->forceFill(['display_name' => $person['name']])->save();
                $renamedAny = true;
                $this->audit->record('owner.name.confirmed', $contact, ['before' => $before, 'after' => ['name' => $person['name'], 'pid' => $contact->tax_code]], 'Nome aggiornato da SISTER con conferma esplicita');
            }
            if (! $contact) {
                $contact = new Contact(['display_name' => $person['name'], 'tax_code' => $person['pid'], 'birth_details' => $person['birthDetails'] ?: null, 'origin' => 'sister']);
                $contact->forceFill(['imported_by_user_id' => $actor->user_id])->save();
                $newOwners++;
            }
            $people[$person['pid']] = $contact;
        }

        $batchKey = 'pending';
        foreach ($preview['missingOwners'] as $m) {
            if (($decisions[$m['key']] ?? '') === 'close') {
                $this->closeMissing($actor, $m, $preview['context']['situationDate'], $now);
                $touched['parcels'][$m['parcelId']] = true;
            }
        }

        foreach ($valid as $row) {
            $parcel = $this->ensureParcel($actor, $row['context'], $zone, $row['unit']['address'] ?? '');
            $touched['parcels'][$parcel->id] = true;
            if ($row['parcelSuppressed']) {
                if (! $this->parcelSuppressed($actor, $parcel->id)) {
                    $this->suppressParcel($actor, $parcel, $now);
                }

                continue;
            }
            $unit = $this->ensureUnit($actor, $parcel, $row['unit']);
            $overlay = AgencyUnitObservation::query()->where('cadastral_unit_id', $unit->id)->first();
            if ($row['status'] !== 'Identico' && $row['status'] !== 'Già soppresso') {
                $old = $overlay?->only(['category', 'address', 'state']);
                $overlay = $this->writeOverlay($actor, $overlay, $unit, $row['unit'], $now);
                $touched['units'][$unit->id] = true;
                $this->audit->record('census.unit.import', $overlay, ['before' => $old, 'after' => $overlay->only(['category', 'address', 'state']), 'key' => $row['key']], $row['status']);
                if ($overlay->state === 'Soppresso') {
                    $this->warnProperties($unit->id);
                }
            }
            if (! $overlay || $overlay->state === 'Soppresso') {
                continue;
            }
            if ($row['context']['situationDate'] && ($overlay->situation_date?->format('Y-m-d') ?? '') !== $row['context']['situationDate']) {
                $before = $overlay->situation_date?->format('Y-m-d') ?? '';
                $overlay->forceFill(['situation_date' => $row['context']['situationDate']])->save();
                $touched['units'][$unit->id] = true;
                $this->audit->record('sister.source.date', $overlay, ['before' => $before, 'after' => $row['context']['situationDate']], 'Data della nuova fonte SISTER; dati catastali conservati');
            }
            foreach ($preview['owners'] as $person) {
                if (! in_array($row['key'], $links[$person['pid']] ?? [], true)) {
                    continue;
                }
                $holding = count($preview['owners']) === 1 && $row['unit']['subjectHolding'] ? $row['unit']['subjectHolding'] : $person;
                $this->link($actor, $people[$person['pid']], $unit, $parcel, $holding, $person['rawText'], $row['context']['situationDate'], ! empty($input['confirmUpdates']), $now);
                $touched['units'][$unit->id] = true;
                $touched['parcels'][$parcel->id] = true;
            }
        }

        $batch = null;
        $keys = array_values(array_unique(array_map(fn ($r) => self::parcelKey($r['context']), array_filter($preview['rows'], fn ($r) => $r['context']['sheet'] !== '' && $r['context']['parcel'] !== ''))));
        $newZone = $zone && ! CensusBatch::query()->where('scouting_zone_id', $zone->id)->exists();
        if ($touched['parcels'] || $newOwners || $newZone || $renamedAny || $preview['missingOwners']) {
            $rows = $preview['rows'];
            $batch = CensusBatch::query()->create([
                'actor_user_id' => $actor->user_id, 'scouting_zone_id' => $zone?->id, 'source' => 'Sister', 'source_date' => $preview['context']['situationDate'] ?: null,
                'fingerprint' => $fingerprint,
                'summary' => [
                    'keys' => $keys, 'rows' => count($rows),
                    'active' => count(array_filter($valid, fn ($r) => $r['unit']['state'] === 'Attivo')),
                    'suppressed' => count(array_filter($valid, fn ($r) => $r['unit']['state'] === 'Soppresso')),
                    'incomplete' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['Incompleto', 'Non riconosciuto'], true))),
                    'conflicts' => count(array_filter($rows, fn ($r) => $r['status'] === 'Conflitto')),
                    'duplicates' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['Identico', 'Già soppresso'], true))),
                    'newUnits' => count(array_filter($valid, fn ($r) => ! $r['parcelSuppressed'] && $r['existingUnitId'] === null)),
                    'newOwners' => $newOwners, 'reusedOwners' => count($preview['owners']) - $newOwners,
                    'ownerDecisions' => array_map(fn ($m) => $m + ['decision' => $decisions[$m['key']] ?? null], $preview['missingOwners']),
                ],
                'issues' => array_values(array_map(fn ($r) => ['key' => self::parcelKey($r['context']), 'unitKey' => $r['key'], 'context' => $r['context'], 'rawText' => $r['unit']['rawText'],
                    'status' => $r['status'], 'line' => $r['line'], 'message' => $r['message']], array_filter($rows, fn ($r) => in_array($r['status'], ['Incompleto', 'Non riconosciuto', 'Conflitto'], true)))),
            ]);
            if ($touched['units']) {
                AgencyUnitObservation::query()->whereIn('cadastral_unit_id', array_keys($touched['units']))->update(['census_batch_id' => $batch->id]);
            }
            if ($zone && $zone->status === 'Pianificata') {
                DB::table('scouting_zones')->where('id', $zone->id)->update(['status' => 'In corso', 'updated_at' => $now]);
            }
            $this->audit->record('sister.import', $batch, ['rows' => count($rows), 'newOwners' => $newOwners]);
        }

        return ['id' => $batch?->id, 'unchanged' => $batch === null] + $this->summary($preview, array_keys($touched['parcels']), array_keys($touched['units']));
    }

    /** @return array<string,mixed> */
    private function summary(array $preview, array $parcels, array $units = []): array
    {
        $number = count($preview['owners']);
        $one = count($preview['valid']) === 1 ? $preview['valid'][0] : null;
        $title = $number && $one
            ? ($number === 1 ? 'Collegato 1 proprietario' : "Collegati {$number} proprietari").' a F.'.$one['context']['sheet'].' P.'.$one['context']['parcel'].' Sub.'.($one['unit']['sub'] !== '' ? $one['unit']['sub'] : '—')
            : ($number ? 'Collegati '.count($preview['valid']).' immobili a '.$preview['owners'][0]['name'] : (count($preview['valid']) === 1 ? 'Importato 1 immobile' : 'Importati '.count($preview['valid']).' immobili'));

        return ['parcelIds' => array_map('intval', $parcels), 'unitIds' => array_map('intval', $units), 'owners' => $number, 'units' => count($preview['valid']), 'title' => $title,
            'detail' => 'Collegamenti salvati senza duplicati. Le eventuali chiusure sono conservate nello storico; i dati catastali restano invariati e la data della fonte è aggiornata quando presente.'];
    }

    // ------------------------------------------------------------------ scrittura

    private function ensureMunicipality(array $c): Municipality
    {
        $m = Municipality::query()->where('cadastral_code', strtoupper($c['code']))->first();
        if ($m) {
            return $m;
        }
        $province = $c['province'] !== '' ? DB::table('territorial_provinces')->where('abbreviation', strtoupper($c['province']))->value('id') : null;

        return Municipality::query()->create(['cadastral_code' => strtoupper($c['code']), 'name' => $c['municipality'], 'territorial_province_id' => $province]);
    }

    private function ensureParcel(AgencyMembership $actor, array $c, ?object $zone, string $address): Parcel
    {
        $municipality = $this->ensureMunicipality($c);
        $parcel = Parcel::query()->firstOrCreate(
            ['municipality_id' => $municipality->id, 'cadastral_kind' => $c['kind'] === 'Terreni' ? 'T' : 'F', 'section' => self::section($c['section']),
                'sheet' => CatalogSearch::cadastralId($c['sheet']), 'number' => CatalogSearch::cadastralId($c['parcel'])],
        );
        // L'operatore della particella è chi la importa o l'operatore della zona; il Responsabile da solo non la assegna.
        $operator = $zone ? (int) $zone->operator_user_id : ($actor->role === 'scout' ? (int) $actor->user_id : null);
        if ($operator !== null && CensusScope::parcelOperator($actor->agency_id, $parcel->id) === null) {
            CensusScope::assignParcel($actor->agency_id, $parcel->id, $operator, $zone?->id);
        }

        return $parcel;
    }

    private function ensureUnit(AgencyMembership $actor, Parcel $parcel, array $unit): CadastralUnit
    {
        $sub = CatalogSearch::cadastralId($unit['sub']);
        if ($sub !== '') {
            return CadastralUnit::query()->firstOrCreate(['parcel_id' => $parcel->id, 'subalterno' => $sub]);
        }
        $existing = DB::table('cadastral_units as cu')->join('agency_unit_observations as o', 'o.cadastral_unit_id', '=', 'cu.id')
            ->where('cu.parcel_id', $parcel->id)->whereNull('cu.subalterno')->where('o.agency_id', $actor->agency_id)->value('cu.id');
        if ($existing) {
            return CadastralUnit::query()->findOrFail($existing);
        }

        return CadastralUnit::query()->firstOrCreate(['parcel_id' => $parcel->id, 'subalterno' => null, 'source_ref' => 'sister:agenzia-senza-subalterno']);
    }

    private function writeOverlay(AgencyMembership $actor, ?AgencyUnitObservation $overlay, CadastralUnit $unit, array $u, $now): AgencyUnitObservation
    {
        $attrs = [
            'source' => 'sister-text', 'state' => $u['state'], 'category' => $u['category'], 'census_zone' => $u['censusZone'] ?: null, 'cadastral_class' => $u['cadastralClass'] ?: null,
            'consistency' => $u['consistency'] ?: null, 'consistency_value' => $u['consistencyValue'], 'consistency_unit' => $u['consistencyUnit'] ?: null, 'income' => $u['income'],
            'batch' => $u['batch'] ?: null, 'address' => $u['address'] ?: null, 'raw_address' => $u['rawAddress'] ?: null, 'floor' => $u['floor'] ?: null, 'raw_text' => $u['rawText'],
            'raw_classing' => $u['rawClassing'] ?: null, 'situation_date' => $u['situationDate'] ?: null, 'identity_detail' => $u['identityDetail'] ?? null,
            'subject_holding' => $u['subjectHolding'], 'user_id' => $overlay?->user_id ?? $actor->user_id,
        ];
        if ($u['state'] === 'Soppresso') {
            $attrs['removed'] = ['at' => $now->toIso8601String(), 'actorId' => $actor->user_id, 'reason' => 'Soppressione indicata da Sister', 'kind' => 'Soppressione'];
        }
        if ($overlay) {
            $overlay->forceFill($attrs)->save();

            return $overlay;
        }
        $overlay = new AgencyUnitObservation($attrs);
        $overlay->forceFill(['cadastral_unit_id' => $unit->id])->save();

        return $overlay;
    }

    /** Collega un proprietario a una unità con diritto e quota, completando (mai cambiando in silenzio) una titolarità esistente. */
    private function link(AgencyMembership $actor, Contact $contact, CadastralUnit $unit, Parcel $parcel, array $holding, string $rawText, string $date, bool $confirmUpdates, $now): void
    {
        $current = Ownership::query()->where('contact_id', $contact->id)->where('cadastral_unit_id', $unit->id)->whereNull('valid_to')->first();
        if ($current) {
            $old = CensusReader::holding((object) ['details' => $current->details, 'right_type' => $current->right_type, 'share_numerator' => $current->share_numerator, 'share_denominator' => $current->share_denominator]);
            if (Holding::same($old, $holding)) {
                return;
            }
            if (! Holding::isExtension($old, $holding) || ! $confirmUpdates) {
                throw new CommandRejected('Titolarità già presente con diritto o quota differenti: usa “Aggiorna proprietari” sulla singola unità per conservare lo storico.');
            }
            self::close($current, $date !== '' ? $date : $now->toDateString(), 'Completamento diritti da Sister', 'Correzione');
        }
        self::open($actor, $contact, $unit, $parcel, Holding::combine($holding), $date !== '' ? $date : null, 'Sister', 'Importazione', 'Associazione', $rawText, true, $now);
    }

    /** Apre una nuova intestazione attuale. */
    public static function open(AgencyMembership $actor, Contact $contact, CadastralUnit $unit, Parcel $parcel, array $holding, ?string $from, string $source, string $reason, string $kind, string $rawText, bool $exactDateUnknown, $now): Ownership
    {
        [$n, $d] = Holding::share($holding);
        $ownership = new Ownership([
            'contact_id' => $contact->id, 'cadastral_unit_id' => $unit->id, 'parcel_id' => $parcel->id, 'share_numerator' => $n, 'share_denominator' => $d,
            'right_type' => $holding['right'] ?: null, 'valid_from' => $from, 'source' => $source,
            'details' => ['holding' => $holding, 'verified_at' => $now->toIso8601String(), 'source' => $source, 'reason' => $reason, 'kind' => $kind, 'raw_text' => $rawText,
                'exact_date_unknown' => $exactDateUnknown, 'actor_user_id' => $actor->user_id],
        ]);
        $ownership->save();

        return $ownership;
    }

    /** Chiude l'intestazione (resta nello storico). */
    public static function close(Ownership $ownership, string $end, string $reason, string $kind, ?bool $exactDateUnknown = null): void
    {
        $details = $ownership->details ?? [];
        $details['reason'] = $reason;
        $details['kind'] = $kind;
        if ($exactDateUnknown !== null) {
            $details['exact_date_unknown'] = $exactDateUnknown;
        }
        $ownership->forceFill(['valid_to' => $end, 'details' => $details])->save();
    }

    private function closeMissing(AgencyMembership $actor, array $m, string $sourceDate, $now): void
    {
        $ownership = Ownership::query()->where('contact_id', $m['ownerId'])->where('cadastral_unit_id', $m['unitId'])->whereNull('valid_to')->first();
        if (! $ownership) {
            throw new CommandRejected('Il collegamento è cambiato. Controlla di nuovo il testo.', 409);
        }
        $reason = 'Intestatario assente nella nuova visura SISTER: chiusura confermata';
        self::close($ownership, $sourceDate !== '' ? $sourceDate : $now->toDateString(), $reason, 'Trasferimento', true);
        $this->audit->record('sister.owner.close', $ownership, ['unit' => $m['unitId'], 'owner' => $m['ownerId']], $reason);
    }

    private function suppressParcel(AgencyMembership $actor, Parcel $parcel, $now): void
    {
        $removed = ['at' => $now->toIso8601String(), 'actorId' => $actor->user_id, 'reason' => 'Particella soppressa: indicazione esplicita nella fonte Sister', 'kind' => 'Soppressione particella'];
        $units = AgencyUnitObservation::query()->whereIn('cadastral_unit_id', CadastralUnit::query()->where('parcel_id', $parcel->id)->select('id'))->get();
        foreach ($units as $o) {
            $o->forceFill(['state' => 'Soppresso', 'removed' => $removed])->save();
            $this->warnProperties($o->cadastral_unit_id);
        }
        $this->audit->record('census.parcel.suppression', $units->first(), ['parcel_id' => $parcel->id], $removed['reason']);
    }

    private function warnProperties(int $unitId): void
    {
        DB::table('properties')->whereIn('id', DB::table('property_units')->where('cadastral_unit_id', $unitId)->select('property_id'))
            ->update(['cadastral_warning' => 'Verifica catastale necessaria']);
    }

    // ------------------------------------------------------------------ lettura del censimento

    /** Normalizza l'input dell'anteprima (resolveCensusInput, modalità automatica). */
    private function resolve(AgencyMembership $actor, array $input): array
    {
        $text = $input['sourceText'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new CommandRejected('Incolla il testo da SISTER.');
        }
        if (mb_strlen($text) > 500000) {
            throw new CommandRejected('Dividi il testo in blocchi entro 500.000 caratteri.');
        }
        $split = SisterText::split($text);
        if ($split['unrecognized']) {
            throw new CommandRejected('Alcune righe non sono riconosciute. Copia le celle della tabella SISTER mantenendo le colonne: '.implode(' · ', array_map(fn ($r) => mb_substr($r, 0, 100), array_slice($split['unrecognized'], 0, 2))));
        }
        $targetId = ! empty($input['targetUnitId']) ? (int) $input['targetUnitId'] : null;
        $target = $targetId ? $this->targetUnit($actor, $targetId) : null;
        if ($targetId && ! $target) {
            throw new CommandRejected('L’unità selezionata non è più presente. Cercala nuovamente.');
        }
        $hasEstateRow = array_filter(preg_split('/\r?\n/', $split['estates']), fn ($r) => trim($r) !== '' && ! preg_match('/^(?:Situazione|Sezione)\b/iu', trim($r))) !== [];
        if ($split['owners'] !== '' && ! $target && ! $hasEstateRow) {
            throw new CommandRejected('Manca l’unità: selezionala oppure incolla anche la sua riga catastale.');
        }
        $base = $target ? $target['context'] : (array) ($input['context'] ?? []);

        return ['mode' => $split['subjectRows'] ? 'owner' : ($split['owners'] !== '' ? null : 'parcels'), 'owners' => $split['owners'], 'estates' => $split['estates'],
            'context' => array_merge($base, ['situationDate' => $split['sourceDate'] ?: (($input['context']['situationDate'] ?? '') ?: '')]),
            'targetUnitId' => $targetId, 'trusted' => $target !== null, 'preserveExisting' => $input['preserveExisting'] ?? true];
    }

    /** importContext(): Catasto, Provincia, Comune e codice catastale validi. @return array<string,string> */
    public static function context(mixed $c, bool $strict = true): array
    {
        $c = (array) $c;
        if (! in_array($c['kind'] ?? null, ['Fabbricati', 'Terreni'], true)) {
            throw new CommandRejected('Scegli il Catasto: Fabbricati oppure Terreni.');
        }
        $date = (string) ($c['situationDate'] ?? '');
        $ok = (! $strict || preg_match('/^[A-Z]{2}$/i', (string) ($c['province'] ?? ''))) && trim((string) ($c['municipality'] ?? '')) !== '' && preg_match('/^[A-Z]\d{3}$/i', (string) ($c['code'] ?? ''))
            && mb_strlen((string) ($c['section'] ?? '')) <= 12 && ($date === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))));
        if (! $ok) {
            throw new CommandRejected('Completa Provincia, Comune e codice catastale. Controlla gli eventuali metadati della fonte.');
        }

        return ['province' => strtoupper((string) ($c['province'] ?? '')), 'municipality' => trim($c['municipality']), 'code' => strtoupper($c['code']), 'section' => strtoupper(trim((string) ($c['section'] ?? ''))),
            'kind' => $c['kind'], 'situationDate' => $date];
    }

    /** @return array<string,string>|null un Comune noto, se il nome e la provincia sono univoci */
    private function municipalityByName(string $city, string $province): ?array
    {
        $rows = DB::table('municipalities as m')->join('territorial_provinces as p', 'p.id', '=', 'm.territorial_province_id')
            ->whereRaw('upper(m.name) = ?', [mb_strtoupper(trim($city), 'UTF-8')])->where('p.abbreviation', strtoupper($province))
            ->get(['m.name', 'm.cadastral_code', 'p.abbreviation']);
        if ($rows->count() !== 1) {
            return null;
        }

        return ['municipality' => $rows[0]->name, 'code' => $rows[0]->cadastral_code, 'province' => $rows[0]->abbreviation, 'section' => '', 'kind' => 'Fabbricati', 'situationDate' => ''];
    }

    private function agencyParcel(AgencyMembership $actor, array $c): ?object
    {
        $key = 'p'.self::parcelKey($c);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $parcel = DB::table('parcels as p')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
            ->where('m.cadastral_code', strtoupper($c['code']))->where('p.cadastral_kind', $c['kind'] === 'Terreni' ? 'T' : 'F')
            ->where('p.section', self::section($c['section']))->where('p.sheet', CatalogSearch::cadastralId($c['sheet']))->where('p.number', CatalogSearch::cadastralId($c['parcel']))
            ->select('p.*')->first();
        if ($parcel) {
            $present = DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
                ->where('o.agency_id', $actor->agency_id)->where('cu.parcel_id', $parcel->id)->exists();
            $parcel = $present ? $parcel : null;
        }

        return $this->memo[$key] = $parcel;
    }

    /** @return list<object> schede senza subalterno della particella */
    private function blankUnits(AgencyMembership $actor, int $parcelId): array
    {
        return DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->where('o.agency_id', $actor->agency_id)->where('cu.parcel_id', $parcelId)->whereNull('cu.subalterno')->select('o.*', 'cu.subalterno')->get()->all();
    }

    private function existingUnit(AgencyMembership $actor, int $parcelId, string $sub, array $blank): ?object
    {
        if ($sub === '') {
            return $blank[0] ?? null;
        }

        return DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->where('o.agency_id', $actor->agency_id)->where('cu.parcel_id', $parcelId)->where('cu.subalterno', CatalogSearch::cadastralId($sub))->select('o.*', 'cu.subalterno')->first();
    }

    private function parcelSuppressed(AgencyMembership $actor, int $parcelId): bool
    {
        return DB::table('agency_unit_observations as o')->join('cadastral_units as cu', 'cu.id', '=', 'o.cadastral_unit_id')
            ->where('o.agency_id', $actor->agency_id)->where('cu.parcel_id', $parcelId)->whereRaw("o.removed->>'kind' = 'Soppressione particella'")->exists();
    }

    /** @return array{unit:object,key:string,context:array<string,string>}|null l'unità selezionata (visibile all'operatore) */
    private function targetUnit(AgencyMembership $actor, int $unitId): ?array
    {
        $r = CensusScope::units($actor)->where('o.cadastral_unit_id', $unitId)
            ->select('o.*', 'cu.parcel_id', 'cu.subalterno', 'p.section as p_section', 'p.sheet as p_sheet', 'p.number as p_number', 'p.cadastral_kind as p_kind',
                'mu.name as mu_name', 'mu.cadastral_code as mu_code', 'tp.abbreviation as mu_province')->first();
        if (! $r) {
            return null;
        }
        $context = ['province' => (string) $r->mu_province, 'municipality' => $r->mu_name, 'code' => $r->mu_code, 'section' => (string) $r->p_section,
            'kind' => $r->p_kind === 'T' ? 'Terreni' : 'Fabbricati', 'situationDate' => (string) ($r->situation_date ?? '')];
        $sub = $r->subalterno === null ? '' : (string) $r->subalterno;

        $full = $context + ['sheet' => (string) $r->p_sheet, 'parcel' => (string) $r->p_number];

        return ['unit' => $r, 'context' => $context, 'full' => $full, 'key' => self::unitKey($full, $sub, (string) $r->identity_detail)];
    }

    /** Scheda del database nella forma dei dati importati, per confronti. */
    private function unitView(object $o): array
    {
        return ['sub' => $o->subalterno === null ? '' : (string) $o->subalterno, 'category' => (string) $o->category, 'address' => (string) $o->address, 'cadastralClass' => (string) $o->cadastral_class,
            'consistency' => (string) $o->consistency, 'floor' => (string) $o->floor, 'censusZone' => (string) $o->census_zone, 'income' => $o->income === null ? null : (float) $o->income,
            'batch' => (string) $o->batch, 'state' => $o->state, 'subjectHolding' => $o->subject_holding ? json_decode($o->subject_holding, true) : null, 'rawText' => (string) $o->raw_text,
            'identityDetail' => (string) $o->identity_detail, 'situationDate' => (string) ($o->situation_date ?? '')];
    }

    // ------------------------------------------------------------------ intestatari assenti

    /** missingSisterOwners(): intestatari attuali che la nuova visura non riporta più. @return list<array<string,mixed>> */
    private function missingOwners(AgencyMembership $actor, array $rows, array $people, bool $subjectList): array
    {
        if ($subjectList || ! $people) {
            return [];
        }
        $imported = array_column($people, 'pid');
        $out = [];
        foreach ($rows as $row) {
            if ($row['existingUnitId'] === null || $row['unit']['state'] === 'Soppresso') {
                continue;
            }
            $owners = DB::table('ownerships as w')->join('contacts as c', 'c.id', '=', 'w.contact_id')->where('w.agency_id', $actor->agency_id)
                ->where('w.cadastral_unit_id', $row['existingUnitId'])->whereNull('w.valid_to')->whereNull('c.removed_at')->get(['c.id', 'c.display_name', 'c.tax_code']);
            foreach ($owners as $o) {
                if (! in_array(SisterText::normalizedCF($o->tax_code), $imported, true)) {
                    $out[] = ['key' => 'u'.$row['existingUnitId'].'-o'.$o->id, 'unitKey' => $row['key'], 'parcelId' => (int) $row['existingParcelId'], 'unitId' => (int) $row['existingUnitId'],
                        'ownerId' => (int) $o->id, 'name' => $o->display_name, 'pid' => (string) $o->tax_code];
                }
            }
        }

        return $out;
    }

    private function validateDecisions(array $missing, array $decisions): void
    {
        $keys = array_column($missing, 'key');
        foreach ($decisions as $key => $value) {
            if (! in_array($key, $keys, true) || ! in_array($value, ['maintain', 'close'], true)) {
                throw new CommandRejected('Una scelta non corrisponde più all’anteprima. Controlla di nuovo il testo.', 409);
            }
        }
        foreach ($keys as $key) {
            if (empty($decisions[$key])) {
                throw new CommandRejected('Un intestatario non compare più nella visura: scegli se mantenerlo o chiuderne l’intestazione.');
            }
        }
    }

    private function fingerprint(array $preview, array $links, array $decisions, ?int $zoneId): string
    {
        $rows = array_map(fn ($r) => [$r['key'], $r['parcelSuppressed'], $r['unit']['state'], $r['context']['situationDate'],
            ...array_map(fn ($k) => self::norm((string) ($r['unit'][$k] ?? '')), self::COMPARE)], $preview['rows']);
        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        $owners = array_map(fn ($o) => [$o['pid'], self::norm($o['name']), Holding::signature($o), self::norm($o['birthDetails'] ?? '')], $preview['owners']);
        usort($owners, fn ($a, $b) => strcmp($a[0], $b[0]));
        ksort($links);
        ksort($decisions);
        $subject = array_map(fn ($r) => [$r['key'], Holding::signature($r['unit']['subjectHolding'])], array_values(array_filter($preview['rows'], fn ($r) => $r['unit']['subjectHolding'])));
        usort($subject, fn ($a, $b) => strcmp($a[0], $b[0]));

        return hash('sha256', json_encode(['zone' => $zoneId, 'rows' => array_values(array_unique($rows, SORT_REGULAR)), 'owners' => $owners,
            'links' => array_map(fn ($k) => array_values(array_unique($k)), $links), 'decisions' => $decisions, 'subject' => $subject], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ utilità

    public static function identifier(string $s): bool
    {
        return (bool) preg_match('/^[\p{L}\d.\/-]+$/u', $s);
    }

    public static function norm(string $s): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
    }

    private static function section(string $s): string
    {
        $s = strtoupper(trim($s));

        return $s === '_' ? '' : $s;
    }

    private static function part(string $v): string
    {
        return preg_match('/^\d+$/', $v) ? (string) preg_replace('/^0+(?=\d)/', '', $v) : strtoupper(trim($v));
    }

    public static function parcelKey(array $c): string
    {
        return json_encode(['reko-local', strtoupper($c['code']), $c['kind'], self::section($c['section'] ?? ''), self::part($c['sheet']), self::part($c['parcel'])], JSON_UNESCAPED_UNICODE);
    }

    public static function unitKey(array $c, string $sub, string $detail = ''): string
    {
        return self::parcelKey($c).':'.self::part($sub).($sub === '' ? ':'.$detail : '');
    }

    public static function sameUnit(array $a, array $b): bool
    {
        foreach (self::COMPARE as $k) {
            $norm = function (array $u) use ($k) {
                $v = (string) ($u[$k] ?? '');

                return self::norm($k === 'address' ? SisterText::normalizeAddress($v) : ($k === 'consistency' ? SisterText::formatMeasure($v) : $v));
            };
            if ($norm($a) !== $norm($b)) {
                return false;
            }
        }

        return true;
    }

    /** compatibleBlank(): stessa categoria, indirizzo e piano, se presenti da entrambe le parti. */
    public static function compatibleBlank(array $a, array $b): bool
    {
        $blank = function (string $value): string {
            $parsed = SisterText::address($value);
            $address = $parsed ? trim($parsed['street'].' '.$parsed['number']) : $value;

            return self::norm((string) preg_replace('/[,.;]/u', ' ', (string) preg_replace('/\bn[.°]\s*/iu', ' ', (string) preg_replace('/\s+Piano\b.*$/iu', '', $address))));
        };
        foreach ([[$a['category'] ?? '', $b['category'] ?? ''], [$blank($a['address'] ?? ''), $blank($b['address'] ?? '')], [$a['floor'] ?? '', $b['floor'] ?? '']] as [$x, $y]) {
            if ($x !== '' && $y !== '' && self::norm($x) !== self::norm($y)) {
                return false;
            }
        }

        return true;
    }
}
