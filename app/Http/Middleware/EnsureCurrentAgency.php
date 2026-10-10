<?php

namespace App\Http\Middleware;

use App\Gestionale\CurrentAgency;
use App\Gestionale\Navigation;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the /gestionale routes: the user needs an active membership of an active agency,
 * must choose an agency when they have several, and must choose a work profile. The picker pages
 * explain what to do
 * (JSON and Livewire requests, which cannot follow a redirect, get a plain 403: this is also
 * what a member sees when their membership was deactivated while the page was open).
 * users.is_admin grants nothing here.
 *
 * A section the role cannot open (Navigation::sectionsFor) answers 403 with the page message of
 * the old gestionale "Questa sezione non è accessibile con il tuo ruolo." Impostazioni is the exception:
 * the screen itself explains that the configuration belongs to the Responsabile.
 *
 * Registered as Livewire persistent middleware, so it also runs on every Livewire update.
 */
class EnsureCurrentAgency
{
    public function __construct(private readonly CurrentAgency $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $this->current->membership();

        if ($membership === null) {
            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, 'Nessuna agenzia attiva per questo account.');

            return redirect()->guest(route('gestionale.choose'));
        }

        // crm-entry.tsx asks for a work profile before opening every Gestionale section.
        // This also prevents direct links (for example, from Trova) from silently entering
        // an administrator session with unrestricted agency access.
        if ($this->current->workProfile() === null && $request->route()?->getName() !== 'gestionale.profile') {
            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, 'Scegli prima il profilo di lavoro.');

            if ($request->isMethod('GET') && $request->route()?->getName()) {
                $parameters = [];
                foreach ($request->route()->parameters() as $key => $value) {
                    $parameters[$key] = $value instanceof UrlRoutable ? $value->getRouteKey() : $value;
                }

                $request->session()->put('gestionale.profile_intended', [
                    'route' => $request->route()->getName(),
                    'parameters' => $parameters,
                    'query' => $request->query(),
                ]);
            }

            return redirect()->route('gestionale.profile');
        }

        $section = Navigation::sectionForRoute($request->route()?->getName());
        if ($section !== null && $section !== 'Impostazioni CRM' && ! in_array($section, Navigation::sectionsFor($membership), true)) {
            abort_if($request->expectsJson(), 403, 'Questa sezione non è accessibile con il tuo ruolo.');

            return response()->view('gestionale.blocked', [], 403);
        }

        return $next($request);
    }
}
