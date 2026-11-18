# Implementation plan

NeuroLog is built **from scratch as a ZeusFW application**, with the same layout as MedScribe and ZPMS (see their
CLAUDE.md files and `zeusfw/CLAUDE.md`). Each milestone ends with something that runs and is tested; the next one
starts only when the previous one is accepted.

## Foundations (decided 2026-11-18)

| Topic | Decision |
| --- | --- |
| Framework | ZeusFW: YAML entities, `.zetem` templates, routes in `config/settings.info.yaml`, schema changes through `fw/bin/update.sh` |
| Database | MySQL / MariaDB (utf8mb4) |
| Language | Plain PHP 8.x, vanilla HTML/CSS/JS; no Composer, no Node, no front-end framework |
| Sign-in | ErnsAuth SSO (`ernsauth_sso` module), session hardening and idle timeout from ZeusFW |
| Roles | `surgeon` (enters cases, sees all group cases, confirms codes on own cases), `admin` (users, code lists, settings), `viewer` (read-only). Every view of a case and every export is audit-logged |
| Look | `docs/design-system.md` palette and tokens (`--nl-*`, Inter); logo, favicons from `docs/brand.md` |
| Data | No real patient data until the ledger tasks in "Governance" (data dictionary) are done; development uses made-up cases only |

## Milestones

| # | Milestone | Delivers | Accepted when |
| --- | --- | --- | --- |
| M0 | **Shell** | ZeusFW scaffold (`fw`/`web/core` symlinks, `config/*.in`, `web/index.php` with `Kernel::boot()`), ErnsAuth sign-in, the three roles, page layout with logo and favicons, design-system CSS tokens, test runner (`bin/run_tests.sh`: `php -l`, template compile, tests) | Sign in, see an empty dashboard styled per the design system; tests pass |
| M1 | **Schema and code lists** | Entities from the data dictionary: patient, admission, operation, procedure row, diagnosis, implant, complication, follow-up, audit; code lists loaded from `vocab/` (feeders); NeuroLog ID generator (`P-XXXX-XXXX-C`, Crockford, mod-37 check) with tests | `update.sh` builds the database from nothing; IDs pass their tests |
| M2 | **Entering an operation** | Chips and quick line ([plans/tags-and-chips.md](plans/tags-and-chips.md) A–B), operation form with procedure rows and their facets, diagnosis with qualifiers, level entry in the group's notation (`Ο4/5` → L4-L5) with validation, cage/screw construct, trigeminal branches, `rf_protocol` field using `tools/rf_protocol/`, side/route/technology required | A surgeon can record each worked example of the data dictionary, and impossible input is refused with a clear message |
| M3 | **Finding operations** | Operation list with filters on every facet (procedure, region, level, side, approach, construct, branch pattern, RF mode, surgeon, hospital, date), patient page, audit of views | "All left L4-L5 tubular discectomies in 2026" is one filter |
| M4 | **Implants, complications and follow-up** ([plans/m4.md](plans/m4.md), draft) | Implants with company, product, count, levels and size parameters per category; Clavien-Dindo complications linked to operations (and reoperations to index operations), follow-up windows with scores (VAS, ODI, NDI, mJOA, BNI…), reminders for due follow-ups | A case can be followed from operation to 12 months |
| M5 | **Import from APYweb** | Read-only import with source keys, review queue for `suggested` codes (confirm / change), the open coding questions answered | Imported cases appear as `suggested`; only confirmed codes count in statistics |
| M6 | **Statistics and export** | Dashboard counts by facet, pseudonymised research export with a new random ID per study | Export contains no identifying field; counts match the list |

M5 needs a decision first: how NeuroLog reads APYweb without changing it (a read-only database account, or a file
export run on the APYweb server). See [open-questions.md](open-questions.md).

## Progress

| Milestone | State |
| --- | --- |
| M0 | Built 2026-11-18. Password sign-in and roles tested over real HTTP; ErnsAuth sign-in is wired (module, sign-in tab, `config/ernsauth.php.in`) but untested until a NeuroLog client is registered in ErnsAuth. Dashboard counters read 0 until M1. |
| M2 | Built 2026-11-18, awaiting the surgeon's acceptance. Level parser (PHP + JS, shared cases), chip pickers with the surgeon's frequent values first, level grid; the operation form (patient search or new patient, diagnoses with qualifiers, procedure rows with every facet, trigeminal branches, RF protocol into `rf_cycles`, construct); view page with confirm codes; quick line and `@` tags (proposed rows dashed, saved as suggested unless accepted; reading look); group presets; hospitals and members pages; audit and change log. The four worked examples validate; example 1 entered through the browser. Not in M2: implants (M4 with complications), admissions (only the operation's hospital for now). |
| M3 | Built 2026-11-18, awaiting acceptance. One search line (same words and notation as the quick line, plus a year or year range) becomes filters shown as removable chips; a filter panel for every facet (procedure, level, side, approach, route, technology, RF mode, branches, construct, diagnosis, surgeon, hospital, dates, confirmed/suggested). Procedure filters match one procedure row together; a level finds the ranges that cover it. Patient list, patient page and patient form (AMKA check, consent date). Lists and views audited with filter names only. The acceptance query works in English and Greek (tested). |
| M4 | Built 2026-11-18, awaiting acceptance ([plans/m4.md](plans/m4.md)). Implants as groups of identical implants with company (managed list), product, count, levels, side and size parameters per category (`vocab/implants.yaml`), searchable by range; `@υλ` proposes implant rows. Complications (Clavien-Dindo, timing from the onset date, up to 90 days, reoperation linked both ways). Follow-up visits at fixed windows with the scores each case is asked for, baseline scores, lock, and a due list per surgeon. Window limits and score sets are a draft (open question 19). |
| M1 | Built 2026-11-18. 15 tables from the data dictionary (`web/classes/yaml/`), 710 code values in 108 lists, NeuroLog IDs (`web/nl_id.php`). `update.sh` builds the database and loads the lists from nothing (tested on an empty database). Dashboard counters are real; admins see the code lists read-only. |

## Repository layout (from M0)

Follows ZPMS / MedScribe: `config/` (`*.in` templates committed, real files gitignored), `web/` (DocumentRoot:
`index.php`, `classes/yaml/`, `templates/`, `css/`, `js/`, `assets/`), `bin/` (CLI, `run_tests.sh`, `post-update.sh`
only if unavoidable), `tests/`, `sql/admin.sql.in`. Design documents stay in `docs/`, code lists in `vocab/`.
