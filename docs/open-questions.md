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

## Ledger

7. Who confirms suggested codes: the operating surgeon only, or any member?
8. Access rules: who sees whose cases, who can export; every view and export logged.
9. Retention period, and what happens to a member's data if they leave the group.

## Research

10. Which validated Greek translations of ODI, NDI and EQ-5D? EQ-5D needs a licence from the EuroQol group.
11. Authorship and data-use rules for studies on the shared data.
12. Legal basis, ethics approval per hospital, DPIA and consent forms (see "Governance" in
    [data-dictionary.md](data-dictionary.md)).

## Brand

13. **Colours.** The logo uses navy `#003399` and cyan `#00C8F8`–`#00E8FF`; the palette in the README says Deep Navy
    `#17365D` and Neuro Cyan `#20A6B2`. Which one does the interface follow?
14. **Small favicon.** At 16 px the logo's nodes blur into dots. Keep it, or draw a simplified mark (the N with one
    node) for 16 and 32 px? See [brand.md](brand.md).
