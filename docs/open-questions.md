# Open questions

Questions still to be answered by the group before NeuroLog is built or the APYweb import is run.
When one is answered, move it to [decisions.md](decisions.md).

## Coding of existing APYweb operations

1. **Fracture cause.** Almost no existing fracture text says osteoporotic, traumatic or pathological. Should a
   fracture treated with kyphoplasty default to `osteoporotic`, with the other causes recorded only when written,
   or should every one be set by hand from the patient file?
2. **«Δισκοπλαστική» without a product.** Does it always mean DiscoGel (only «Gelstiks» names its product)?
3. **«Διαδερμική αποσυμπίεση».** Is it the same procedure as «πεταλεκτομή»? Imported as `laminectomy_decompression`
   (tubular MIS) until answered.
4. **Bare texts** («Αφαίρεση», «Αποσυμπίεση», «Κρανιοτομία», «Αναθεώρηση», «Διαδερμική» alone): imported as
   `uncoded` and coded by hand from the patient file.
5. **«Σύνδεση περιφερικού άκρου»**: what is connected (shunt catheter, nerve)?
6. Import all APYweb operations, or only from a start date?
7a. **Trigeminal branches of past cases.** Existing texts never name the branch treated. Fill them from the operative
    notes before import, or import as `unknown` and fill later?
7b. **RF plausibility limits.** Confirm the limits that refuse typos in the RF protocol (e.g. thermal 40–95 °C, pulsed
    limit 37–45 °C, pulsed 1–100 V, 1–20 Hz, 1–50 ms); table in [rf-protocol.md](rf-protocol.md).

16. ~~Greek labels missing~~ — answered 2026-11-20: the surgeon gave every Greek label (`vocab/procedures.csv`, `vocab/diagnoses.csv`).
17. **Default action and level kind per procedure** (`vocab/procedures.csv`, columns `action`, `level_kind`): drafted
    2026-11-18 from the procedure names; please review (e.g. vertebral_tumour_ablation = ablation, rf_neurotomy =
    lesioning, intradiscal_hydrogel = injection_block).

18. **Words per code** (`vocab/aliases.yaml`, used by the quick line, the `@` tags and later the APYweb import):
    drafted 2026-11-18 from general clinical vocabulary. Compared with ZPMS's tag lists on 2026-11-19: cervical fusion wording and
    the region abbreviations settled (decisions); still open: «ριζοπάθεια». Add the group's own abbreviations and spellings; remove any
    word that means something else in the group's usage.

19. **Follow-up windows and scores per case** (`vocab/followup.yaml`), drafted 2026-11-18 for M4: windows in days
    after the operation 6w = 28–63, 3m = 70–120, 12m = 300–430, 24m = 640–820, shown on the due list 14 days early;
    which scores each case is asked for follows the data dictionary ("C if lumbar / cervical / cranial /
    myelopathy / epilepsy", BNI for trigeminal). Please confirm the window limits and the score sets.

## Ledger

9. Retention period, and what happens to a member's data if they leave the group.

## Research

10. Which validated Greek translations of ODI, NDI and EQ-5D? EQ-5D needs a licence from the EuroQol group.
11. Authorship and data-use rules for studies on the shared data.
12. Legal basis, ethics approval per hospital, DPIA and consent forms (see "Governance" in
    [data-dictionary.md](data-dictionary.md)).
15. **Reading APYweb without changing it** (needed for milestone M5): a read-only database account for NeuroLog, or a
    file export run on the APYweb server?

## Brand

14. **Small favicon.** At 16 px the logo's nodes blur into dots. Keep it, or draw a simplified mark (the N with one
    node) for 16 and 32 px? See [brand.md](brand.md).
