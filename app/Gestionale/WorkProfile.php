<?php

namespace App\Gestionale;

use App\Models\AgencyMembership;

/**
 * "Come vuoi lavorare nel Gestionale?" (crm-entry.tsx, permissions.ts actorWithProfile).
 * The authenticated identity never changes. A profile can only narrow an admin to crm or scout:
 * an operator cannot ask for another role. The applied role lives in memory on the membership
 * (AgencyMembership::$realRole keeps the true one) and is never written to the database.
 */
final class WorkProfile
{
    public const ROLES = ['admin', 'crm', 'scout'];

    public const LABELS = ['admin' => 'Responsabile', 'scout' => 'Agente acquisizioni', 'crm' => 'Segreteria'];

    /** Short description under each choice (crm-entry.tsx). */
    public const DESCRIPTIONS = [
        'admin' => 'Squadra, obiettivi e coordinamento',
        'crm' => 'Clienti, richieste e richiami',
        'scout' => 'Proprietari, immobili e lavoro sul territorio',
    ];

    public static function label(?string $role): string
    {
        return self::LABELS[$role ?? ''] ?? '';
    }

    /** Profiles the account may choose: an admin all three, anyone else only its own role. @return list<string> */
    public static function rolesFor(AgencyMembership $membership): array
    {
        return $membership->actualRole() === 'admin' ? self::ROLES : [$membership->actualRole()];
    }

    /** actorWithProfile(): the membership narrowed to the profile; invalid or foreign profiles are refused. */
    public static function apply(AgencyMembership $membership, mixed $profile): AgencyMembership
    {
        if ($profile === null || $profile === '') {
            return $membership;
        }
        if (! in_array($profile, self::ROLES, true)) {
            throw new CommandRejected('Profilo non valido.', 400);
        }
        $actual = $membership->actualRole();
        if ($actual !== 'admin' && $profile !== $actual) {
            throw new CommandRejected('Profilo non consentito per questo account.', 403);
        }
        if ($profile === $actual) {
            return $membership;
        }

        $membership->realRole = $actual;
        $membership->setAttribute('role', $profile);
        $membership->syncOriginalAttribute('role');

        return $membership;
    }
}
