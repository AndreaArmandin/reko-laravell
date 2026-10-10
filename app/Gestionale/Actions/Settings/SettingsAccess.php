<?php

namespace App\Gestionale\Actions\Settings;

use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;

/**
 * Role gate of settings.* / user.* (engine.ts applyAction + admin()): scout and crm get the
 * messages of the old engine, any other non-admin the generic one. Always checked on the server.
 */
final class SettingsAccess
{
    public const CRM_DENIED = 'Operazione riservata allo scouting o all’Amministratore.';

    public const ADMIN_ONLY = 'Questa operazione è riservata all’amministratore.';

    public static function admin(AgencyMembership $actor): void
    {
        if ($actor->isActive() && $actor->role === 'admin') {
            return;
        }

        throw new CommandRejected(match (true) {
            ! $actor->isActive() => self::ADMIN_ONLY,
            $actor->role === 'scout' => Commands::SCOUT_DENIED,
            $actor->role === 'crm' => self::CRM_DENIED,
            default => self::ADMIN_ONLY,
        }, 403);
    }
}
