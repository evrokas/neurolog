# NeuroLog design documents

NeuroLog is in the **design phase**: no application code yet. These documents define what it records and how.

| Document | What it holds |
| --- | --- |
| [data-dictionary.md](data-dictionary.md) | Every field NeuroLog stores: tables, identifiers, import from APYweb, the classification model, code lists, facets, anatomy, governance. The main reference. |
| [decisions.md](decisions.md) | Decision log, newest first: what was decided and why. |
| [open-questions.md](open-questions.md) | Questions still to be answered before building or importing. |
| [naming.md](naming.md) | Why the name NeuroLog, and the names rejected. |
| [seeding/apyweb-seed-plan.md](seeding/apyweb-seed-plan.md) | Read-only plan, run on the APYweb server, that extracts how operations are actually written, to test the code lists against real data. |
| [../vocab/](../vocab/) | Machine-readable code lists: `procedures.csv`, `diagnoses.csv`, `facets.yaml`. |

## In one paragraph

NeuroLog is an **operation ledger first** (identified records, part of patient care), and a source for audit and
research second (pseudonymised exports only). Each operation is classified as **one core procedure code plus
independent closed facets** (region, level, side, approach, route, compartment, technology, episode); diagnoses
the same way. Patients and operations get **NeuroLog's own short IDs** (`P-7K3Q-XM2A-R`). Operations already in
**APYweb** (the practice's billing app) are imported with their source key and coded afterwards; a code counts in
statistics only once a surgeon has confirmed it.

## Keeping the documents in step

- `data-dictionary.md` is the public copy of a private working document. Counts from the group's own operations
  stay in the private copy; this repository carries no data from APYweb or any other system.
- The code lists exist twice: as tables in `data-dictionary.md` and as files in `vocab/`. Change both together.
- A value in a code list is **retired, never renamed or deleted**, so old records keep their meaning.
