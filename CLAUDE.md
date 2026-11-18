# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Status

NeuroLog has milestones **M0** (shell), **M1** (schema, code lists, NeuroLog IDs), **M2** (entering operations:
chips, quick line, presets), **M3** (search and filters, patient pages) and **M4** (implants, complications,
follow-up); milestones are in [`docs/implementation-plan.md`](docs/implementation-plan.md). Read
[`docs/README.md`](docs/README.md) first, then [`docs/data-dictionary.md`](docs/data-dictionary.md) and
[`docs/decisions.md`](docs/decisions.md) before proposing anything. Don't re-argue a decision in the log unless
the user brings new information; record new decisions there (newest first, with the reason).

## This repository is public

- **Never commit patient data or anything derived from a real system's records**: no APYweb/ZPMS extracts,
  no operation or pathology texts, no counts or volumes from the practice, no staff names, no seed results.
  `.gitignore` blocks the usual seed-output folders; that is a backstop, not permission.
- The private working copy of the data dictionary holds the counts; the public copy here does not.

## Conventions

- Code lists live twice: tables in `docs/data-dictionary.md` and files in `vocab/` (`procedures.csv`,
  `diagnoses.csv`, `complications.csv`, `facets.yaml`, `fields.yaml`, `implants.yaml`, `followup.yaml`). Change both in the same commit; quote
  `yes`/`no` in YAML. `fw/bin/update.sh` reloads them into `nl_codes` (`bin/load_vocab.php`).
- A code or facet value is **retired, never renamed or deleted**.
- Codes describe what was done, never the approach («Διαδερμική δισκεκτομή» = `discectomy` + `tubular_MIS`).
- Clinical wording and clinical facts come from the surgeon; don't invent indications, outcomes or figures.

## Application (ZeusFW, from M0)

- Layout like MedScribe/ZPMS; read `zeusfw/CLAUDE.md` before using framework features. `fw` → the zeusfw checkout and
  `web/core` → `../fw/core` are symlinks, gitignored. Real config files are copied from the committed `*.in` templates.
- Set up a checkout: README → Installation. Dev server: `php -S 127.0.0.1:8097 -t web router.php`.
- Roles `admin` (superuser), `surgeon`, `viewer` from `bin/seed_users.php`; route `access:` uses these names.
- Tests: `bin/run_tests.sh` (`--static-only`): `php -l`, YAML, route handlers and roles, every `.zetem` compiles,
  unit tests incl. `tools/rf_protocol/test.php`, and `bin/check_web.php` (real HTTP, temporary account per role,
  access matrix, CSRF on sign-in). Run it after every change.
- Schema: one YAML per table in `web/classes/yaml/`; apply with `fw/bin/update.sh` (it takes the database name from
  `sql/admin.sql`, not `config/db.php`). ZeusFW traps: no string `default:` (maker escapes the quotes; the app sets the
  value), and changing the type of a `UNIQUE` column adds a second index (drop it). Avoid reserved words as column
  names (`procedure_code`, `fu_window`). Rows link by `*_ref` integer keys; `nl_id` is the visible ID (`web/nl_id.php`).
- Status messages: `$kernel->addStatus('notice'|'error'|'warning', …)`; the notifications module prints them **raw**,
  so escape anything a user typed.
- **Every POST handler starts with `if ($refused = csrfClass::requireValid()) return $refused;`**: Kernel::boot's
  `csrf_webforms` covers only framework webforms, not app routes (`bin/check_web.php` tests it).
- Operations: `web/operations.php` (validation is pure and unit-tested, saving, derived fields, audit/change log),
  `web/operation_pages.php` (pages), `web/levels.php` + `web/js/levels.js` (one case file `tests/cases/levels.json`),
  `web/js/chips.js` (chip pickers), `web/quickline.php` (quick line and `@` tags; cases `tests/cases/quickline.json`;
  words per code in `vocab/aliases.yaml`, general vocabulary only; words of 6+ letters match on their stem).
- Finding: `web/search.php` (pure: filters from GET, SQL with bound parameters, the search line; procedure filters
  share one `EXISTS` so they match the same row) and `web/find_pages.php` (operation list, patients). Audit lists
  with filter names only, never values. Dev data: `php bin/seed_demo.php --link-surgeon=UNAME` (made-up names only).
- M4: `web/implants.php` (implant rows; parameters per category in `vocab/implants.yaml`, stored as JSON in
  `implants.params`), `web/outcomes.php` (complication, follow-up and score rules, pure) and `web/outcome_pages.php`;
  windows and scores asked in `vocab/followup.yaml`. Implants carry company (`companies`) and sizes, never lot numbers.
- `bin/check_schema.php` (in the runner) checks tables, columns, unique IDs and that `vocab/` and `nl_codes` agree.
- Development uses made-up cases only. No web fonts or scripts from other hosts (the check enforces it on the sign-in page).

## Brand

- Logo and favicons: `assets/brand/` and `web/assets/`, regenerated only from
  `assets/brand/source/neurolog-logo-original.jpg` with `tools/brand/make_brand.py`. Rules in `docs/brand.md`.
- Every HTML page of the app carries the favicon `<head>` links from `docs/brand.md`.

## Related systems

- **APYweb** (`evrokas/apyweb`): the practice's billing app; source of existing operations. Read-only from
  NeuroLog's side; never changed by it.
- **ZPMS** (`evrokas/zpms`): patient records. NeuroLog does not use the ZPMS guid as its patient ID.
- **erweb** (`evrokas/erweb`): the public website; each registry code may point to one erweb page.
- When the application is built, the user's preferences apply: plain PHP (no Composer/Node), vanilla HTML/CSS/JS.
