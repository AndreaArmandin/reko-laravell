<?php

namespace App\Gestionale\Livewire;

use App\Gestionale\Actions\Properties\SetPropertyLifecycle;
use App\Models\Property;

/**
 * Azioni archive(id), restore(id) e remove(id) usate da <x-gestionale.record-lifecycle> (record-lifecycle.tsx).
 * Servono HandlesCommands nella pagina; dopo ogni azione la pagina può aggiornare i propri dati in afterLifecycle().
 */
trait ManagesPropertyLifecycle
{
    public function archive(int $id): void
    {
        $this->changeLifecycle($id, 'archive', 'Scheda archiviata. Puoi annullare con “Ripristina”.');
    }

    public function restore(int $id): void
    {
        $this->changeLifecycle($id, 'restore', 'Scheda ripristinata');
    }

    /** "Rimuovi dalle liste": solo il Responsabile, dopo la conferma nella finestra. */
    public function remove(int $id): void
    {
        $this->changeLifecycle($id, 'remove', 'Scheda rimossa dalla lista. Puoi ripristinarla dagli archiviati.', true);
    }

    private function changeLifecycle(int $id, string $mode, string $success, bool $confirmed = false): void
    {
        $property = Property::query()->visibleTo($this->actor())->findOrFail($id);
        $this->command(fn () => app(SetPropertyLifecycle::class)->handle($this->actor(), $property, $mode, $confirmed), $success);
        if (method_exists($this, 'afterLifecycle')) {
            $this->afterLifecycle($id);
        }
    }
}
