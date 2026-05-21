# allcalls-churn-synapcores

A Laravel 13 application that integrates with **SynapCores AIDB** to predict loyalty-member churn. It uses a custom PHP SDK to communicate with SynapCores' REST API, seeds 8,000 synthetic loyalty-program members with a realistic churn signal, trains a binary-classification model via SynapCores SQL extensions (`CREATE EXPERIMENT` / `TRAIN` / `AUTOML.PREDICT`), and surfaces the top-50 at-risk Gold/Platinum members on an admin dashboard with one-click retention-offer logging.

---

## Requirements

- PHP ≥ 8.2, Composer
- SynapCores Community Edition running locally (`synapcores --port 8080`)
- SQLite (default) or MySQL/PostgreSQL

---

## How to run locally

```bash
# 1. Clone & install
git clone https://github.com/eduardoguerrero/allcalls-churn-synapcores.git
cd allcalls-churn-synapcores
composer install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# Edit .env and set:
#   SYNAPCORES_URL=http://127.0.0.1:8080
#   SYNAPCORES_API_KEY=<key from SynapCores Settings → API Keys>

# 3. Start SynapCores (in a separate terminal)
synapcores --port 8080

# 4. Migrate & seed
php artisan migrate
php artisan synapcores:seed          # generates 8,000 members (~5 seconds)

# 5. Train the model & score members
php artisan synapcores:train         # CREATE EXPERIMENT → TRAIN → AUTOML.PREDICT

# 6. Serve
php artisan serve
# Open http://localhost:8000/dashboard
```

The JSON API is also available without authentication:

```
GET  /api/members/at-risk            → top-50 at-risk members as JSON
POST /api/members/{id}/offer         → log a retention offer for a member
```

---

## Design decisions

- **Custom SDK over a library** — SynapCores doesn't ship a PHP SDK, so `app/Services/SynapCores/` wraps Laravel's built-in HTTP client. `SynapCoresAuth` caches the JWT for 55 minutes (keyed by a hash of the API key for cache isolation) and auto-refreshes on 401; `SynapCoresClient` retries once after a token refresh before throwing. This makes the SDK resilient without being complex.

- **Singleton registration + Repository pattern** — Both `SynapCoresAuth` and `SynapCoresClient` are singletons in `AppServiceProvider` so the cached JWT is shared across the request lifecycle. Data access is abstracted behind `LoyaltyMemberRepositoryInterface` / `EloquentLoyaltyMemberRepository`, allowing the data source to be swapped without touching controllers.

- **API rate limiting + FormRequest** — API routes are protected with `throttle:60,1` (60 req/min). `sendOffer` uses a `SendOfferRequest` FormRequest, keeping validation concerns out of the controller. The API response is shaped by `LoyaltyMemberResource` (JsonResource), exposing only the fields needed and formatting floats consistently.

- **Churn signal design** — The seed data encodes a clear but noisy signal: members with `visits_30d < 2` AND `spend_30d < $20` are labelled churned ~85% of the time; high-activity members churn only ~10% of the time. Noise (~15%) prevents a trivially overfit model.

- **SQLite by default** — Eliminates the need for a running database server during evaluation. Switch to MySQL/Postgres by editing `DB_CONNECTION` in `.env`.

- **Blade over Inertia/React** — A working server-rendered table is faster to build and easier to run than a SPA that requires `npm install` and `npm run build`. The evaluator can see predictions immediately after `php artisan serve`.

---

## What I'd do with more time

- **Scheduled refresh** — Add a `php artisan schedule:run` job that re-runs `AUTOML.PREDICT` nightly and writes results to a `churn_predictions` history table.
- **Personalised offers** — Use SynapCores' `SELECT GENERATE(…)` to draft a 2-sentence retention message per member based on their tenure and recent spend.
- **Model versioning** — Track experiment versions and allow rollback via an admin UI.
- **Authentication** — Add Laravel Breeze to protect `/dashboard` behind a login screen.
- **Tests** — Add a PHPUnit feature test that seeds a small dataset, mocks the SynapCores HTTP responses, and asserts the dashboard returns the expected members.

---

## Where I cut corners

| Corner cut | Why | What I'd do instead |
|---|---|---|
| No auth on dashboard/API | Out of scope per spec; adds setup friction | Laravel Breeze + sanctum tokens |
| No test suite | Time constraint; SDK is testable in isolation | Mock `SynapCoresClient` with Mockery |
| `IF NOT EXISTS` on `CREATE EXPERIMENT` | Simplifies re-running `synapcores:train` | Detect experiment status via SynapCores API before creating |
| Tailwind CDN | Removes the `npm install` step entirely | Vite + Tailwind CLI for production |
| SQLite in local `.env` | Simplest possible setup for evaluators | MySQL with a `docker-compose.yml` |

---

## License

MIT
