<?php

namespace App\Gestionale\Clients;

/**
 * client-card.ts clientContactLinks() and contact-validation.ts contactErrors(): the call, WhatsApp and
 * email links of a client card, and the per-field contact errors shown while typing in the client form.
 * Links are only built from valid contact data; a stored value that does not validate is shown as plain text.
 */
final class ClientCard
{
    public const PHONE_ERROR = 'Inserisci un telefono con 6–15 cifre. Sono ammessi +, spazi, parentesi e trattini.';

    public const EMAIL_ERROR = 'Controlla l’indirizzo email, per esempio nome@example.it.';

    /**
     * contactErrors(): name, phone, email (first error per field). Unchanged historical values are not re-validated.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string}  $input
     * @param  array{name?: ?string, phone?: ?string, email?: ?string}|null  $previous
     * @return array<string, string>
     */
    public static function contactErrors(array $input, ?array $previous = null): array
    {
        $errors = [];
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        if (trim((string) ($input['name'] ?? '')) === '') {
            $errors['name'] = 'Inserisci il nome del cliente.';
        }
        $phoneChanged = $previous === null || $phone !== trim((string) ($previous['phone'] ?? ''));
        $emailChanged = $previous === null || $email !== trim((string) ($previous['email'] ?? ''));
        if ($phoneChanged && $phone !== '' && (! preg_match('/^\+?[\d\s()\.\/-]+$/', $phone) || ! preg_match('/^(?:\D*\d){6,15}\D*$/', $phone) || mb_strlen($phone) > 60)) {
            $errors['phone'] = self::PHONE_ERROR;
        }
        if ($emailChanged && $email !== '' && (mb_strlen($email) > 160 || ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email))) {
            $errors['email'] = self::EMAIL_ERROR;
        }
        if ($phone === '' && $email === '' && ($previous === null || $phoneChanged || $emailChanged)) {
            $errors['phone'] = 'Inserisci almeno un recapito: telefono oppure email.';
        }

        return $errors;
    }

    /** @return array{phone: string, email: string, tel: ?string, whatsapp: ?string, emailHref: ?string} */
    public static function contactLinks(?string $phone, ?string $email): array
    {
        $phone = trim((string) $phone);
        $email = trim((string) $email);
        $errors = self::contactErrors(['name' => 'Contatto', 'phone' => $phone, 'email' => $email]);
        $normalized = (string) preg_replace('/[^+\d]/', '', $phone);
        $international = str_starts_with($normalized, '+') ? substr($normalized, 1) : (str_starts_with($normalized, '00') ? substr($normalized, 2) : '');
        $phoneOk = $phone !== '' && ! isset($errors['phone']);

        return [
            'phone' => $phone,
            'email' => $email,
            'tel' => $phoneOk ? 'tel:'.$normalized : null,
            'whatsapp' => $phoneOk && preg_match('/^\d{6,15}$/', $international) ? 'https://wa.me/'.$international : null,
            'emailHref' => $email !== '' && ! isset($errors['email']) ? 'mailto:'.rawurlencode($email) : null,
        ];
    }
}
