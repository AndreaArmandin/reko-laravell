<?php

namespace App\Http\Controllers\Gestionale;

use App\Gestionale\CurrentAgency;
use App\Gestionale\MissingAgencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Opens the gestionale of one of the user's agencies (agency picker, dashboard "Le tue agenzie").
 * POST + CSRF: the choice is stored in the session and read by CurrentAgency.
 */
class EnterAgencyController extends Controller
{
    public function __invoke(int $agency, CurrentAgency $current): RedirectResponse
    {
        try {
            $current->switchTo($agency);
        } catch (MissingAgencyContext) {
            return redirect()->route('gestionale.choose')->with('gestionale.error', 'Non sei un membro attivo di questa agenzia.');
        }

        return redirect()->route('gestionale.home');
    }
}
