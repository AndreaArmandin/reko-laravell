<?php

namespace App\Http\Middleware;

use App\Gestionale\CurrentAgency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the /gestionale routes: the user needs an active membership of an active agency,
 * and must have chosen one when they have several. Otherwise the agency picker explains what to do
 * (JSON requests get 403). users.is_admin grants nothing here.
 */
class EnsureCurrentAgency
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->current->membership() === null) {
            abort_if($request->expectsJson(), 403, 'Nessuna agenzia attiva per questo account.');

            return redirect()->guest(route('gestionale.choose'));
        }

        return $next($request);
    }
}
