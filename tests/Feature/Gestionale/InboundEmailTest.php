<?php

use App\Gestionale\Inbound\InboundMail;
use App\Models\InboundEmail;
use App\Models\User;
use Livewire\Livewire;

const MAIL_TOKEN = 'token-di-prova-token-di-prova-token-di-prova-12';

function userWithToken(string $senders = 'immobiliare.it'): User
{
    return User::factory()->create(['inbound_token_hash' => hash('sha256', MAIL_TOKEN), 'inbound_allowed_senders' => $senders]);
}

function mailPayload(array $over = []): array
{
    return array_merge([
        'messageId' => '<abc@mail>',
        'from' => 'Immobiliare.it <noreply@mail.immobiliare.it>',
        'subject' => 'Nuovo contatto',
        'date' => '2026-10-12T10:00:00Z',
        'body' => 'Vedi https://www.immobiliare.it/annunci/123456/ chiama 333 1234567',
    ], $over);
}

function postMail(array $payload, ?string $token = MAIL_TOKEN)
{
    return test()->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])->postJson('/api/inbound-email', $payload);
}

it('matches senders by address or domain, never by lookalike', function () {
    expect(InboundMail::senderAllowed('x <a@immobiliare.it>', 'immobiliare.it'))->toBeTrue()
        ->and(InboundMail::senderAllowed('a@mail.immobiliare.it', 'immobiliare.it'))->toBeTrue()
        ->and(InboundMail::senderAllowed('a@evilimmobiliare.it', 'immobiliare.it'))->toBeFalse()
        ->and(InboundMail::senderAllowed('a@immobiliare.it.evil.com', 'immobiliare.it'))->toBeFalse()
        ->and(InboundMail::senderAllowed('me@gmail.com', 'me@gmail.com'))->toBeTrue()
        ->and(InboundMail::senderAllowed('other@gmail.com', 'me@gmail.com'))->toBeFalse()
        ->and(InboundMail::senderAllowed('not an address', 'immobiliare.it'))->toBeFalse()
        ->and(InboundMail::senderAllowed('a@immobiliare.it', null))->toBeFalse();
});

it('stores a mail for the owner of the key, once', function () {
    $user = userWithToken();
    $other = User::factory()->create();

    postMail(mailPayload())->assertOk()->assertJson(['saved' => true]);
    postMail(mailPayload())->assertOk()->assertJson(['saved' => false, 'duplicate' => true]);

    expect(InboundEmail::count())->toBe(1)
        ->and($user->inboundEmails()->count())->toBe(1)->and($other->inboundEmails()->count())->toBe(0)
        ->and($user->inboundEmails()->first()->listing_url)->toBe('https://www.immobiliare.it/annunci/123456/')
        ->and($user->inboundEmails()->first()->raw['messageId'])->toBe('<abc@mail>');
});

it('refuses a missing or wrong key and stores nothing', function () {
    userWithToken();
    postMail(mailPayload(), null)->assertUnauthorized();
    postMail(mailPayload(), 'sbagliato')->assertUnauthorized();

    expect(InboundEmail::count())->toBe(0);
});

it('ignores foreign senders without storing them, and needs configured senders', function () {
    userWithToken();
    postMail(mailPayload(['from' => 'spam@evil.com']))->assertOk()->assertJson(['ignored' => true]);
    expect(InboundEmail::count())->toBe(0);

    User::query()->update(['inbound_allowed_senders' => null]);
    postMail(mailPayload())->assertStatus(503);
});

it('validates the payload', function () {
    userWithToken();
    postMail(['from' => 'a@immobiliare.it'])->assertStatus(422);
    postMail(mailPayload(['body' => str_repeat('a', 100_001)]))->assertStatus(422);
});

it('lets the user generate a key from the page, storing only its hash', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = Livewire::test('pages::inbox.index')->set('senders', 'immobiliare.it')->call('generateKey');
    $key = $page->get('newKey');

    expect($key)->toHaveLength(48)->and($user->refresh()->inbound_token_hash)->toBe(hash('sha256', $key))
        ->and($user->inbound_allowed_senders)->toBe('immobiliare.it');
    postMail(mailPayload(), $key)->assertOk();

    // A new key replaces the old one.
    $page->call('generateKey');
    postMail(mailPayload(['messageId' => '<2@mail>']), $key)->assertUnauthorized();
});

it('requires senders before generating a key', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::inbox.index')->set('senders', '')->call('generateKey')->assertHasErrors('senders');
    expect($user->refresh()->inbound_token_hash)->toBeNull();
});

it('shows each user only their own mails, escaped, and keeps guests out', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $mine->inboundEmails()->create(['message_id' => 'a', 'sender' => 'x@immobiliare.it', 'subject' => 'Soggetto visibile', 'body' => '<script>alert(1)</script>', 'raw' => [], 'received_at' => now()]);
    $theirs->inboundEmails()->create(['message_id' => 'b', 'sender' => 'x@immobiliare.it', 'subject' => 'Di un altro utente', 'body' => 'x', 'raw' => [], 'received_at' => now()]);

    $this->get(route('inbox'))->assertRedirect(route('login'));
    $this->actingAs($mine)->get(route('inbox'))->assertOk()
        ->assertSee('Soggetto visibile')->assertDontSee('Di un altro utente')->assertDontSee('<script>alert(1)</script>', false);
});

