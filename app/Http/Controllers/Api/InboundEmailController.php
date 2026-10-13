<?php

namespace App\Http\Controllers\Api;

use App\Gestionale\Inbound\InboundInbox;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catcher for portal emails: a script in the user's own mailbox POSTs each message here with the
 * user's key as Bearer. The owner comes from the key, never from the payload.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->bearerToken();
        $user = $token === '' ? null : User::query()->where('inbound_token_hash', hash('sha256', $token))->first();

        if ($user === null || ! hash_equals((string) $user->inbound_token_hash, hash('sha256', $token))) {
            return response()->json(['error' => 'Chiave non valida.'], 401);
        }

        if (blank($user->inbound_allowed_senders)) {
            return response()->json(['error' => 'Ricezione email non ancora configurata.'], 503);
        }

        $data = $request->validate([
            'messageId' => ['required', 'string', 'max:300'],
            'from' => ['required', 'string', 'max:320'],
            'subject' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'string', 'max:64'],
            'body' => ['nullable', 'string', 'max:100000'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(InboundInbox::receive($user, $data));
    }
}
