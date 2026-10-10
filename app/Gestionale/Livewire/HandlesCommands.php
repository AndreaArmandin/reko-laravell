<?php

namespace App\Gestionale\Livewire;

use App\Gestionale\CommandRejected;
use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use Closure;

/**
 * Livewire pages of the gestionale: the acting membership and command execution with the
 * gestionale messages shown next to the field (or as 'command') and in the shared REKO feedback.
 */
trait HandlesCommands
{
    protected function actor(): AgencyMembership
    {
        return app(CurrentAgency::class)->membership() ?? abort(403, 'Nessuna agenzia attiva per questo account.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $command
     * @return T|null
     */
    protected function command(Closure $command, ?string $success = null): mixed
    {
        $this->resetErrorBag();

        try {
            $result = $command();
        } catch (CommandRejected $e) {
            $this->addError($e->field ?? 'command', $e->getMessage());
            $this->dispatch('crm-notice', type: 'error', text: $e->getMessage());

            return null;
        }

        if ($success !== null) {
            $this->dispatch('crm-notice', type: 'success', text: $success);
        }

        return $result;
    }
}
