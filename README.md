# REKO Laravel

REKO è una singola applicazione Laravel 13: Trova e Gestionale usano lo stesso database PostgreSQL/PostGIS, le stesse agenzie e lo stesso catalogo catastale. Il Gestionale comprende clienti, richieste, immobili, agenda, obiettivi, mappa e zone, archivio catastale e impostazioni dell’agenzia. Il pannello `/admin` gestisce cataloghi, importazioni e agenzie.

## Avvio in locale

Serve PostgreSQL con PostGIS attivo sulla porta 5432; non serve Docker. Avvia prima il servizio nel DB manager locale (per esempio DBngin), poi configura `.env`. Se il ruolo PostgreSQL locale è `postgres`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=reko
DB_USERNAME=postgres
DB_PASSWORD=la_password_del_server_locale
```

Poi, dalla cartella del progetto:

```sh
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Apri `http://127.0.0.1:8000`. Se Laravel mostra un errore di connessione a PostgreSQL, controlla che il servizio DB sia avviato e che `.env` punti al database locale corretto. I test usano un database separato, `reko_test`, definito in `phpunit.xml`.

## Struttura principale

- `/trova`: ricerca immobiliare, con query geografiche PostGIS.
- `/gestionale`: lavoro dell’agenzia, filtrato per appartenenza e ruolo.
- `/admin`: gestione piattaforma e catalogo.
- `app/Gestionale`: azioni, permessi, query e regole del Gestionale.
- `resources/views/pages/gestionale`: schermate Laravel/Livewire.
- `database/migrations`: schema PostgreSQL; le tabelle condivise consentono il collegamento tra Trova e Gestionale.

# reko_laravell
