<?php

namespace App\Gestionale;

use App\Models\AgencyMembership;

/**
 * Optional permissions of the gestionale (permissions.ts allowed()), for every area that applies one:
 *  - Blade / Livewire views:  @can('agency-permission', 'exports')  or  Gate::allows('agency-permission', 'exports')
 *  - Actions and commands:    Permissions::require($actor, 'activities.assign')   (CommandRejected 403, message of the old engine)
 *  - plain checks:            $membership->allows('sister.import')
 * The rules live in AgencyMembership::allows(): admin always; owner.edit admin only; sister.import never
 * for crm and only if granted to a scout; the others follow the saved list, or the defaults
 * (activities.assign, activities.share, exports) while no list was ever saved. They hold on the
 * server whatever the menu shows.
 */
final class Permissions
{
    /** Refusal text per permission (engine.ts, workflow.ts, export.ts, census-sister.ts). */
    public const DENIED = [
        'sister.import' => 'Permesso “Importa da Sister” non assegnato.',
        'exports' => 'Esportazione non autorizzata.',
        'activities.assign' => 'Assegnazione non consentita.',
        'activities.share' => 'Condivisione non consentita.',
        'owner.edit' => 'Operazione riservata allo scouting o all’Amministratore.',
    ];

    public static function allows(?AgencyMembership $actor, string $permission): bool
    {
        return $actor !== null && $actor->allows($permission);
    }

    /** @throws CommandRejected */
    public static function require(?AgencyMembership $actor, string $permission): void
    {
        if (! self::allows($actor, $permission)) {
            throw new CommandRejected(self::DENIED[$permission] ?? 'Operazione non consentita.', 403);
        }
    }
}
