# allcalls-churn-synapcores

A Laravel 13 application that integrates with **SynapCores AIDB** to predict loyalty-member churn. It uses a custom PHP SDK to communicate with SynapCores' REST API, seeds 8,000 synthetic loyalty-program members with a realistic churn signal, attempts to train a binary-classification model via SynapCores SQL extensions (`CREATE EXPERIMENT` / `TRAIN` / `AUTOML.PREDICT`), and surfaces the top-50 at-risk Gold/Platinum members on an admin dashboard with one-click retention-offer logging.

---

## Requirements

- PHP ≥ 8.2, Composer
- SynapCores Community Edition running locally (`synapcores --port 8085`)
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
#   SYNAPCORES_URL=http://127.0.0.1:8085
#   SYNAPCORES_API_KEY=<key from SynapCores Settings → API Keys>

# 3. Start SynapCores (in a separate terminal)
#    Use port 8085 — port 8080 conflicts with another process and causes
#    SELECT/CREATE EXPERIMENT timeouts even when INSERTs succeed.
synapcores --port 8085

# 4. Migrate & seed (local SQLite only — no SynapCores connection required)
php artisan migrate
php artisan synapcores:seed          # generates 8,000 members in SQLite (~5 seconds)

# 5. Train the model & score members
#    synapcores:train owns ALL SynapCores interaction:
#      a) syncs loyalty_members from SQLite → SynapCores
#      b) CREATE EXPERIMENT churn_v1 → TRAIN → AUTOML.PREDICT
#      c) if any step fails, falls back to AI Embeddings automatically
#      d) writes churn_probability back to SQLite (dashboard source)
php artisan synapcores:train

# 6. Serve
php artisan serve
# Open http://127.0.0.1:8000/dashboard
```

The JSON API is also available without authentication:

```
GET  /api/members/at-risk            → top-50 at-risk members as JSON
POST /api/members/{id}/offer         → log a retention offer for a member
```

---

## Running tests

```bash
php artisan test
```

The suite uses SQLite in-memory (no external dependencies required):

| Suite | File | Tests | What it covers |
|---|---|---|---|
| Unit | `tests/Unit/SynapCoresSeedTest.php` | 10 | `computeChurn` probability zones, `weightedRandom` distribution |
| Feature | `tests/Feature/Commands/SynapCoresSeedCommandTest.php` | 13 | Command validation, DB state, truncation, field ranges |

---

## Architecture — data flow

```
synapcores:seed
  └─► SQLite: loyalty_members (N rows, churn_probability = NULL)
      No SynapCores connection required.

synapcores:train
  ├─► [AutoML path] Reads from SQLite
  │     1. Sync: DROP TABLE / CREATE TABLE / INSERT → SynapCores
  │     2. CREATE EXPERIMENT churn_v1 ON loyalty_members PREDICT churned
  │     3. TRAIN EXPERIMENT churn_v1  (300 s timeout)
  │     4. AUTOML.PREDICT REST → churn probabilities
  │
  ├─► [Embeddings fallback — if any AutoML step fails]
  │     1. batchEmbeddings(prototype texts) → churned / active vectors
  │     2. batchEmbeddings(member feature sentences) → member vectors
  │     3. churn_prob = cosine_sim(member, churn) / (sim_churn + sim_active)
  │
  └─► Normalise scores → [0.05, 0.95] → write churn_probability to SQLite

Dashboard / API
  └─► SELECT FROM SQLite WHERE tier IN ('Gold','Platinum')
      ORDER BY churn_probability DESC LIMIT 50
```

`synapcores:seed` has a single responsibility: generate realistic training data in SQLite. `synapcores:train` owns all SynapCores interaction. The dashboard never contacts SynapCores directly.

## `synapcores:train` — scoring paths

### Path 1 — AutoML SQL (preferred)

```sql
-- Step 1: data sync (executed by the command before CREATE EXPERIMENT)
DROP TABLE IF EXISTS loyalty_members;
CREATE TABLE loyalty_members (id INTEGER PRIMARY KEY, tier TEXT, ...);
INSERT INTO loyalty_members ...  -- batched via /v1/query/execute/batch

