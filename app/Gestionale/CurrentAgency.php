<?php

namespace App\Gestionale;

use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The agency the current request (or job) works for.
 *
 * Web: resolved from the logged-in user's ACTIVE memberships of ACTIVE agencies. A user with
 * one membership gets it automatically; a user with several must pick one (switchTo), stored
 * in the session. No silent "first agency": writing into the wrong agency is worse than
 * asking. Jobs, commands and the legacy import use runAs() explicitly.
 *
 * Registered as a scoped singleton, so it is reset between requests and queued jobs.
 */
final class CurrentAgency
{
    public const SESSION_KEY = 'gestionale.current_agency_id';

    private ?Agency $override = null;

    private bool $resolved = false;

    private ?int $resolvedForUserId = null;

    private ?AgencyMembership $membership = null;

    public function __construct(private readonly AuthFactory $auth) {}

    public function id(): ?int
    {
        return $this->agency()?->getKey();
    }

    public function agency(): ?Agency
    {
        return $this->override ?? $this->membership()?->agency;
    }

    /** Membership of the logged-in user in the current agency; null in system context (runAs). */
    public function membership(): ?AgencyMembership
    {
        if ($this->override !== null) {
            return null;
        }

        $user = $this->user();

        if (! $this->resolved || $this->resolvedForUserId !== $user?->getKey()) {
            $this->membership = $user ? $this->resolve($user) : null;
            $this->resolved = true;
            $this->resolvedForUserId = $user?->getKey();
        }

        return $this->membership;
    }

    public function require(): Agency
    {
        return $this->agency() ?? throw new MissingAgencyContext('Nessuna agenzia corrente per questa operazione.');
    }

    /** Lets a user with several agencies choose one. Only an active membership of an active agency. */
    public function switchTo(Agency|int $agency): AgencyMembership
    {
        $user = $this->user() ?? throw new MissingAgencyContext('Accesso richiesto.');
        $agencyId = $agency instanceof Agency ? $agency->getKey() : $agency;

        $membership = $this->activeMemberships($user)->firstWhere('agency_id', $agencyId)
            ?? throw new MissingAgencyContext('Non sei un membro attivo di questa agenzia.');

        $this->session()?->put(self::SESSION_KEY, $agencyId);
        $this->forget();

        return $membership;
    }

    /**
     * Runs a callback as a given agency, without a user membership (system context:
     * imports, jobs, console). The previous context is always restored.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Agency|int $agency, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $agency instanceof Agency ? $agency : Agency::query()->findOrFail($agency);

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function forget(): void
    {
        $this->resolved = false;
        $this->resolvedForUserId = null;
        $this->membership = null;
    }

    private function resolve(User $user): ?AgencyMembership
    {
        $memberships = $this->activeMemberships($user);
        $chosen = $this->session()?->get(self::SESSION_KEY);

        if ($chosen !== null && ($membership = $memberships->firstWhere('agency_id', (int) $chosen))) {
            return $membership;
        }

        return $memberships->count() === 1 ? $memberships->first() : null;
    }

    /**
     * Active memberships of the logged-in user in active agencies (agency picker).
     *
     * @return Collection<int, AgencyMembership>
     */
    public function available(): Collection
    {
        $user = $this->user();

        return $user ? $this->activeMemberships($user) : new Collection;
    }

    /** @return Collection<int, AgencyMembership> */
    private function activeMemberships(User $user)
    {
        return AgencyMembership::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->whereHas('agency', fn ($query) => $query->active())
            ->with('agency')
            ->orderBy('id')
            ->get();
    }

    private function user(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    private function session(): ?Session
    {
        $request = app('request');

        return $request instanceof Request && $request->hasSession() ? $request->session() : null;
    }
}
