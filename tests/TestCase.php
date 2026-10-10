<?php

namespace Tests;

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most feature tests exercise an already-entered Gestionale session. Seed the single available
     * work profile there; tests for the entry screen explicitly clear it with withoutGestionaleProfile().
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $sameUser = (string) ($this->app['auth']->guard($guard)->user()?->getAuthIdentifier() ?? '')
            === (string) $user->getAuthIdentifier();

        parent::actingAs($user, $guard);

        if (! $sameUser) {
            $memberships = AgencyMembership::query()->where('user_id', $user->getAuthIdentifier())
                ->active()->whereHas('agency', fn ($query) => $query->active())->get(['agency_id', 'role']);
            $session = [CurrentAgency::SESSION_KEY => null];
            foreach ($memberships as $membership) {
                $session[CurrentAgency::PROFILE_KEY.'.'.$membership->agency_id] = $memberships->count() === 1 ? $membership->role : null;
            }
            $this->withSession($session);
        }

        return $this;
    }

    public function withoutGestionaleProfile(AgencyMembership $membership): static
    {
        return $this->withSession([CurrentAgency::PROFILE_KEY.'.'.$membership->agency_id => null]);
    }

    /**
     * RefreshDatabase wipes the database. With a cached config (php artisan optimize)
     * phpunit.xml is ignored and the tests would run against the development database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if ($app->environment() !== 'testing' || $database !== 'reko_test') {
            throw new RuntimeException(
                "Test fermati: userebbero il database [{$database}] invece di [reko_test]. "
                .'Probabilmente la configurazione è in cache: esegui php artisan optimize:clear.'
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