-- Step 2: experiment
-- CE syntax: CREATE EXPERIMENT <name> AS (<SELECT>) PREDICT <col> USING ...
CREATE EXPERIMENT IF NOT EXISTS churn_v1 AS (
  SELECT tier, tenure_months, visits_30d, spend_30d, churned
  FROM loyalty_members
) PREDICT churned USING algorithm='binary_classification';

-- Step 3: training (TRAIN is not valid SQL in CE — uses REST endpoint)
POST /v1/automl/experiments/churn_v1/train

-- Step 4: scoring (REST endpoint, not SQL)
POST /v1/automl/models/churn_v1/predict  { "rows": [...] }
```

The TRAIN step uses a 300-second timeout (override in `SynapCoresTrain::TRAIN_TIMEOUT`). Scores are normalised to [0.05, 0.95] before persisting.

### Path 2 — AI Embeddings fallback

Triggered automatically if any AutoML step throws. Reads features from local SQLite — no SynapCores table required.

1. Two prototype texts are embedded via `/v1/ai/embeddings/batch`.
2. Each member's features become a natural-language sentence and are embedded the same way.
3. `churn_prob = cosine_sim(member, churn_proto) / (sim_churn + sim_active)`.
4. Raw scores normalised to [0.05, 0.95].

### Observed SynapCores CE behaviour (port matters)

During development, running SynapCores on **port 8080** produced consistent `"Operation timeout"` errors on all read operations (`SELECT`, `CREATE EXPERIMENT`, `TRAIN`) while writes (`CREATE TABLE`, `INSERT`) succeeded. Switching to **port 8085** resolved all timeouts — SELECTs and DDL/ML operations return normally. This appears to be a port conflict specific to the local environment, not a SynapCores CE limitation.

Evidence (port 8080 vs 8085):

```
# port 8080 — reads timeout regardless of table size
SELECT * FROM test_sc (1 row)  →  {"error":{"code":"query_error","message":"Operation timeout"}}
CREATE TABLE test_sc           →  {"error":{"code":"query_error","message":"Operation timeout"}}

# port 8085 — reads work correctly
SELECT * FROM orders           →  {"data":{"columns":[...],"rows":[],"execution_time_ms":0}}
```

---

## Design decisions

- **Custom SDK over a library** — SynapCores doesn't ship a PHP SDK, so `app/Services/SynapCores/` wraps Laravel's built-in HTTP client. `SynapCoresAuth` holds the API key and sends it as `Authorization: Bearer <key>` (the CE gateway requires the `Authorization` header regardless of credential type). On a 401, `SynapCoresClient` calls `refreshToken()` and retries once before throwing `SynapCoresException`, so a JWT-based flow can drop in without changing call sites. The client exposes `batch()` for bulk SQL inserts, `execute()` for DDL/ML statements (with an optional per-call timeout for long-running operations like TRAIN), `automlPredict()` for the REST scoring endpoint, and `batchEmbeddings()` for the embedding fallback.

- **Two-path scoring with graceful fallback** — `synapcores:train` first attempts the full AutoML SQL workflow. Any exception (network, timeout, unsupported operation) causes a clean fallback to embedding-based scoring. Both paths write the same `churn_probability` column, so the dashboard is agnostic to which path ran.

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
- **Tests** — Extend the existing PHPUnit suite to cover the SynapCores SDK (mock HTTP responses with Mockery) and the dashboard endpoint assertions.

---

## Where I cut corners

| Corner cut | Why | What I'd do instead |
|---|---|---|
| Port 8085 hardcoded in docs | Port 8080 caused read timeouts in the local environment (see above) | Detect the conflict automatically; document the port as a required env var |
| No auth on dashboard/API | Out of scope per spec; adds setup friction | Laravel Breeze + sanctum tokens |
| Partial test suite | Unit + feature tests cover `synapcores:seed` (23 tests, 932 assertions); SDK and dashboard endpoints are not yet covered | Mock `SynapCoresClient` with Mockery for SDK tests |
| `IF NOT EXISTS` on `CREATE EXPERIMENT` | Simplifies re-running `synapcores:train` | Detect experiment status via SynapCores API before creating |
| Tailwind CDN | Removes the `npm install` step entirely | Vite + Tailwind CLI for production |
| SQLite in local `.env` | Simplest possible setup for evaluators | MySQL with a `docker-compose.yml` |

---

## License

MIT
