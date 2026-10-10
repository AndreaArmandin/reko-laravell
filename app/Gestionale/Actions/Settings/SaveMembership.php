<?php

namespace App\Gestionale\Actions\Settings;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;

/**
 * user.save (engine.ts): the admin edits an operator of the agency: role, active, group and branch,
 * optional permissions and the catalog package. Operators are deactivated, never deleted, and
 * accounts are linked to the agency from the platform admin: this never creates a user.
 * Permissions are applied on the server (AgencyMembership::allows, Gate "agency-permission").
 */
final class SaveMembership
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  role, active, group, branch, permissions (list), catalogPackage {name, enabled, maxParcels, unlimited}
     */
    public function handle(AgencyMembership $actor, AgencyMembership $target, array $data): AgencyMembership
    {
        SettingsAccess::admin($actor);
        if ((int) $target->agency_id !== (int) $actor->agency_id) {
            throw new CommandRejected('Operatore non accessibile con questo ruolo.', 403);
        }

        $role = (string) ($data['role'] ?? $target->actualRole());
        $active = ($data['active'] ?? true) !== false;
        if ((int) $target->user_id === (int) $actor->user_id && (! $active || $role !== 'admin')) {
            throw new CommandRejected('Non puoi rimuovere i tuoi permessi amministrativi da questa sessione.');
        }
        if (! in_array($role, AgencyMembership::ROLES, true)) {
            throw new CommandRejected('Scegli uno dei tre ruoli previsti.');
        }

        $attributes = [
            'role' => $role,
            'deactivated_at' => $active ? null : ($target->deactivated_at ?? now()),
        ];
        foreach (['group' => 'group_name', 'branch' => 'branch_name'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $attributes[$column] = ($value = mb_substr(trim((string) ($data[$key] ?? '')), 0, 100)) === '' ? null : $value;
            }
        }
        if (array_key_exists('permissions', $data)) {
            $permissions = $data['permissions'];
            if (! is_array($permissions) || array_diff($permissions, AgencyMembership::OPTIONAL_PERMISSIONS) !== []) {
                throw new CommandRejected('Permessi non validi.');
            }
            $attributes['permissions'] = array_values(array_unique($permissions));
        }
        if (array_key_exists('catalogPackage', $data)) {
            $attributes['catalog_package'] = $this->package($data['catalogPackage']);
        }

        return DB::transaction(function () use ($target, $attributes) {
            $fresh = AgencyMembership::query()->lockForUpdate()->findOrFail($target->getKey());
            $fresh->forceFill($attributes)->save();
            $this->audit->record('user.save', $fresh);

            return $fresh;
        });
    }

    /** @return array{name: string, enabled: bool, maxParcels: int, unlimited: bool} */
    private function package(mixed $package): array
    {
        $message = 'Indica il nome e un limite valido, oppure scegli “Senza limite complessivo”.';
        if (! is_array($package) || ! is_string($package['name'] ?? null) || mb_strlen($package['name']) > 100
            || ! is_bool($package['enabled'] ?? null) || ! is_int($package['maxParcels'] ?? null) || $package['maxParcels'] < 0
            || (isset($package['unlimited']) && ! is_bool($package['unlimited']))) {
            throw new CommandRejected($message);
        }
        $unlimited = ($package['unlimited'] ?? false) === true;
        if ($package['enabled'] && (trim($package['name']) === '' || (! $unlimited && $package['maxParcels'] < 1))) {
            throw new CommandRejected($message);
        }

        return ['name' => trim($package['name']), 'enabled' => $package['enabled'], 'maxParcels' => $unlimited ? 0 : $package['maxParcels'], 'unlimited' => $unlimited];
    }
}
