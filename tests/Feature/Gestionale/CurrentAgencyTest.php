<?php

use App\Gestionale\CurrentAgency;
use App\Gestionale\MissingAgencyContext;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\User;
use Illuminate\Support\Facades\Route;

function currentAgency(): CurrentAgency
{
    return app(CurrentAgency::class);
}

it('has no agency for guests and users without memberships', function () {
    expect(currentAgency()->id())->toBeNull();

    $this->actingAs(User::factory()->create());
    expect(currentAgency()->id())->toBeNull()
        ->and(fn () => currentAgency()->require())->toThrow(MissingAgencyContext::class);
});

it('resolves the only active membership', function () {
    $membership = AgencyMembership::factory()->crm()->create();
    $this->actingAs($membership->user);

    expect(currentAgency()->id())->toBe($membership->agency_id)
        ->and(currentAgency()->membership()->is($membership))->toBeTrue();
});

it('ignores deactivated memberships and deactivated agencies', function () {
    $inactive = AgencyMembership::factory()->deactivated()->create();
    $this->actingAs($inactive->user);
    expect(currentAgency()->id())->toBeNull();

    $closed = AgencyMembership::factory()->create(['agency_id' => Agency::factory()->deactivated()]);
    $this->actingAs($closed->user);
    expect(currentAgency()->id())->toBeNull();
});

it('never picks an agency silently for a user with several, and switches only to own active ones', function () {
    $user = User::factory()->create();
    $a = AgencyMembership::factory()->admin()->create(['user_id' => $user->id]);
    $b = AgencyMembership::factory()->crm()->create(['user_id' => $user->id]);
    $foreign = Agency::factory()->create();

    $this->actingAs($user);
    request()->setLaravelSession(app('session.store'));

    expect(currentAgency()->id())->toBeNull();

    currentAgency()->switchTo($b->agency_id);
    expect(currentAgency()->id())->toBe($b->agency_id)->and(currentAgency()->membership()->role)->toBe('crm');

    currentAgency()->switchTo($a->agency);
    expect(currentAgency()->membership()->role)->toBe('admin');

    expect(fn () => currentAgency()->switchTo($foreign))->toThrow(MissingAgencyContext::class);
});

it('re-resolves when the logged-in user changes', function () {
    $one = AgencyMembership::factory()->create();
    $two = AgencyMembership::factory()->create();

    $this->actingAs($one->user);
    expect(currentAgency()->id())->toBe($one->agency_id);

    $this->actingAs($two->user);
    expect(currentAgency()->id())->toBe($two->agency_id);
});

it('runs system code as an agency and restores the previous context', function () {
    $membership = AgencyMembership::factory()->create();
    $other = Agency::factory()->create();
    $this->actingAs($membership->user);

    $inside = currentAgency()->runAs($other, fn () => [currentAgency()->id(), currentAgency()->membership()]);

    expect($inside)->toBe([$other->id, null])
        ->and(currentAgency()->id())->toBe($membership->agency_id);

    expect(fn () => currentAgency()->runAs($other, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(currentAgency()->id())->toBe($membership->agency_id);
});

it('protects gestionale routes with the agency middleware', function () {
    Route::middleware(['web', 'auth', 'agency'])->get('/_test/gestionale', fn () => 'ok');

    // Without a current agency the user is sent to the agency picker (JSON callers get 403).
    $this->actingAs(User::factory()->create())->get('/_test/gestionale')->assertRedirect(route('gestionale.choose'));
    $this->actingAs(User::factory()->create())->getJson('/_test/gestionale')->assertForbidden();

    // A platform admin without membership is not an agency admin.
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/_test/gestionale')->assertRedirect(route('gestionale.choose'));

    $this->actingAs(AgencyMembership::factory()->scout()->create()->user)->get('/_test/gestionale')->assertOk();
});
