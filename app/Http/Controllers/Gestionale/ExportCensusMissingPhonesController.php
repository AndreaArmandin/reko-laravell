<?php

namespace App\Http\Controllers\Gestionale;

use App\Gestionale\Census\CensusMissingPhoneExport;
use App\Gestionale\Census\CensusScope;
use App\Gestionale\CurrentAgency;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportCensusMissingPhonesController extends Controller
{
    public function __invoke(CurrentAgency $current, CensusMissingPhoneExport $export): StreamedResponse
    {
        $membership = $current->membership() ?? abort(403, 'Nessuna agenzia attiva per questo account.');
        abort_unless(CensusScope::canUse($membership), 403, CensusScope::NOT_ALLOWED);

        $csv = $export->csv($membership);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'reko-codici-senza-telefono.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
