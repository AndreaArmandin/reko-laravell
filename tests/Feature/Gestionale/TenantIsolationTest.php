<?php

use App\Gestionale\CrossAgencyWrite;
use App\Gestionale\CurrentAgency;
use App\Gestionale\MissingAgencyContext;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Concerns\AgencyScope;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\LegacyEntityRef;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->a = AgencyMembership::factory()->admin()->create();
    $this->b = AgencyMembership::factory()->admin()->create();
    $this->contactA = Contact::factory()->create(['agency_id' => $this->a->agency_id, 'display_name' => 'Mario Rossi']);
    $this->contactB = Contact::factory()->create(['agency_id' => $this->b->agency_id, 'display_name' => 'Anna Bianchi']);
});

it('shows each agency only its own rows', function () {
    $this->actingAs($this->a->user);
    expect(Contact::query()->pluck('display_name')->all())->toBe(['Mario Rossi'])
        ->and(Contact::query()->find($this->contactB->id))->toBeNull();

    $this->actingAs($this->b->user);
    expect(Contact::query()->pluck('display_name')->all())->toBe(['Anna Bianchi']);
});

it('fails closed without a current agency', function () {
    expect(Contact::query()->count())->toBe(0)
        ->and(Contact::withoutGlobalScope(AgencyScope::class)->count())->toBe(2);

    expect(fn () => Contact::query()->create(['display_name' => 'Senza agenzia']))->toThrow(MissingAgencyContext::class);
});

it('fills agency_id from the context and ignores a mass-assigned one', function () {
    $this->actingAs($this->a->user);

    $contact = Contact::query()->create(['display_name' => 'Luca Verdi', 'agency_id' => $this->b->agency_id]);

    expect($contact->agency_id)->toBe($this->a->agency_id);
});

it('refuses to write rows of another agency or to move a row between agencies', function () {
    $this->actingAs($this->a->user);

    $foreign = new Contact(['display_name' => 'Intruso']);
    $foreign->agency_id = $this->b->agency_id;
    expect(fn () => $foreign->save())->toThrow(CrossAgencyWrite::class);

    $loaded = Contact::withoutGlobalScopes()->findOrFail($this->contactB->id);
    expect(fn () => $loaded->update(['notes' => 'modificato da A']))->toThrow(CrossAgencyWrite::class)
        ->and(fn () => $loaded->delete())->toThrow(CrossAgencyWrite::class);

    $this->contactA->agency_id = $this->b->agency_id;
    expect(fn () => $this->contactA->save())->toThrow(CrossAgencyWrite::class);
    expect(Contact::withoutGlobalScopes()->find($this->contactB->id)->notes)->toBeNull();
});

it('blocks cross-agency links in the database itself (composite foreign keys)', function () {
    // A channel of agency A pointing at a contact of agency B: raw SQL, no Eloquent guard.
    expect(fn () => DB::transaction(fn () => DB::table('contact_channels')->insert([
        'agency_id' => $this->a->agency_id, 'contact_id' => $this->contactB->id,
        'kind' => 'phone', 'value' => '3331234567', 'normalized_value' => '393331234567',
    ])))->toThrow(QueryException::class);

    // A client of agency A whose referent is a member of agency B only.
    expect(fn () => DB::transaction(fn () => DB::table('client_profiles')->insert([
        'agency_id' => $this->a->agency_id, 'contact_id' => $this->contactA->id, 'agent_user_id' => $this->b->user_id,
    ])))->toThrow(QueryException::class);

    DB::table('client_profiles')->insert([
        'agency_id' => $this->a->agency_id, 'contact_id' => $this->contactA->id, 'agent_user_id' => $this->a->user_id,
    ]);
    expect(DB::table('client_profiles')->count())->toBe(1);
});

it('never cascades an agency delete into its data', function () {
    $restrictive = collect(DB::select(<<<'SQL'
        SELECT cl.relname AS child, c.confdeltype AS on_delete
        FROM pg_constraint c
        JOIN pg_class cl ON cl.oid = c.conrelid
        WHERE c.contype = 'f' AND c.confrelid = 'agencies'::regclass
    SQL));

    // Only the membership links may go with an empty agency; everything else is RESTRICT ('r').
    $cascading = $restrictive->where('on_delete', 'c')->pluck('child')->unique()->values()->all();
    expect($cascading)->toBe(['agency_memberships'])
        ->and($restrictive->whereNotIn('on_delete', ['r', 'c'])->all())->toBe([]);

    expect(fn () => DB::transaction(fn () => Agency::query()->whereKey($this->a->agency_id)->delete()))
        ->toThrow(QueryException::class);
    expect(Contact::withoutGlobalScopes()->whereKey($this->contactA->id)->exists())->toBeTrue();
});

it('blocks removing a membership that is still the referent of clients', function () {
    ClientProfile::factory()->agent($this->a)->create(['contact_id' => $this->contactA->id]);

    expect(fn () => DB::transaction(fn () => $this->a->delete()))->toThrow(QueryException::class);
});

it('isolates every agency-owned model, not only contacts', function () {
    app(CurrentAgency::class)->runAs($this->b->agency, function () {
        LegacyEntityRef::query()->create([
            'source_system' => 'gestionale', 'legacy_type' => 'client', 'legacy_id' => 'c-1',
            'entity_type' => 'contact', 'entity_id' => $this->contactB->id,
        ]);
        ContactChannel::factory()->create(['contact_id' => $this->contactB->id]);
    });

    $this->actingAs($this->a->user);
    expect(LegacyEntityRef::query()->count())->toBe(0)
        ->and(ContactChannel::query()->count())->toBe(0);

    $this->actingAs($this->b->user);
    expect(LegacyEntityRef::query()->count())->toBe(1)
        ->and(ContactChannel::query()->count())->toBe(1);
});
