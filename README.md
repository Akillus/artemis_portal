# ARIADNE Portal Laravel

Porting del portale ARIADNE/ARTEMIS su Laravel, con backend dati nativo verso OpenSearch e frontend SPA pubblicato dal progetto Laravel.

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

## Stato attuale

- Nessun bridge runtime verso il vecchio backend PHP.
- Nessun endpoint `mail` o `updateServices`.
- Nessun editor admin nel frontend pubblicato.
- API attive solo per consultazione dati e supporto UI.

## Sviluppo locale

Avvio Laravel:

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

Il container Laravel esegue automaticamente:

- `php artisan migrate --seed`
- `php artisan portal:bootstrap-opensearch`
- `php artisan serve --host=0.0.0.0 --port=8000`

Per fermare lo stack:

```bash
docker compose down
```

Per azzerare anche i dati OpenSearch:

```bash
docker compose down -v
```

## GitHub

Il progetto Laravel non era inizialmente un repository git. Per pubblicarlo:

```bash
cd /Users/Achille/RICERCA/LaravelApps/ARIADNE_PORTAL_LARAVEL
git init
git branch -M main
git add .
git commit -m "Initial Laravel port"
git remote add origin <URL-DEL-REPOSITORY>
git push -u origin main
```

Se usi HTTPS, GitHub ti chiederà un Personal Access Token al posto della password. Se usi SSH, configura prima la chiave SSH sul tuo account GitHub.

## Note

- Il bundle frontend corrente viene servito direttamente da `public/index.html`.
- Le view Blade demo e il bridge legacy sono stati rimossi dal progetto.
- Il bootstrap Docker non dipende dal vecchio progetto PHP: mapping e dati statici minimi di OpenSearch sono ora nel repository Laravel.
