<?php

namespace App\Http\Controllers\Api;

use App\Gestionale\Inbound\InboundInbox;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound webhook of Postmark. The service signs in with HTTP Basic credentials set in its
 * webhook URL; the owner is the user whose personal code is the plus-tag of the receiving address
 * (<base>+<code>@inbound…), never anything written inside the message.
 */
class PostmarkInboundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $username = (string) config('services.postmark_inbound.username');
        $password = (string) config('services.postmark_inbound.password');

        if (strlen($username) < 8 || strlen($password) < 24) {
            return response()->json(['error' => 'Ricezione email non ancora configurata.'], 503);
        }

        if (! hash_equals($username, (string) $request->getUser()) || ! hash_equals($password, (string) $request->getPassword())) {
            return response()->json(['error' => 'Credenziali non valide.'], 401, ['WWW-Authenticate' => 'Basic realm="inbound"']);
        }

        $code = (string) $request->input('MailboxHash', '');
        $user = $code === '' ? null : User::query()->where('inbound_code', $code)->first();

        // Unknown address: answered 200 so Postmark does not retry a message nobody owns.
        if ($user === null) {
            return response()->json(['saved' => false, 'ignored' => true]);
        }

        $from = (string) ($request->input('FromFull.Email') ? ($request->input('FromFull.Name') ? $request->input('FromFull.Name').' <'.$request->input('FromFull.Email').'>' : $request->input('FromFull.Email')) : $request->input('From', ''));
        $body = (string) $request->input('TextBody', '');
        if ($body === '') {
            $body = trim(html_entity_decode(strip_tags((string) preg_replace('~<(script|style)\b.*?</\1>~is', '', (string) $request->input('HtmlBody', '')))));
        }

        $data = [
            'messageId' => mb_substr((string) $request->input('MessageID', ''), 0, 300),
            'from' => mb_substr($from, 0, 320),
            'subject' => mb_substr((string) $request->input('Subject', ''), 0, 500),
            'date' => mb_substr((string) $request->input('Date', ''), 0, 64),
            'body' => mb_substr($body, 0, 100000),
            'source' => 'postmark',
        ];

        if ($data['messageId'] === '' || $data['from'] === '') {
            return response()->json(['error' => 'Messaggio incompleto.'], 422);
        }

        return response()->json(InboundInbox::receive($user, $data, allowForwardingConfirmation: true));
    }
}
