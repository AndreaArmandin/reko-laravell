<?php

namespace App\Gestionale\Inbound;

/**
 * Pure helpers for emails pushed to the inbound endpoint: who may send, and a best-effort
 * extraction of listing link and phone. The raw message is stored whatever is found here.
 */
final class InboundMail
{
    public static function address(string $from): string
    {
        return mb_strtolower(preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) ? $m[1] : trim($from));
    }

    /** An entry is a full address or a domain; a domain also covers its subdomains. */
    public static function senderAllowed(string $from, ?string $allowed): bool
    {
        $address = self::address($from);

        if (! preg_match('/^[^\s@]+@([^\s@]+)$/', $address, $m)) {
            return false;
        }

        $domain = $m[1];

        foreach (array_filter(array_map(fn (string $r) => mb_strtolower(trim($r)), explode(',', (string) $allowed))) as $rule) {
            if (str_contains($rule, '@') ? $rule === $address : ($domain === $rule || str_ends_with($domain, '.'.$rule))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{url: string, phone: string} */
    public static function extract(string $body): array
    {
        preg_match('~https://(?:www\.)?immobiliare\.it/annunci/\d+/?~i', $body, $url);
        preg_match('/(?:\+|00)?39[\s.-]?3\d{2}[\s.-]?\d{6,7}|\b3\d{2}[\s.-]?\d{6,7}\b|\b0\d{1,3}[\s.-]?\d{5,8}\b/', $body, $phone);

        return ['url' => $url[0] ?? '', 'phone' => $phone[0] ?? ''];
    }
}
