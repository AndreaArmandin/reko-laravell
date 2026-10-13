<?php

namespace App\Gestionale\Inbound;

use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Stores one received message in a user's personal inbox. Shared by every way in
 * (mailbox script, mail-forwarding service) so they behave the same.
 */
final class InboundInbox
{
    /** Gmail sends the confirmation code of a new forwarding address from here. */
    public const FORWARDING_CONFIRMATION_SENDER = 'forwarding-noreply@google.com';

    /**
     * @param  array{messageId: string, from: string, subject?: ?string, date?: ?string, body?: ?string, source?: ?string}  $data
     * @return array{saved: bool, duplicate?: bool, ignored?: bool, id?: int}
     */
    public static function receive(User $user, array $data, bool $allowForwardingConfirmation = false): array
    {
        $confirmation = $allowForwardingConfirmation && InboundMail::address($data['from']) === self::FORWARDING_CONFIRMATION_SENDER;

        // Not stored, but answered as handled so the sender never retries it.
        if (! $confirmation && ! InboundMail::senderAllowed($data['from'], $user->inbound_allowed_senders)) {
            return ['saved' => false, 'ignored' => true];
        }

        $body = (string) ($data['body'] ?? '');
        $found = InboundMail::extract($body);
        $received = rescue(fn () => Carbon::parse($data['date'] ?? ''), now(), false);

        $email = InboundEmail::query()->firstOrCreate(
            ['user_id' => $user->id, 'message_id' => $data['messageId']],
            [
                'source' => $data['source'] ?? 'email',
                'sender' => $data['from'],
                'subject' => (string) ($data['subject'] ?? ''),
                'body' => $body,
                'raw' => $data,
                'listing_url' => $found['url'],
                'phone' => $found['phone'],
                'received_at' => $received,
            ],
        );

        return ['saved' => $email->wasRecentlyCreated, 'duplicate' => ! $email->wasRecentlyCreated, 'id' => $email->id];
    }
}
