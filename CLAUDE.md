# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Status

NeuroLog is in the **design phase**: documents and code lists only, no application code yet. Read
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
