# ARIADNE Portal Laravel

Porting del portale ARIADNE/ARTEMIS su Laravel, con backend dati nativo verso OpenSearch, pannello admin Filament e frontend SPA pubblicato dal progetto Laravel.

Il progetto deriva dal portale ARIADNE ed e stato adattato per il contesto ARTEMIS mantenendo una struttura applicativa standard Laravel.

## Struttura

- `app/Services/PortalSearchService.php`
  Backend di consultazione nativo Laravel per ricerca, record, aggregazioni, timeline, servizi e publisher.
- `app/Http/Controllers/Api/PortalApiController.php`
  Endpoint API esposti al frontend.
- `app/Http/Controllers/PortalController.php`
  Catch-all che serve il frontend pubblicato da `public/index.html`.
- `frontend/`
  Sorgente della SPA, ora mantenuto dentro questo repository.
- `public/`
  Bundle frontend pubblicato e asset statici serviti in produzione/locale.
- `resources/opensearch/`
  Mapping e dati statici minimi usati per bootstrap degli indici OpenSearch.

## Stato attuale

- Nessun bridge runtime legacy nel backend attivo.
- Nessun endpoint `mail` o `updateServices`.
- Nessun editor admin nel frontend pubblicato.
- API attive solo per consultazione dati e supporto UI.
- Pannello admin basato su Filament per linking e gestione delle risorse importate.

## Sviluppo locale

Installazione dipendenze:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Avvio Laravel in locale:

```bash
php artisan serve --host=127.0.0.1 --port=8099
```

Build frontend dalla cartella interna del repository:

```bash
cd frontend
npm install
npm run build-local
```

Dopo la build, pubblicare il contenuto di `frontend/dist/` dentro `public/`.

## Docker

Il repository include un setup Docker standard per:

- Laravel app
- OpenSearch
- bootstrap automatico degli indici minimi del portale
- seed automatico dell'utente admin Filament

Avvio:

```bash
docker compose up --build
```

URL:

- portale: `http://localhost:8099`
- admin Filament: `http://localhost:8099/admin/login`
- OpenSearch: `http://localhost:9200`

Credenziali admin Docker di default:

- email: `admin@admin.local`
- password: `admin`

Per fermare lo stack:

```bash
docker compose down
```

Per azzerare anche i dati OpenSearch:

```bash
docker compose down -v
```

## Note

- Il bundle frontend corrente viene servito direttamente da `public/index.html`.
- Le view Blade demo e il bridge legacy runtime sono stati rimossi dal progetto.
- Il bootstrap Docker non dipende da repository esterni: mapping e dati statici minimi di OpenSearch sono inclusi nel repository Laravel.
