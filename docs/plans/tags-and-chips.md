# Tags and chips in NeuroLog — subplan (DRAFT, for approval)

Adapted from ZPMS's clinical tags (`evrokas/zpms`, `docs/CLINICAL_TAGS_PLAN.md`: inline `@δγ`/`@επ` tags in
notes, Greek-keyboard suggestions from fixed lists plus previous entries, a reading look, extracted values for the
patient summary and filters) and from MedScribe's chips (fields shown as chips, case-feature chips that switch a set
of options on and off).

## The difference that shapes everything

In ZPMS the note is the source of truth and tags pull facts **out of free text**. In NeuroLog the coded fields are the
source of truth (a code counts only when confirmed) and free text is "never analysed". Copying ZPMS one-to-one would
give every fact two homes, the note and the field, which can disagree. So in NeuroLog:

- **Chips** are how coded values are **picked and shown** (the form side).
- **Tags** are a **fast way to fill** coded fields by typing the group's own wording. They create *suggested* values
  shown as chips; a person confirms them. The text is kept verbatim, never overwritten, and nothing is counted until
  it is confirmed, the same rule as the APYweb import.

## A. Chips for coded values (M2, entering an operation)

1. **One chip picker for every coded field**: a text box with type-ahead over the field's list in `nl_codes`. Chosen
   values show as chips with the code's Greek label (English on hover), × to remove. Single-value fields show one chip;
   multi-value fields (technology, neuromonitoring, clinical syndrome, adjuncts, branches) show several.
2. **Matching like ZPMS**: case- and accent-insensitive, Greek or Latin keyboard (`δισκ`, `disc`, `ΚΜΔ` all find
   discectomy / disc herniation), labels and codes both searched.
3. **Suggestion order like ZPMS SmartLists**: the list's values, then the signed-in surgeon's own most used values for
   that field (counted from their confirmed rows), with a count. Retired values never appear in a picker; on an old
   record they still show, greyed, so nothing is lost.
4. **Keyboard**: ↑↓ move, Enter picks, Backspace in an empty box removes the last chip, Esc closes. Tab is never
   taken over (moves to the next field).
5. **Level grid** (from ZPMS): a grid of segments by region (C, T, L, S); click or Space to tick several; also
   typed in the group's notation (`Ο4/5`, `Α5/6/7`, `Ο2/3,3/4`), shown back as chips `L4-L5`. Impossible levels
   refused at entry (data dictionary).
6. **Colour by family**, pale only, like ZPMS's palette: diagnosis, procedure, levels, approach, technology,
   implants, each with a tint/ink pair defined once in CSS tokens. Status shown on the chip: dashed outline =
   *suggested*, solid = *confirmed*.
7. **Preset chips** (MedScribe's case-feature chips): a row of the surgeon's frequent combinations above the
   procedure rows, e.g. «Διαδερμική δισκεκτομή» = discectomy + tubular_MIS + microscope. One click adds the rows with
   those facets; the levels and side are still asked. Presets are kept per surgeon (a small table), created from an
   existing operation with «save as preset».

## B. Tags: typing the group's own wording (M2, reused by M5)

1. **Quick line** at the top of the operation form: the surgeon types the operation the way it is written today,
   e.g. «Διαδερμική δισκεκτομή Ο4/5 αρ, TLIF, σπονδυλοδεσία». A parser proposes procedure rows (discectomy +
   tubular_MIS, interbody_fusion transforaminal, pedicle_screw_fixation percutaneous, L4-L5, left) as *suggested*
   chips with the construct (cage_and_screws). Accept all, accept one, or change one.
2. **The same parser runs the APYweb import (M5)**, so the import's suggestions and the form's suggestions can never
   disagree. Its rules come from `vocab/` (`diadermiki`, `level_notation`, the procedure aliases) and the decisions
   log (PLIF/TLIF never implies screws; «σπονδυλοδεσία» means screws; «Διαδερμική» = tubular except percutaneous
   screws).
3. **Aliases**: each procedure and diagnosis gets a list of the group's own words (`aliases` in `vocab/`, e.g.
   discectomy: δισκεκτομή, μικροδισκεκτομή; disc_herniation: ΚΜΔ, κήλη). Built from the seeding results kept in the
   private repository; only the words go public, never counts or texts.
4. **Explicit `@` tags** (ZPMS syntax) are optional inside the quick line and the operative note for things the
   parser cannot guess: `@δγ` diagnosis, `@επ` levels, `@πλ` side, `@προσ` approach, `@υλ` implant. Same end rules
   as ZPMS (comma, `·`, full stop + space, line break, next tag; longest name wins; e-mail is not a tag).
   Each tag fills a suggested chip; it never writes a confirmed value.
5. **Reading look**: in the record view, tagged words in the operative note are shown in the pale ink of their
   family, tag words hidden (ZPMS section 9). The note itself is saved exactly as typed.

## C. Finding operations (M3)

- Active filters shown as removable chips above the list («L4-L5 ×», «tubular_MIS ×», «2026 ×»).
- One search box that accepts the same tags and notation: `@επ Ο4/5 @προσ tubular` = the filter.

## What is not copied from ZPMS

- No mirror-behind-textarea colouring while typing in the operative note in M2 (ZPMS phase 2); the quick line is
  one input with chips below it. Can come later if the operative note is used much.
- No prescriptions value type (NeuroLog records no medication).
- No extracted-values table like ZPMS's `note_fields`: extracted values go straight into the coded fields as
  *suggested*.

## Build order and tests

| Step | Milestone | Done when |
| --- | --- | --- |
| 1. Chip picker + level grid + level parser (PHP and JS, one shared JSON file of test cases, like ZPMS) | M2 | Every data-dictionary worked example can be entered with chips only; impossible levels refused |
| 2. Suggestions from previous entries; colours; suggested/confirmed look | M2 | A surgeon's own frequent values come first |
| 3. Quick-line parser + aliases + `@` tags (shared test cases incl. every «Διαδερμική» rule) | M2 | The worked examples typed as one line give the right suggested rows |
| 4. Preset chips | M2 | «save as preset» and one-click reuse work |
| 5. Filter chips and tag search | M3 | "left L4-L5 tubular discectomies in 2026" typed as one line |

## Decisions needed from you

1. Agree that tags **suggest** coded values and never confirm them (the text stays verbatim)?
2. The tag names in B4 (`@δγ @επ @πλ @προσ @υλ`): keep, rename, or drop explicit tags and rely on the parser only?
3. Presets per surgeon, or shared by the group?
