# REKO Laravel

Un'unica app Laravel 13 con un solo database PostgreSQL/PostGIS per Trova e, in seguito, Gestionale. Questa fase include auth, pannello admin, agenzie, identità catastali/versioni e tracciamento import. Il catalogo reale e le schermate CRM non sono ancora caricati.

## PostgreSQL locale (DBngin o altra installazione)

Non serve Docker. Avvia PostgreSQL con PostGIS sulla porta 5432. In `.env` configura la connessione reale; ad esempio, se il ruolo locale è `postgres`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=reko
DB_USERNAME=postgres
DB_PASSWORD=la_password_del_server_locale
```

Il database `reko` deve esistere. La prima migrazione abilita PostGIS; il ruolo deve poter creare l'estensione. Su questo Mac la password effettiva è già salvata in `.env`: non copiarla nel README o nel codice. DBngin conserva la directory dati attiva sotto `~/Library/Application Support/com.tinyapp.DBngin/Engines/postgresql/`, fuori dal Desktop sincronizzato con iCloud. La cartella `REKO DB iCloud backup 2026-10-04` sul Desktop è solo il vecchio cluster conservato come backup.

## Avvio

```bash
composer install
php artisan migrate
npm install
npm run build
php artisan serve
```

Il binario PHP deve essere almeno 8.4.1. Su questo Mac è disponibile anche `/Users/andrea/Library/Application Support/Herd/bin/php84`.

Se vuoi ricreare **solo il database locale di sviluppo** cancellando tutte le tabelle e i dati: `php artisan migrate:fresh`. Non usare questo comando su un database con dati da conservare.

## Admin e agenzie

1. Registra il primo utente dalla pagina di registrazione.
2. Da terminale esegui `php artisan reko:grant-admin tua@email.it`.
3. Accedi a `/admin`, crea l'agenzia e assegna l'email di un utente già registrato con ruolo `admin`, `scout` o `crm`.

`users.is_admin` abilita il pannello della piattaforma. `agency_memberships` collega ogni utente a una o più agenzie tramite `user_id`, `agency_id` e `role`. L'admin di un'agenzia non è automaticamente admin della piattaforma. La registrazione non accetta un parametro per autoassegnarsi un'agenzia o un ruolo; lo decide l'admin.

Il catalogo (`municipalities`, `parcels`, `cadastral_units`, versioni e rilasci) è condiviso. Le future righe operative CRM avranno `agency_id` e dovranno essere autorizzate rispetto all'appartenenza corrente. Le vecchie migrazioni CRM sono conservate in `database/migrations_deferred/` come riferimento, **non** vengono eseguite da `migrate`: vanno riviste prima di attivarle.

Le particelle distinguono Fabbricati (`F`) e Terreni (`T`). Le unità con subalterno mancante conservano un riferimento stabile alla riga della fonte (`source_ref`), senza inventare un subalterno. Gli import condivisi sono tracciati in `import_runs` e `import_issues` senza `agency_id`; l'importatore vero richiede ancora i file sorgente catastali.

## Test

Crea un database locale separato `reko_test` e lancia `php artisan test`. `phpunit.xml` usa la stessa connessione e lo stesso ruolo di `.env`, ma forza il nome del database di test. I test ricreano le tabelle in `reko_test`.
