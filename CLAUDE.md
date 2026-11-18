# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Status

NeuroLog has the **M0 shell** (ZeusFW app: sign-in, three roles, dashboard and placeholder pages, design-system
styles, tests); milestones are in [`docs/implementation-plan.md`](docs/implementation-plan.md). Read
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
  `diagnoses.csv`, `facets.yaml`). Change both in the same commit.
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
