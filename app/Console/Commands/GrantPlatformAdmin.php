<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'reko:grant-admin {email : Email di un utente già registrato}';

    protected $description = 'Concede il ruolo di amministratore della piattaforma a un utente esistente';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Utente non trovato. Registralo prima di eseguire questo comando.');
            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$user->email} è ora amministratore della piattaforma.");

        return self::SUCCESS;
    }
}