function postmarkPayload(string $code, array $over = []): array
{
    return array_merge([
        'MessageID' => 'pm-1',
        'MailboxHash' => $code,
        'From' => 'noreply@mail.immobiliare.it',
        'FromFull' => ['Email' => 'noreply@mail.immobiliare.it', 'Name' => 'Immobiliare.it'],
        'Subject' => 'Nuovo contatto',
        'Date' => 'Mon, 12 Oct 2026 10:00:00 +0200',
        'TextBody' => 'Vedi https://www.immobiliare.it/annunci/123456/ chiama 333 1234567',
    ], $over);
}

function postmarkPost(array $payload, ?array $auth = ['reko-user-1', 'una-password-lunga-di-prova-1234'])
{
    config(['services.postmark_inbound.username' => 'reko-user-1', 'services.postmark_inbound.password' => 'una-password-lunga-di-prova-1234']);
    $headers = $auth === null ? [] : ['Authorization' => 'Basic '.base64_encode($auth[0].':'.$auth[1])];

    return test()->withHeaders($headers)->postJson('/api/inbound-email/postmark', $payload);
}

it('stores a Postmark message for the owner of the address code', function () {
    $user = User::factory()->create(['inbound_code' => 'codiceprova1234567890', 'inbound_allowed_senders' => 'immobiliare.it']);
    $other = User::factory()->create(['inbound_code' => 'altrocodice123456789', 'inbound_allowed_senders' => 'immobiliare.it']);

    postmarkPost(postmarkPayload('codiceprova1234567890'))->assertOk()->assertJson(['saved' => true]);
    postmarkPost(postmarkPayload('codiceprova1234567890'))->assertOk()->assertJson(['duplicate' => true]);

    $mail = $user->inboundEmails()->sole();
    expect($other->inboundEmails()->count())->toBe(0)->and($mail->source)->toBe('postmark')
        ->and($mail->sender)->toBe('Immobiliare.it <noreply@mail.immobiliare.it>')
        ->and($mail->listing_url)->toBe('https://www.immobiliare.it/annunci/123456/');
});

it('refuses Postmark calls with wrong or missing credentials, or without configuration', function () {
    User::factory()->create(['inbound_code' => 'codiceprova1234567890', 'inbound_allowed_senders' => 'immobiliare.it']);

    postmarkPost(postmarkPayload('codiceprova1234567890'), null)->assertUnauthorized();
    postmarkPost(postmarkPayload('codiceprova1234567890'), ['reko-user-1', 'sbagliata'])->assertUnauthorized();
    config(['services.postmark_inbound.username' => '', 'services.postmark_inbound.password' => '']);
    test()->postJson('/api/inbound-email/postmark', postmarkPayload('codiceprova1234567890'))->assertStatus(503);

    expect(InboundEmail::count())->toBe(0);
});

it('ignores an unknown address code and foreign senders', function () {
    User::factory()->create(['inbound_code' => 'codiceprova1234567890', 'inbound_allowed_senders' => 'immobiliare.it']);

    postmarkPost(postmarkPayload('nonesiste'))->assertOk()->assertJson(['ignored' => true]);
    postmarkPost(postmarkPayload('codiceprova1234567890', ['From' => 'spam@evil.com', 'FromFull' => ['Email' => 'spam@evil.com', 'Name' => '']]))->assertOk()->assertJson(['ignored' => true]);

    expect(InboundEmail::count())->toBe(0);
});

it('keeps the Gmail forwarding confirmation so the user can read the code', function () {
    $user = User::factory()->create(['inbound_code' => 'codiceprova1234567890', 'inbound_allowed_senders' => 'immobiliare.it']);

    postmarkPost(postmarkPayload('codiceprova1234567890', [
        'MessageID' => 'pm-conf', 'From' => 'forwarding-noreply@google.com',
        'FromFull' => ['Email' => 'forwarding-noreply@google.com', 'Name' => 'Gmail Team'],
        'Subject' => 'Conferma inoltro', 'TextBody' => 'Codice di conferma: 123456',
    ]))->assertOk()->assertJson(['saved' => true]);

    expect($user->inboundEmails()->sole()->body)->toContain('123456');
    // The key-based endpoint never accepts that exception.
    $user->forceFill(['inbound_token_hash' => hash('sha256', MAIL_TOKEN)])->save();
    postMail(mailPayload(['from' => 'Gmail Team <forwarding-noreply@google.com>', 'messageId' => 'x']))->assertJson(['ignored' => true]);
});

it('shows the personal forwarding address once Postmark is configured', function () {
    config(['services.postmark_inbound.address' => 'abc123@inbound.postmarkapp.com']);
    $this->actingAs($user = User::factory()->create());

    $page = Livewire::test('pages::inbox.index');
    $code = $user->refresh()->inbound_code;

    expect($code)->toHaveLength(20);
    $page->assertSee('abc123+'.$code.'@inbound.postmarkapp.com');
});
