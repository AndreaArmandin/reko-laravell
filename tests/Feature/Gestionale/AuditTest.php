<?php

use App\Gestionale\Audit;
use App\Gestionale\CrossAgencyWrite;
use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use App\Models\Concerns\AgencyScope;
use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->member = AgencyMembership::factory()->admin()->create();
    $this->actingAs($this->member->user);
    $this->contact = Contact::query()->create(['display_name' => 'Mario Rossi']);
});

it('records who did what, on which record, in the current agency', function () {
    $event = app(Audit::class)->record('owner.save', $this->contact, ['before' => ['notes' => null], 'after' => ['notes' => 'x']], ' correzione ');

    expect($event->fresh())
        ->agency_id->toBe($this->member->agency_id)
        ->user_id->toBe($this->member->user_id)
        ->auditable_type->toBe('contact')
        ->auditable_id->toBe($this->contact->id)
        ->reason->toBe('correzione')
        ->payload->toEqual(['before' => ['notes' => null], 'after' => ['notes' => 'x']]);

    expect($event->auditable->is($this->contact))->toBeTrue();
});

it('is append-only, also for raw SQL', function () {
    $event = app(Audit::class)->record('client.save', $this->contact);

    expect(fn () => $event->update(['action' => 'client.delete']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->delete()))->toThrow(QueryException::class);
});

it('keeps the trail when the author account is deleted', function () {
    $other = AgencyMembership::factory()->admin()->create(['agency_id' => $this->member->agency_id]);
    $event = app(Audit::class)->record('settings.save', null, [], null, $other->user);

    $other->delete();
    $other->user->delete();

    expect(AuditEvent::query()->find($event->id))->not->toBeNull()
        ->user_id->toBeNull()
        ->action->toBe('settings.save');
});

it('refuses events on records of another agency and hides other agencies\' trail', function () {
    $foreign = AgencyMembership::factory()->admin()->create();
    $foreignContact = app(CurrentAgency::class)->runAs($foreign->agency, fn () => Contact::factory()->create(['agency_id' => $foreign->agency_id]));

    expect(fn () => app(Audit::class)->record('owner.save', $foreignContact))->toThrow(CrossAgencyWrite::class);

    app(Audit::class)->record('client.save', $this->contact);
    $this->actingAs($foreign->user);
    app(Audit::class)->record('client.save', $foreignContact);

    expect(AuditEvent::query()->count())->toBe(1)
        ->and(AuditEvent::withoutGlobalScope(AgencyScope::class)->count())->toBe(2);
});

it('accepts platform events without an agency and validates the action name', function () {
    auth()->logout();
    $event = app(Audit::class)->record('platform.agency.deactivate');
    expect($event->agency_id)->toBeNull();

    expect(fn () => DB::transaction(fn () => app(Audit::class)->record('Not An Action')))->toThrow(QueryException::class);
});
