<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\PropertyRequest;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->crm2 = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

function ca_save(AgencyMembership $actor, array $input, ?Contact $client = null): Contact
{
    test()->actingAs($actor->user);

    return app(SaveClient::class)->handle($actor, $input, $client);
}

function ca_rejected(Closure $command, int $status, string $message): void
{
    try {
        $command();
    } catch (CommandRejected $e) {
        expect($e->status())->toBe($status)->and($e->getMessage())->toContain($message);

        return;
    }
    test()->fail('CommandRejected not thrown: '.$message);
}

function ca_counts(): array
{
    return [Contact::query()->withoutGlobalScopes()->count(), ClientProfile::query()->withoutGlobalScopes()->count(),
        PropertyRequest::query()->withoutGlobalScopes()->count(), AuditEvent::query()->count()];
}

it('creates a client with primary channels, creator and referent, audited once', function () {
    $client = ca_save($this->crm, ['name' => ' Mario Rossi ', 'phone' => '+39 333 1234567', 'email' => 'mario@example.it', 'consent_practice' => true]);
    $profile = $client->clientProfile;

    expect($client->display_name)->toBe('Mario Rossi')->and($client->origin)->toBe('manual')
        ->and($client->primaryPhone->value)->toBe('+39 333 1234567')
        ->and($client->primaryEmail->value)->toBe('mario@example.it')
        ->and($profile->agent_user_id)->toBe($this->crm->user_id)
        ->and($profile->created_by_user_id)->toBe($this->crm->user_id)
        ->and($profile->status)->toBe('Nuovo')->and($profile->preferred_channel)->toBe('Telefono')
        ->and($profile->source)->toBeNull()
        ->and($profile->consent_practice)->toBeTrue()->and($profile->consents_updated_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'client.save')->count())->toBe(1);
});

it('validates contacts in the gestionale order with the exact messages', function (array $input, string $message) {
    $before = ca_counts();
    ca_rejected(fn () => ca_save($this->crm, $input), 400, $message);
    expect(ca_counts())->toBe($before);
})->with([
    [['name' => ' ', 'phone' => 'x'], 'Inserisci il nome del cliente.'],
    [['name' => 'A', 'phone' => '12345'], 'Inserisci un telefono con 6–15 cifre. Sono ammessi +, spazi, parentesi e trattini.'],
    [['name' => 'A', 'phone' => '333-abc-4567'], 'Inserisci un telefono con 6–15 cifre.'],
    [['name' => 'A', 'email' => 'mario@'], 'Controlla l’indirizzo email, per esempio nome@example.it.'],
    [['name' => 'A'], 'Inserisci almeno un recapito: telefono oppure email.'],
    [['name' => 'A', 'phone' => '3331234567', 'status' => 'Inventato'], 'Stato cliente non valido.'],
    [['name' => 'A', 'phone' => '3331234567', 'preferred_channel' => 'Piccione'], 'Canale preferito non valido.'],
    [['name' => 'A', 'phone' => '3331234567', 'investor' => 'sì'], 'Profilo investitore non valido.'],
    [['name' => 'A', 'phone' => '3331234567', 'investor' => ['active' => true, 'min' => -1]], 'Budget investitore non valido.'],
    [['name' => 'A', 'phone' => '3331234567', 'investor' => ['active' => true, 'min' => 5, 'max' => 1]], 'Il budget minimo supera il massimo.'],
    [['name' => 'A', 'phone' => '3331234567', 'investor' => ['active' => true, 'groups' => ['C5']]], 'Controlla i criteri dell’investitore.'],
]);

it('does not re-validate unchanged historical values', function () {
    $client = ca_save($this->admin, ['name' => 'Storico', 'email' => 'ok@example.it']);
    $client->primaryEmail->forceFill(['value' => 'vecchio formato@example'])->saveQuietly();
    $client->refresh();

    $saved = ca_save($this->admin, ['notes' => 'Richiamare', 'expected_updated_at' => Commands::revision($client->clientProfile)], $client);
    expect($saved->clientProfile->notes)->toBe('Richiamare');
});

