<?php

namespace App\Gestionale\Census;

use App\Gestionale\Permissions;
use App\Models\AgencyMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Port of ownersWithoutPhoneCSV() from Gestionale's census export. */
final class CensusMissingPhoneExport
{
    private const VALID_PID = '/^(?:[A-Z]{6}[A-Z0-9]{2}[A-Z][A-Z0-9]{2}[A-Z][A-Z0-9]{3}[A-Z]|\d{11})$/';

    public function csv(AgencyMembership $membership): string
    {
        Permissions::require($membership, 'exports');
        if (! CensusScope::canUse($membership)) {
            abort(403, CensusScope::NOT_ALLOWED);
        }

        $owners = DB::table('contacts as c')->where('c.agency_id', $membership->agency_id)->whereNull('c.removed_at')
            ->where(function (Builder $owner) use ($membership): void {
                $owner->whereNotNull('c.imported_by_user_id')
                    ->orWhereExists(fn (Builder $holding) => $holding->selectRaw('1')->from('ownerships as w')
                        ->whereColumn('w.contact_id', 'c.id')->where('w.agency_id', $membership->agency_id));
            });

        if ($membership->role === 'scout') {
            $visibleUnits = CensusScope::units($membership)->selectRaw('1')
                ->whereExists(fn (Builder $holding) => $holding->selectRaw('1')->from('ownerships as w')
                    ->whereColumn('w.cadastral_unit_id', 'o.cadastral_unit_id')->whereColumn('w.contact_id', 'c.id')
                    ->where('w.agency_id', $membership->agency_id)->whereNull('w.valid_to'));

            $owners->where(fn (Builder $visible) => $visible->where('c.imported_by_user_id', $membership->user_id)->orWhereExists($visibleUnits));
        }

        $grouped = [];
        $owners->select('c.id', 'c.tax_code', 'c.vat_number', 'c.recapito')->orderBy('c.id')->chunk(1000, function ($contacts) use (&$grouped): void {
            $channels = DB::table('contact_channels')->whereIn('contact_id', $contacts->pluck('id')->all())
                ->where('kind', 'phone')->where('status', '!=', 'Errato')->orderBy('id')->get(['contact_id', 'value'])->groupBy('contact_id');

            foreach ($contacts as $contact) {
                $pid = SisterText::normalizedCF(trim((string) ($contact->tax_code ?: $contact->vat_number)));
                if (! preg_match(self::VALID_PID, $pid)) {
                    continue;
                }

                $recapito = SisterText::extractedContacts((string) $contact->recapito);
                $hasPhone = collect($recapito)->contains(fn (string $value) => str_starts_with($value, 'tel:'));
                if (! $hasPhone) {
                    foreach ($channels[$contact->id] ?? [] as $channel) {
                        if (collect(SisterText::extractedContacts((string) $channel->value))->contains(fn (string $value) => str_starts_with($value, 'tel:'))) {
                            $hasPhone = true;
                            break;
                        }
                    }
                }

                $grouped[$pid] ??= ['hasPhone' => false];
                $grouped[$pid]['hasPhone'] = $grouped[$pid]['hasPhone'] || $hasPhone;
            }
        });

        $rows = [];
        foreach ($grouped as $pid => $details) {
            if (! $details['hasPhone']) {
                $rows[] = [preg_match('/^\d{11}$/', $pid) ? 'CF numerico / P.IVA' : 'Codice fiscale', $pid];
            }
        }
        usort($rows, fn (array $a, array $b): int => CensusQuery::collate($a[1], $b[1]));

        $cell = static function (string $value): string {
            if (preg_match('/^[=+@\-\t\r\n]/', $value)) {
                $value = "'".$value;
            }

            return '"'.str_replace('"', '""', $value).'"';
        };

        return "\xEF\xBB\xBF".collect([['Tipo', 'Codice fiscale / P.IVA'], ...$rows])
            ->map(fn (array $row): string => implode(';', array_map($cell, $row)))->implode("\r\n")."\r\n";
    }
}
