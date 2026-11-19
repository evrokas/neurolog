# CLAUDE.md

Surgical registry on ZeusFW. Before proposing anything read `docs/README.md`, `docs/data-dictionary.md` and
`docs/decisions.md`; milestones in `docs/implementation-plan.md`. Don't re-argue a logged decision without new
information; log new ones (newest first, with the reason).

## Public repository

- Never commit patient data or anything derived from a real system's records (APYweb/ZPMS extracts, operation or
  pathology texts, practice counts, staff or company names, seed results). Those stay in the database or in
  `../neurolog_private`. `.gitignore` is a backstop, not permission.
- Development uses made-up cases only; no web fonts or scripts from other hosts.

## Code lists

- Kept twice: tables in `docs/data-dictionary.md` and files in `vocab/`; change both in one commit. Quote `yes`/`no` in
  YAML. `fw/bin/update.sh` reloads them into `nl_codes`.
- A code or value is retired (removed from `vocab/`, marked in the dictionary), never renamed or deleted.
- Codes say what was done, not the approach («Διαδερμική δισκεκτομή» = `discectomy` + `tubular_MIS`).
- Clinical wording and facts come from the surgeon; never invent them. `vocab/aliases.yaml`: general vocabulary only.

## Application

- Setup: README → Installation (`fw` → zeusfw and `web/core` → `../fw/core` are gitignored symlinks; config from
  `*.in`). Dev server: `php -S 127.0.0.1:8097 -t web router.php`. Roles: `admin`, `surgeon`, `viewer`.
- Run `bin/run_tests.sh` after every change (static checks, unit tests in `tests/static/`, `bin/check_schema.php`,
  `bin/check_web.php` over real HTTP).
- Schema: one YAML per table in `web/classes/yaml/`, applied by `fw/bin/update.sh` (database name from `sql/admin.sql`).
  No string `default:`; changing a `UNIQUE` column's type adds a second index; avoid reserved words as column names.
  Rows link by `*_ref` ids; `nl_id` is the visible ID.
- Every POST handler starts with `if ($refused = csrfClass::requireValid()) return $refused;`.
- `$kernel->addStatus()` prints raw: escape anything a user typed. Audit logs name filters and fields, never values.
- Validation and parsing are pure functions with unit tests (`operations.php`, `implants.php`, `supplies.php`,
  `outcomes.php`, `search.php`, `quickline.php`, `levels.php` + `js/levels.js` sharing `tests/cases/levels.json`).
- Brand: logo and favicons only from `tools/brand/make_brand.py`; every page carries the favicon links in `docs/brand.md`.
- Plain PHP (no Composer/Node), vanilla HTML/CSS/JS.

## Related systems

- APYweb (`../apy`): billing; source of operations and suppliers. Read-only, through its `db_config.php` in a READ ONLY
  session (`bin/import_apyweb_companies.php`); never changed.
- ZPMS: patient records; its guid is not NeuroLog's patient ID. erweb: public site; a code may name one erweb page.