it('warns about duplicates on the whole agency, primary channels only, and allows explicit distinct confirmation', function () {
    $theirs = ca_save($this->crm2, ['name' => 'Anna Bianchi', 'phone' => '333 765 4321']);
    $before = ca_counts();

    ca_rejected(fn () => ca_save($this->crm, ['name' => 'Anna B.', 'phone' => '+39 333 7654321']), 409, 'Possibile cliente duplicato');
    expect(ca_counts())->toBe($before);

    // Archived clients count too.
    $theirs->clientProfile->forceFill(['lifecycle_state' => 'archived', 'lifecycle_at' => now()])->save();
    ca_rejected(fn () => ca_save($this->crm, ['name' => 'Anna B.', 'phone' => '3337654321']), 409, 'Possibile cliente duplicato');

    $copy = ca_save($this->crm, ['name' => 'Anna B.', 'phone' => '3337654321', 'confirm_duplicate' => true]);
    expect($copy->id)->not->toBe($theirs->id);

    // A name-only match never blocks client.save.
    expect(ca_save($this->crm, ['name' => 'Anna Bianchi', 'email' => 'altra@example.it'])->exists)->toBeTrue();
});

it('forces the referent for crm and lets the admin pick only an active member', function () {
    $client = ca_save($this->crm, ['name' => 'Luca', 'phone' => '3330000001', 'agent_user_id' => $this->crm2->user_id]);
    expect($client->clientProfile->agent_user_id)->toBe($this->crm->user_id);

    $byAdmin = ca_save($this->admin, ['name' => 'Sara', 'phone' => '3330000002']);
    expect($byAdmin->clientProfile->agent_user_id)->toBe($this->admin->user_id)->and($byAdmin->clientProfile->next_action)->toBeNull();

    $this->crm2->forceFill(['deactivated_at' => now()])->save();
    ca_rejected(fn () => ca_save($this->admin, ['name' => 'Ugo', 'phone' => '3330000003', 'agent_user_id' => $this->crm2->user_id]), 400, 'Scegli un referente attivo.');
});

it('moves the requests with the referent and keeps the original creator', function () {
    $client = ca_save($this->crm, ['name' => 'Piero', 'phone' => '3330000004']);
    $request = PropertyRequest::factory()->forClient($client->clientProfile)->create();

    $saved = ca_save($this->admin, ['agent_user_id' => $this->crm2->user_id, 'expected_updated_at' => Commands::revision($client->clientProfile)], $client);

    expect($saved->clientProfile->agent_user_id)->toBe($this->crm2->user_id)
        ->and($saved->clientProfile->created_by_user_id)->toBe($this->crm->user_id)
        ->and($request->fresh()->agent_user_id)->toBe($this->crm2->user_id);

    $this->actingAs($this->crm2->user);
    expect(PropertyRequest::query()->visibleTo($this->crm2)->pluck('id')->all())->toBe([$request->id])
        ->and(PropertyRequest::query()->visibleTo($this->crm)->count())->toBe(0);
});

it('keeps referent and work note when only the notes change', function () {
    $client = ca_save($this->admin, ['name' => 'Nota', 'phone' => '3330000005', 'agent_user_id' => $this->crm->user_id]);
    $client = ca_save($this->admin, ['next_action' => 'Chiamare lunedì', 'expected_updated_at' => Commands::revision($client->clientProfile)], $client);
    $client = ca_save($this->admin, ['notes' => 'Solo note', 'expected_updated_at' => Commands::revision($client->clientProfile)], $client);

    expect($client->clientProfile->agent_user_id)->toBe($this->crm->user_id)
        ->and($client->clientProfile->next_action)->toBe('Chiamare lunedì');
});

it('deletes a cleared channel and stores long contact time and source (4000)', function () {
    $client = ca_save($this->admin, ['name' => 'Canali', 'phone' => '3330000006', 'email' => 'c@example.it']);
    $client = ca_save($this->admin, ['phone' => '', 'contact_time' => str_repeat('m', 5000), 'source' => str_repeat('s', 300),
        'expected_updated_at' => Commands::revision($client->clientProfile)], $client);

    expect($client->primaryPhone)->toBeNull()->and($client->primaryEmail->value)->toBe('c@example.it')
        ->and(mb_strlen($client->clientProfile->contact_time))->toBe(4000)
        ->and(mb_strlen($client->clientProfile->source))->toBe(300);
});

