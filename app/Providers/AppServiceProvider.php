<?php

namespace App\Providers;

use App\Gestionale\CurrentAgency;
use App\Http\Middleware\CheckUserIsAdmin;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\PropertyRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Reset per request and per queued job: the agency never leaks between them.
        $this->app->scoped(CurrentAgency::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Livewire::addPersistentMiddleware([CheckUserIsAdmin::class]);

        $this->configureGestionale();
    }

    /**
     * Gestionale (agency CRM) wiring.
     * - Stable morph aliases for audit_events / legacy_entity_refs (not class names, which change).
     *   Not enforced globally, so existing morphs elsewhere keep working.
     * - "agency-permission": optional permissions of the current membership (permissions.ts allowed()).
     */
    protected function configureGestionale(): void
    {
        Relation::morphMap([
            'agency' => Agency::class,
            'agency_membership' => AgencyMembership::class,
            'contact' => Contact::class,
            'contact_channel' => ContactChannel::class,
            'client_profile' => ClientProfile::class,
            'property_request' => PropertyRequest::class,
        ]);

        Gate::define('agency-permission', fn (User $user, string $permission): bool => (bool) app(CurrentAgency::class)
            ->membership()?->allows($permission));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
