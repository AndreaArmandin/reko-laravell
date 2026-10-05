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