it('stores every investor category of the UI and reads it back', function () {
    $groups = array_keys(ClientProfile::INVESTOR_GROUPS);
    $client = ca_save($this->admin, ['name' => 'Investitore', 'phone' => '3330000007',
        'investor' => ['active' => true, 'min' => 100000, 'max' => 500000, 'zones' => ' Centro ', 'groups' => $groups, 'opportunity' => ' Reddito ']]);
    $profile = $client->clientProfile->fresh();

    expect($profile->is_investor)->toBeTrue()->and($profile->investor_groups)->toBe($groups)
        ->and($profile->investor_zones)->toBe('Centro')->and($profile->investor_opportunity)->toBe('Reddito')
        ->and((float) $profile->investor_budget_max)->toBe(500000.0);
});

it('requires and checks the revision on edits (428 / 409), nothing written', function () {
    $client = ca_save($this->admin, ['name' => 'Rev', 'phone' => '3330000008']);
    $revision = Commands::revision($client->clientProfile);

    ca_rejected(fn () => ca_save($this->admin, ['notes' => 'x'], $client), 428, 'Ricarica la scheda prima di salvare.');
    ca_save($this->admin, ['notes' => 'prima', 'expected_updated_at' => $revision], $client->fresh());
    ca_rejected(fn () => ca_save($this->admin, ['notes' => 'seconda', 'expected_updated_at' => $revision], $client->fresh()), 409, 'I dati sono cambiati. Aggiorna la vista e riprova.');
    expect($client->clientProfile->fresh()->notes)->toBe('prima');
});

it('blocks scouts and crm on clients of others', function () {
    $client = ca_save($this->crm, ['name' => 'Mio', 'phone' => '3330000009']);

    ca_rejected(fn () => ca_save($this->scout, ['name' => 'X', 'phone' => '3330000010']), 403, 'Operazione non consentita all’Operatore 1.');
    ca_rejected(fn () => ca_save($this->crm2, ['notes' => 'x', 'expected_updated_at' => Commands::revision($client->clientProfile)], $client), 403, 'Record non accessibile con questo ruolo.');
});

it('replays a creation with the same idempotency key', function () {
    $first = ca_save($this->crm, ['name' => 'Idem', 'phone' => '3330000011', 'idempotency_key' => 'client-save-0001']);
    $again = ca_save($this->crm, ['name' => 'Idem', 'phone' => '3330000011', 'idempotency_key' => 'client-save-0001']);

    expect($again->id)->toBe($first->id)->and(Contact::query()->count())->toBe(1);
});

it('archives, removes with confirmation and restores clients (record.lifecycle)', function () {
    $client = ca_save($this->crm, ['name' => 'Ciclo', 'phone' => '3330000012']);
    $profile = $client->clientProfile;
    $lifecycle = app(SetRecordLifecycle::class);

    $lifecycle->handle($this->crm, $profile, 'archive');
    expect($profile->fresh()->lifecycle_state)->toBe('archived')->and($profile->fresh()->lifecycle_reason)->toBe('Archiviazione reversibile');

    ca_rejected(fn () => $lifecycle->handle($this->crm, $profile, 'remove', true), 403, 'La rimozione dalla lista richiede la conferma del Responsabile.');
    ca_rejected(fn () => $lifecycle->handle($this->admin, $profile, 'remove'), 403, 'La rimozione dalla lista richiede la conferma del Responsabile.');
    ca_rejected(fn () => $lifecycle->handle($this->crm2, $profile, 'archive'), 403, 'Scheda non accessibile con questo ruolo.');
    ca_rejected(fn () => $lifecycle->handle($this->scout, $profile, 'archive'), 403, 'Scheda non accessibile con questo ruolo.');
    ca_rejected(fn () => $lifecycle->handle($this->admin, $profile, 'delete'), 400, 'Operazione sulla scheda non valida.');

    $lifecycle->handle($this->admin, $profile->fresh(), 'remove', true);
    expect($profile->fresh()->lifecycle_state)->toBe('removed');

    $lifecycle->handle($this->admin, $profile->fresh(), 'restore');
    expect($profile->fresh()->only(['lifecycle_state', 'lifecycle_at', 'lifecycle_reason']))->toBe(['lifecycle_state' => null, 'lifecycle_at' => null, 'lifecycle_reason' => null])
        ->and(AuditEvent::query()->where('action', 'record.lifecycle')->count())->toBe(3);
});
