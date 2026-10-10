<?php

namespace App\Gestionale\Census;

use App\Trova\SisterParser;

/**
 * Lettura del testo copiato da SISTER per l'archivio catastale: sister-text.ts, sister-auto.ts,
 * gestionale-sister.ts (extractSisterClassing/Address) e parseCensusOwners di census-sister.ts.
 * Niente viene indovinato: una riga non riconosciuta resta segnalata.
 */
final class SisterText
{
    public const FISCAL_CODE = '/^(?:[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]|\d{11}|(?:TEST|DEMO)[A-Z0-9-]*)$/i';

    /** @return list<string> sisterCells(): tabulazioni o tabella Markdown, le celle vuote restano. */
    public static function cells(string $row): array
    {
        return SisterParser::cells($row);
    }

    public static function normalizedCF(?string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $value), 'UTF-8');
    }

    public static function meaningfulName(string $value): bool
    {
        return (bool) preg_match('/\p{L}/u', trim($value));
    }

    /** "Situazione aggiornata al: 31/12/2024" → 2024-12-31, oppure ''. */
    public static function sourceDate(string $text): string
    {
        if (! preg_match('/Situazione\s+aggiornata\s+al\s*:?\s*(\d{2})\/(\d{2})\/(\d{4})/iu', $text, $m)) {
            return '';
        }
        $iso = "{$m[3]}-{$m[2]}-{$m[1]}";

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $iso : '';
    }

    /**
     * splitSisterPaste(): divide le tabelle copiate in intestatari e immobili, senza dedurre l'identità
     * di un immobile da un indirizzo.
     *
     * @return array{owners:string,estates:string,subjectRows:bool,unrecognized:list<string>,sourceDate:string}
     */
    public static function split(string $text): array
    {
        $owners = [];
        $estates = [];
        $unrecognized = [];
        $subjectRows = false;

        foreach (preg_split('/\r\n|\n|\r/', $text) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:Situazione\s+aggiornata\s+al|Sezione\s+urbana)\b/iu', $line)) {
                $estates[] = $raw;

                continue;
            }
            $cells = self::cells($raw);
            $isHeader = array_filter($cells, fn ($c) => preg_match('/^(?:codice fiscale|nominativo(?: o denominazione)?|foglio|particella|catasto)$/iu', $c)) !== []
                || preg_match('/^(?:Intestatari|Elenco (?:degli )?immobili(?: del soggetto)?|Immobili|Proprietà)$/iu', $line)
                || array_filter($cells, fn ($c) => ! preg_match('/^:?-+:?$/', $c)) === [];
            if ($isHeader) {
                continue;
            }
            $cf = null;
            foreach ($cells as $i => $c) {
                if (preg_match(self::FISCAL_CODE, (string) preg_replace('/\s+/u', '', $c))) {
                    $cf = $i;
                    break;
                }
            }
            if ($cf !== null && $cf > 0 && $cf <= 3) {
                $clean = preg_match('/^\d+$/', $cells[0]) && $cf > 1 ? array_slice($cells, 1) : $cells;
                $owners[] = implode("\t", $clean);

                continue;
            }
            $first = $cells[0] ?? '';
            if (preg_match('/^[FT]$/i', $first) || (preg_match('/^\d+$/', $first) && count($cells) >= 4)
                || (preg_match('/^[A-Z]+\/\d+$/i', $first) && count($cells) >= 4)) {
                if (preg_match('/^[FT]$/i', $first)) {
                    $subjectRows = true;
                }
                $estates[] = $raw;

                continue;
            }
            $unrecognized[] = $raw;
        }

        return ['owners' => implode("\n", $owners), 'estates' => implode("\n", $estates), 'subjectRows' => $subjectRows,
            'unrecognized' => $unrecognized, 'sourceDate' => self::sourceDate($text)];
    }

    /** "Zona 2 Cat.A/3" → categoria A/3, zona censuaria 2. @return array{category:string,censusZone:string}|null */
    public static function classing(string $value): ?array
    {
        if (! preg_match('/^(?:Zona\s*(\w+)\s*)?(?:Cat\.?\s*)?([A-F])\s*\/?\s*0*(\d{1,2})$/iu', trim($value), $m) || (int) $m[3] <= 0) {
            return null;
        }

        return ['category' => strtoupper($m[2]).'/'.(int) $m[3], 'censusZone' => $m[1] ?? ''];
    }

    /** "MILANO(MI) VIA ROMA n. 5 Piano T" → città, provincia, via, civico. @return array{city:string,province:string,street:string,number:string}|null */
    public static function address(string $text): ?array
    {
        if (! preg_match('/^([^()]+)\(([A-Z]{2})\)\s*(.+)$/iu', trim($text), $m)) {
            return null;
        }
        $street = preg_match('/^(.+?)\s+n\.\s*(.+?)(?:\s+(?:Scala|Interno|Piano)\b.*)?$/iu', $m[3], $s) ? $s : null;

        return [
            'city' => trim($m[1]),
            'province' => strtoupper($m[2]),
            'street' => $street ? trim($s[1]) : trim((string) preg_replace('/\s+Piano\b.*$/iu', '', $m[3])),
            'number' => $street ? trim($s[2]) : '',
        ];
    }

    public static function italianNumber(string $input): ?float
    {
        $cleaned = trim((string) preg_replace('/^(?:R\.?\s*)?(?:Euro\s*:?|€)\s*/iu', '', $input));
        if ($cleaned === '' || ! preg_match('/^\d+(?:\.\d{3})*(?:,\d+)?$/', $cleaned)) {
            return null;
        }

        return (float) str_replace(',', '.', str_replace('.', '', $cleaned));
    }

    /**
     * parseCensusOwners(): intestatari di una unità. @return array{owners:list<array>,errors:list<string>}
     * Ogni intestatario: name, birthDetails, pid, right, fraction, rawText, components?.
     */
    public static function owners(string $input): array
    {
        $owners = [];
        $errors = [];
        foreach (preg_split('/\r\n|\n|\r/', $input) as $i => $row) {
            if (trim($row) === '' || preg_match('/^(?:Situazione\s+aggiornata\s+al|Intestatari)\b/iu', trim($row))) {
                continue;
            }
            $f = self::cells($row);
            if (array_filter($f, fn ($v) => ! preg_match('/^:?-+:?$/', $v)) === []) {
                continue;
            }
            if (array_filter($f, fn ($v) => preg_match('/^(codice fiscale|pid)$/iu', $v)) !== []) {
                continue;
            }
            $separateBirth = (bool) preg_match(self::FISCAL_CODE, self::normalizedCF($f[2] ?? ''));
            $ci = $separateBirth ? 2 : 1;
            $birth = preg_match('/^(.+?)\s+(nat[oa]\s+(?:a|il)\s+.+)$/iu', $f[0] ?? '', $b) ? $b : null;
            $holding = Holding::extract($f[$ci + 1] ?? '' ?: ($f[$ci + 2] ?? ''), ($f[$ci + 1] ?? '') !== '' ? ($f[$ci + 2] ?? '') : '');
            $person = [
                'name' => $birth ? $b[1] : ($f[0] ?? ''),
                'birthDetails' => $separateBirth ? ($f[1] ?? '') : ($birth ? $b[2] : ''),
                'pid' => self::normalizedCF($f[$ci] ?? ''),
            ] + $holding;
            $n = $i + 1;
            if ($person['name'] === '' || ! self::meaningfulName($person['name']) || ! preg_match('/^[A-Z0-9-]{3,32}$/', $person['pid'])) {
                $errors[] = "Proprietari, riga {$n}: nominativo o codice fiscale mancante/non riconosciuto.";

                continue;
            }
            if (($person['right'] !== '' || $person['fraction'] !== '') && ! Holding::valid($person)) {
                $errors[] = "Proprietari, riga {$n}: diritto o quota non riconosciuti. Controlla le colonne originali.";

                continue;
            }
            $previous = null;
            foreach ($owners as $k => $o) {
                if ($o['pid'] === $person['pid']) {
                    $previous = $k;
                    break;
                }
            }
            if ($previous !== null) {
                $old = $owners[$previous];
                $norm = fn (string $s) => mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($s)), 'UTF-8');
                if ($norm($old['name']) !== $norm($person['name']) || ($old['birthDetails'] !== '' && $person['birthDetails'] !== '' && $norm($old['birthDetails']) !== $norm($person['birthDetails']))) {
                    $errors[] = "Proprietari, riga {$n}: codice fiscale ripetuto con anagrafica differente.";
                } else {
                    $owners[$previous] = array_merge($old, Holding::combine($old, array_merge($person, ['rawText' => $row])));
                    if (Holding::conflictingShares($owners[$previous])) {
                        $errors[] = "Proprietari, riga {$n}: quote differenti per lo stesso diritto. Verifica la fonte prima di salvare.";
                    }
                }

                continue;
            }
            $person['rawText'] = $row;
            $owners[] = $person;
        }

        return ['owners' => $owners, 'errors' => $errors];
    }

    // ---- presentazione e normalizzazione (presentation-normalization.ts, census-address.ts, census-model.ts) ----

    /** normalizeAddress(): "20; VIA ROMA, 5" → "VIA ROMA n. 5". */
    public static function normalizeAddress(string $value): string
    {
        $v = (string) preg_replace('/\s+/u', ' ', trim($value));
        $v = (string) preg_replace('/^\d+\s*;\s*(?=(?:VIA|VIALE|VICOLO|PIAZZA|PIAZZALE|CORSO|LARGO|STRADA|LOCALIT[AÀ]|FRAZIONE|CONTRADA|VICO|CIRCONVALLAZIONE|LUNGOMARE|LUNGARNO)\b)/iu', '', $v);
        $v = (string) preg_replace('/\s*,?\s+N[.°]?\s*(?=\d)/iu', ' n. ', $v);

        return (string) preg_replace('/,\s*(?=\d)/u', ' n. ', $v);
    }

    public static function formatMeasure(string $value): string
    {
        $v = (string) preg_replace('/\b(?:m2|mq)\b/iu', 'm²', $value);

        return (string) preg_replace('/\b(?:m3|mc)\b/iu', 'm³', $v);
    }

    /** splitCensusAddress(). @return array{street:string,civic:string,needsReview:bool} */
    public static function splitAddress(string $raw): array
    {
        $address = self::normalizeAddress($raw);
        if (! preg_match('/^(.*?)\s+(?:n\.?|civico)\s*(\d+\p{L}?(?:\s*[\/\-]\s*[\p{L}\d]+)?|s\.?n\.?c\.?)$/iu', $address, $m)) {
            return ['street' => $address, 'civic' => '', 'needsReview' => (bool) preg_match('/\d/', $address)];
        }
        $street = trim($m[1]);
        $civic = mb_strtoupper((string) preg_replace('/\s+/u', '', $m[2]), 'UTF-8');
        $ambiguous = preg_match('/\d/', $street) && ! preg_match('/\b\d{1,2}\s+(?:gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\b/iu', $street);

        return $ambiguous ? ['street' => $address, 'civic' => '', 'needsReview' => true] : ['street' => $street, 'civic' => $civic, 'needsReview' => false];
    }

    public static function normalizeCivic(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', (string) preg_replace('/^(?:n\.?|civico)\s*/iu', '', $value)), 'UTF-8');
    }

    /** floorLabel() di human-labels.ts. */
    public static function floorLabel(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 'Non indicato';
        }
        if (($single = self::floorToken($raw)) !== null) {
            return $single;
        }
        $parts = array_map('trim', preg_split('/[;,\/]+/', $raw));
        if (count($parts) > 1) {
            $labels = array_map(self::floorToken(...), $parts);
            if (! in_array(null, $labels, true)) {
                return implode(' · ', $labels);
            }
        }

        return $raw;
    }

    private static function floorToken(string $value): ?string
    {
        $token = mb_strtoupper(trim($value), 'UTF-8');
        $token = (string) preg_replace('/^(?:PIANO\s+|P\.\s*)/u', '', $token);
        if (in_array($token, ['T', 'PT', '0'], true)) {
            return 'Piano terra';
        }
        if (in_array($token, ['R', 'PR'], true)) {
            return 'Piano rialzato';
        }
        if ($token === 'AM') {
            return 'Piano ammezzato';
        }
        if (preg_match('/^S\s*0*([1-9]\d*)$/', $token, $m) || preg_match('/^-0*([1-9]\d*)$/', $token, $m)) {
            return 'Piano interrato '.(int) $m[1];
        }
        if (preg_match('/^0*[1-9]\d*$/', $token)) {
            return 'Piano '.(int) $token;
        }

        return null;
    }

    /** @return list<string> email:… e tel:… trovati in un testo libero (extractedContacts). */
    public static function extractedContacts(string $value): array
    {
        preg_match_all('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', $value, $e);
        $emails = array_map(fn ($m) => 'email:'.mb_strtolower($m), $e[0]);
        $without = (string) preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', '', $value);
        preg_match_all('/\+?\d[\d ()-]{5,}\d/u', $without, $p);
        $phones = array_map(fn ($m) => 'tel:'.preg_replace('/^(?:0039|39)(?=\d{9,11}$)/', '', (string) preg_replace('/\D/', '', $m)), $p[0]);

        return [...$emails, ...$phones];
    }

    /** matchesCensusTerm(): ricerca libera senza accenti, con numeri interi e telefoni normalizzati. */
    public static function matches(string $value, string $term): bool
    {
        if (mb_stripos($value, $term) !== false) {
            return true;
        }
        if (preg_match('/^[+\d\s()-]+$/', $term) && strlen((string) preg_replace('/\D/', '', $term)) >= 6) {
            $phone = preg_replace('/^(?:0039|39)(?=\d{9,11}$)/', '', (string) preg_replace('/\D/', '', $term));

            return in_array('tel:'.$phone, self::extractedContacts($value), true);
        }
        $haystack = self::fold($value);
        $terms = array_values(array_filter(explode(' ', self::fold($term)), fn ($t) => $t !== ''));
        if ($terms === []) {
            return false;
        }
        $words = explode(' ', $haystack);
        foreach ($terms as $token) {
            $ok = preg_match('/^\d+$/', $token) ? in_array($token, $words, true) : str_contains($haystack, $token);
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function fold(string $input): string
    {
        $n = \Normalizer::normalize($input, \Normalizer::FORM_KD);
        $n = (string) preg_replace('/\p{M}/u', '', $n === false ? $input : $n);
        $n = mb_strtolower($n, 'UTF-8');
        $n = (string) preg_replace('/(\p{L})(\d)|(\d)(\p{L})/u', '$1$3 $2$4', $n);

        return trim((string) preg_replace('/[^\p{L}\d]+/u', ' ', $n));
    }
}
