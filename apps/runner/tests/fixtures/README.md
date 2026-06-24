# Runner test fixtures

This folder holds the fixtures used by `apps/runner`'s PHPUnit suite. There are
two kinds:

## Lightweight fixtures — committed in the mizan repo

Tiny dirs hand-built by `bin/setup-light-fixtures.ps1` (already run once and
committed; safe to regenerate idempotently).

- `no_readme_repo/` — non-empty dir with no README-like file.
- `env_committed_repo/` — a git repo with `.env` tracked (and >1 commit).
- `single_commit_repo/` — a git repo with exactly one commit.
- `laravel_readme_stub.md` — the byte-exact default `README.md` shipped by
  `composer create-project laravel/laravel`, used as the byte-comparison
  anchor by `ReadmeRealCheck`. **This file is committed and committed-anchor:
  updating Laravel's stub means regenerating it and committing the update.**

## Heavy fixtures — gitignored and regenerated on demand

Real `laravel/laravel` apps created by `bin/setup-heavy-fixtures.ps1` (which
runs `composer create-project`) and mutated by
`bin/setup-mutated-fixtures.ps1`. These are NOT committed — each weighs ~50 MB
and requires a network to (re)generate.

Run them, in order, whenever the fixtures are missing or upstream Laravel
changes:

```powershell
php -d extension=php_zip.dll C:\laragon\bin\composer\composer.phar `
  create-project --no-interaction --prefer-dist laravel/laravel `
  apps\runner\tests\fixtures\valid_repo   # via setup-heavy-fixtures.ps1
powershell -ExecutionPolicy Bypass -File apps\runner\bin\setup-heavy-fixtures.ps1
powershell -ExecutionPolicy Bypass -File apps\runner\bin\setup-mutated-fixtures.ps1
```

The heavy fixtures and what each is mutated to break:

| Fixture                  | Real Laravel? | Broken check                | Notes                                                                 |
|--------------------------|---------------|-----------------------------|-----------------------------------------------------------------------|
| `valid_repo/`            | yes           | (none)                      | Canonical pass: custom README ≥200B, `.env` not tracked, >1 git commit.|
| `stub_readme_repo/`      | yes           | `readme_real` only          | `valid_repo` with `README.md` overwritten by the Laravel stub.        |
| `broken_deps_repo/`     | yes           | `composer_install`          | `composer.json` requires `nonexistent/mizan-broken-dep:^9.9.9`; `composer.lock` removed. |
| `broken_bootstrap_repo/`| yes           | `composer_install` + `app_boots` | `bootstrap/app.php` prepended with a `throw`. Composer's `post-autoload-dump` scripts also fail because they shell out to `artisan`. |
| `broken_migration_repo/`| yes           | `migrations_run` only       | Adds `database/migrations/2024_01_01_000002_mizan_broken.php` whose `up()` throws. |

The `broken_bootstrap_repo` pair of failures is intentional and realistic: a
throwing bootstrap breaks both `composer install` (via its `artisan` script)
and `php artisan --version` — the operator sees both checks fail rather than
just one.

**Each broken fixture breaks exactly the named check(s); every other check
passes against it.** When you add a new check, add a new fixture that breaks
exactly that one check and document it here.