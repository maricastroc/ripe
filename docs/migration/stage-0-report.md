# Stage 0 report: foundation

Branch `migration/stage-0-foundation`, 2026-11-15.

## What changed

```
.
├── .github/workflows/ci.yml   backend and frontend checks
├── backend/                   Laravel 13 on PHP 8.4
├── compose.yaml               PostgreSQL 17 for development
├── docker/postgres/init/      creates the ripe_testing database
├── docs/migration/            the plan and the stage reports
├── docs/screens/              screenshots
├── frontend/                  the complete current application, agent included
├── LICENSE
└── README.md
```

**Frontend**
- The Next.js application moved to `frontend/` with `git mv`: 85 files, all recorded as exact renames, so `git log --follow` keeps every file's history.
- Its behaviour is unchanged. The only edits are two scripts: `typecheck` now runs `next typegen` first, and a new `format:check` script was added.

**Backend**
- Laravel framework 13.42 (skeleton 13.11) on PHP `^8.4`, configured for PostgreSQL, with the database queue and the database cache.
- The skeleton is reduced to an API backend: no Vite assets, no welcome page, no web routes. The unused `public` and `s3` disks are removed, and the local disk no longer serves files.
- The skeleton's explanatory comments are removed; PHPDoc remains only where it carries types for static analysis.
- `AGENTS.md` and `CLAUDE.md` from the skeleton are removed. They told coding agents to install Laravel Boost and to run remote install scripts.
- Tooling:
  - Pest 5.3.6 with the Laravel plugin.
  - Pint 1.32.3 on the Laravel preset, plus `declare_strict_types` and `strict_comparison`.
  - Larastan 3.15 at the maximum level.
  - Composer scripts `setup`, `dev`, `lint`, `format`, `analyse`, `test` and `check`.
- Initial tests:
  - architecture presets (php, security, laravel) and strict types across `App`;
  - the health route;
  - PostgreSQL 17 or newer;
  - the presence of the queue and cache tables.

**Infrastructure and CI**
- `compose.yaml` runs `postgres:17-alpine` (17.12 at the time of writing), bound to `127.0.0.1`, with a health check and an init script that creates `ripe_testing`.
- The CI workflow has two jobs:
  - **backend:** PostgreSQL 17 service, `composer validate --strict`, `composer audit`, Pint, Larastan and Pest;
  - **frontend:** `npm ci`, a production dependency audit, Prettier, ESLint, type check and build.

## Verification

| Check | Where | Result |
|---|---|---|
| Frontend type check, lint, Prettier | working tree | pass |
| Frontend production build without `DATABASE_URL` | working tree | pass |
| Greenhouse renders the existing local data from `frontend/prisma/ripe.db` | dev server | pass, no console errors |
| Existing agent end to end against local Ollama (`gemma4:e4b`), on an isolated SQLite database | dev server | pass: a task ripened in about 2 min 40 s over 7 runs (interpret, plan, 4 pieces with one retry after a too-short draft, review), with names left as `[[TBD]]` markers and the plant drawn ripe |
| Migrations up, down and up again on PostgreSQL 17.12 | Compose | pass |
| Pint, Larastan (max), Pest (11 tests, 53 assertions) | working tree | pass |
| Backend dev server: `/up` returns 200, `/` returns 404 | `php artisan serve` | pass |
| Every backend CI step on a fresh clone of the branch | clean clone | pass |
| Every frontend CI step on a fresh clone of the branch | clean clone | pass |
| `composer audit` | backend | no advisories |
| `npm audit --omit=dev` | frontend | 0 vulnerabilities |

The workflow itself has not run on GitHub yet, because the branch has not been pushed. The clean-clone runs reproduce its steps locally.

## Problems found and fixed

1. **The frontend type check depended on leftovers.** `tsc` needed the route types (`PageProps`, `LayoutProps`, `RouteContext`) that Next writes to `.next/types` during a dev or build run, so a fresh checkout failed to type-check. `typecheck` now runs `next typegen` first.
2. **Pint was unstable on a declaration-only file.** On a file containing only `<?php` and `declare(strict_types=1)`, two Pint fixers disagreed, and Pint's local cache hid it. The fresh clone exposed it. The file now uses the stable three-line form, and Pint passes twice in a row without a cache.
3. **Larastan flagged `env()` (`bool|string`) passed to `rtrim`** in the `public` disk configuration. Ripe stores no public files, so the unused disks were removed instead of adding a cast.
4. **Larastan cannot follow Pest's test DSL** (`$this` bound inside closures, calls proxied through `TestCall`). Static analysis covers `app`, `bootstrap`, `config`, `database` and `routes` at the maximum level; tests are verified by running them.

## Dependency compatibility

- **Composer:** every direct dependency is on its latest version, with no security advisories. Pest 5, Larastan 3 and Pint all support Laravel 13 and PHP 8.4.
- **npm, production:** no vulnerabilities.
- **npm, development:** 8 moderate findings, all in the ESLint toolchain (`minimatch` via `typescript-eslint`). npm's suggested fix is a downgrade to `eslint-config-next@15`, so they are left as is; they do not reach the production bundle.
- **Frontend updates available:** patch and minor releases of `next` (16.4.6), `react` (19.3.1), `motion`, `lucide-react`, Tailwind CSS and Prettier. They were deliberately not applied in this stage, to keep the current application exactly as it was.

## Pending

- The skeleton's `User` model, `users` table and session storage stay until stage 1 replaces them with `Visitor`.
- `composer dev` currently starts `serve`, `queue:listen` and `pail`. The agent queue, the scheduler and Reverb are registered in the stages that introduce them.
- The frontend dependency updates above, in a separate change.
- The first CI run on GitHub.

## Next stage

Stage 1, domain and persistence, as described in [plan.md](plan.md).
