# NeuroLog seeding plan — extract real operation semantics from live APYweb

**Run this in a Claude Code session on the APYweb production server**, in the APYweb checkout
(the one with a working `db_config.php` and the live `apydb`). It reads the `operations` table and
produces files that describe how operations and diagnoses are actually written in practice. These
files are used to seed and test NeuroLog's closed classification ([data dictionary](../data-dictionary.md),
sections "Classification model" to "Worked examples").

Goal: every distinct `operation` / `pathology` / `op_type` text in APYweb mapped to
NeuroLog's procedure / diagnosis codes and facets, with a confidence, plus a list of what does
not fit, so the lists can be corrected **before** NeuroLog is built.

> **Status.** First run: 14 November 2026 (read-only, privacy check passed). Its results are **not** in this
> repository and must never be committed here: the repository is public and the results hold the practice's
> own operation wording and volumes. They were reviewed privately and turned into the decisions in
> [../decisions.md](../decisions.md).
>
> **For a re-run:** step 5 below still lists the public-site (erweb) codes used for the first pass. Map to the
> registry codes in [`vocab/procedures.csv`](../../vocab/procedures.csv), [`vocab/diagnoses.csv`](../../vocab/diagnoses.csv)
> and [`vocab/facets.yaml`](../../vocab/facets.yaml) instead, and apply the «Διαδερμική» and cage/screw rules
> from the decision log.

---

## 0. Hard rules (read before doing anything)

1. **Read-only.** Never run INSERT, UPDATE, DELETE, ALTER, CREATE, DROP or TRUNCATE against `apydb`.
   Run every query inside `START TRANSACTION READ ONLY;` … `ROLLBACK;`, or open the PDO
   connection and immediately execute `SET SESSION TRANSACTION READ ONLY`. If a read-only
   MySQL account exists, use it instead of `apyuser`.
2. **No git.** Do not commit, push, branch or modify any tracked file in the APYweb checkout.
   All work goes in `var/neurolog-seed/` (`var/` is gitignored). Create it with mode 0700.
3. **No patient-identifying data in any output file.** Outputs may contain operation, pathology,
   type, clinic and doctor texts, counts and years. They must never contain `patient_name`,
   `patient_age` per row, `admission_code`, `notes`, amounts, dates finer than a year, or
   APYweb `operations.id`. Step 7 checks this before anything leaves the server.
4. **Do not read `notes`.** It is free text and may hold anything.
5. **No network calls** except what the session itself needs. Do not upload outputs anywhere;
   the person running the session decides where they go.
6. **Charset.** Connect with `utf8mb4` (see APYweb CLAUDE.md, "Database Setup"); the texts are Greek.
7. If something here contradicts what you find on the server (column missing, other schema),
   stop and report it instead of improvising.

## 1. Set up

```sh
mkdir -p var/neurolog-seed && chmod 700 var/neurolog-seed
```

Write one throwaway PHP CLI script, `var/neurolog-seed/extract.php`, that:

- reads credentials through the app's own `db_config.php` (do not copy them into the script);
- opens PDO with `charset=utf8mb4` and sets the session read-only;
- does all text processing in PHP (MariaDB regex is enough for counting, not for tokenising Greek).

Normalisation function `norm($s)` used everywhere below:
trim → collapse whitespace → uppercase → strip Greek accents (reuse `upper_gr_php()` from
`db_config.php`) → unify dashes (– — ‐ → -) → map Latin look-alikes inside level tokens
(A→Α only where followed by a digit, see step 4) → strip trailing punctuation.
Keep the raw text too; outputs show both.

## 2. Profile the table → `00_profile.md`

Report, overall and per year of `op_date`:

- number of operations;
- fill rate (non-NULL and non-empty after trim) of `operation`, `pathology`, `op_type`, `clinic`,
  `surgeon`, `surgeon2`, `anesthesiologist`, `receipt_type`, `admission_code` (fill rate only,
  never values for admission_code);
- number of distinct raw and distinct normalised texts for `operation`, `pathology`, `op_type`;
- length distribution of `operation` and `pathology` (min, median, p90, max characters).

```sql
SELECT YEAR(op_date) y, COUNT(*) n,
       SUM(NULLIF(TRIM(operation),'') IS NOT NULL) has_op,
       SUM(NULLIF(TRIM(pathology),'') IS NOT NULL) has_path,
       SUM(NULLIF(TRIM(op_type),'')  IS NOT NULL) has_type
FROM operations GROUP BY y ORDER BY y;
```

## 3. Distinct texts → `10_operation_texts.csv`, `11_pathology_texts.csv`, `12_op_type.csv`, `13_team_clinic.csv`

For each field: one row per **normalised** text with columns

`norm_text, raw_variants (up to 5, " || "-separated), n, first_year, last_year`

sorted by `n` desc. `13_team_clinic.csv` holds `field, value, n` for `clinic`, `surgeon`,
`surgeon2`, `anesthesiologist`, `receipt_type` (these are staff and clinic names, not patients).

Also write `14_pairs.csv`: `norm_operation, norm_pathology, n` — the pairs that occur together,
which is how a procedure gets tied to a diagnosis.

## 4. Token analysis → `20_tokens.csv`, `21_levels.csv`

Split every normalised `operation` and `pathology` text into tokens (split on spaces and
`, ; / ( ) + &`, keep tokens like `L4-L5` and `Ο4-Ο5` whole). Output `token, n_texts, n_operations,
category_guess` sorted by `n_operations`.

`category_guess` from the patterns below. **They are a starting point, not a truth**: report
how often each pattern fires and list the frequent tokens that match none of them.

| Category | Patterns to try (after normalisation, accents stripped) |
| --- | --- |
| Spine level | `([CΑ]|[TΘ]|[LΟ]|[SΙ])\s?(\d{1,2})(\s?-\s?([CΑTΘLΟSΙ])?\s?(\d{1,2}))?` — Greek usage: Α = αυχενική (cervical), Θ = θωρακική, Ο = οσφυϊκή (lumbar), Ι = ιερό (sacral). Check that Greek Ο/Α are not Latin O/A typed by mistake. Single vertebra (`Ο1`) vs segment (`Ο4-Ο5`) vs range (`Ο3-Ο5`) are three different things — report each |
| Spine region words | ΑΥΧΕΝ, ΘΩΡΑΚ, ΟΣΦΥ, ΙΕΡ, ΚΟΚΚΥΓ, ΑΜΣΣ, ΘΜΣΣ, ΟΜΣΣ, ΟΙΜΣΣ, CERVICAL, THORACIC, LUMBAR, SACR |
| Side | ΑΡΙΣΤΕΡ, ΔΕΞΙ, ΑΜΦΟΤΕΡΟΠΛ, ΑΜΦΙΠΛ, ΑΡ., ΔΕ., (Α), (Δ), LEFT, RIGHT, BILAT |
| Approach / technique | ΕΝΔΟΣΚΟΠ, ΔΙΑΔΕΡΜ, ΜΙΚΡΟΧΕΙΡ, ΜΙΚΡΟΣΚΟΠ, ΕΛΑΧΙΣΤΑ ΕΠΕΜΒ, MIS, MISS, ΣΩΛΗΝ (tubular), ΡΟΜΠΟΤ, ΠΛΟΗΓ (navigation), ΣΤΕΡΕΟΤΑΚ, ΕΝΔΟΡΙΝ, ΔΙΑΣΦΗΝΟΕΙΔ |
| Named spinal procedure | TLIF, PLIF, ALIF, XLIF, OLIF, LLIF, ACDF, ACCF, ULBD, TDR |
| Action words | ΔΙΣΚΕΚΤΟΜ, ΠΕΤΑΛΕΚΤΟΜ, ΠΕΤΑΛΟΤΟΜ, ΤΡΗΜΑΤΟΤΟΜ, ΑΠΟΣΥΜΠΙΕΣ, ΣΠΟΝΔΥΛΟΔΕΣ, ΣΤΑΘΕΡΟΠΟΙΗΣ, ΚΥΦΟΠΛΑΣΤ, ΣΠΟΝΔΥΛΟΠΛΑΣΤ, ΑΡΘΡΟΠΛΑΣΤ, ΣΩΜΑΤΕΚΤΟΜ, ΒΙΟΨ, ΑΦΑΙΡΕΣ, ΕΚΤΟΜ, ΚΕΝΩΣ, ΠΑΡΟΧΕΤΕΥΣ, ΚΡΑΝΙΟΤΟΜ, ΚΡΑΝΙΕΚΤΟΜ, ΚΡΑΝΙΟΠΛΑΣΤ, ΚΟΙΛΙΟΣΤΟΜ, ΚΟΙΛΙΟΠΕΡΙΤΟΝ, ΑΠΟΛΙΝΩΣ (clipping), ΔΙΕΓΕΡΤ, ΝΕΥΡΟΔΙΕΓΕΡ, ΑΝΤΛΙ, ΘΕΡΜΟΚΑΥΤΗΡ, ΡΑΔΙΟΣΥΧΝ, RF, ΕΓΧΥΣ, ΑΠΟΚΛΕΙΣΜ, ΑΠΕΛΕΥΘΕΡ, ΝΕΥΡΟΛΥΣ, ΣΥΡΡΑΦ, ΑΦΑΙΡΕΣΗ ΥΛΙΚ (hardware removal), ΑΝΑΘΕΩΡ (revision) |
| Diagnosis words | ΚΗΛΗ, ΣΤΕΝΩΣ, ΟΛΙΣΘΗΣ, ΣΠΟΝΔΥΛΟΛΙΣΘ, ΚΑΤΑΓΜ, ΜΕΤΑΣΤΑ, ΟΓΚΟΣ, ΓΛΟΙΩΜ, ΓΛΟΙΟΒΛΑΣΤ, ΜΗΝΙΓΓΙΩΜ, ΑΔΕΝΩΜ, ΝΕΥΡΙΝΩΜ, ΣΒΑΝΝΩΜ, ΑΙΜΑΤΩΜ, ΑΙΜΟΡΡΑΓ, ΑΝΕΥΡΥΣΜ, ΔΥΣΠΛΑΣΙ, ΥΔΡΟΚΕΦΑΛ, ΣΥΡΙΓΓΟΜΥΕΛ, CHIARI, ΑΠΟΣΤΗΜ, ΜΥΕΛΟΠΑΘ, ΡΙΖΟΠΑΘ, ΙΣΧΙΑΛΓ, ΟΣΦΥΑΛΓ, ΑΥΧΕΝΑΛΓ, ΝΕΥΡΑΛΓ, ΤΡΙΔΥΜ, ΣΠΑΣΤΙΚ, ΣΠΑΣΜΟΣ, PARKINSON, ΤΡΟΜΟΣ, ΕΠΙΛΗΨ, ΚΑΡΠΙΑΙΟ, ΩΛΕΝΙ, ΠΕΡΟΝΙΑΙ |
| Peripheral nerve / site | ΜΕΣΟ ΝΕΥΡ, ΩΛΕΝΙΟ, ΚΕΡΚΙΔΙΚ, ΠΕΡΟΝΙΑΙΟ, ΚΝΗΜΙΑΙΟ, ΙΣΧΙΑΚΟ, ΒΡΑΧΙΟΝΙΟ ΠΛΕΓΜ, ΚΑΡΠΟΣ, ΑΓΚΩΝΑΣ |
| Cranial location | ΜΕΤΩΠ, ΚΡΟΤΑΦ, ΒΡΕΓΜΑΤ, ΙΝΙΑΚ, ΝΗΣΙΔ, ΠΑΡΕΓΚΕΦΑΛ, ΣΤΕΛΕΧ, ΥΠΟΦΥΣ, ΕΦΙΠΠ, ΚΟΙΛΙ, ΒΑΣΗ ΚΡΑΝΙΟΥ, ΓΕΦΥΡΟΠΑΡΕΓΚ (CPA) |

`21_levels.csv`: every level expression found, as `raw, parsed_from, parsed_to, kind (vertebra|segment|range|root),
region_derived, n`. Flag impossible ones (Ο7, Α9, L5-L3 reversed, Θ13) and Latin/Greek mix-ups separately.

## 5. Mapping proposal → `40_mapping_operation.csv`, `41_mapping_pathology.csv`

For every normalised text from step 3 (all of them, rarest included), propose:

`norm_text, n, procedure_or_diagnosis_code, action_or_pathology_class, domain, region, level_from, level_to,
level_kind, side, approach_type, approach_route, technology, episode, qualifiers, confidence (high|medium|low|none),
rule (which tokens decided it), note`

Codes must come **only** from the lists below. If nothing fits, leave the code empty, set
`confidence = none` and explain in `note`. Never invent a code.

A text holding two procedures (e.g. decompression + fusion) gets one row per procedure, same `norm_text`.

**Procedure codes** (NeuroLog core list = erweb procedure names, kebab-case):
aneurysm-clipping, anterior-cervical-discectomy-fusion, awake-craniotomy, brain-tumor-radiofrequency-ablation,
burr-hole-hematoma-evacuation, cerebellopontine-angle-surgery, cervical-disc-arthroplasty, cranioplasty,
craniotomy-hematoma-evacuation, craniotomy-tumor-resection, deep-brain-stimulation,
endoscopic-endonasal-skull-base-surgery, endoscopic-spine-surgery, endoscopic-third-ventriculostomy,
epilepsy-surgery, eyebrow-craniotomy, facet-joint-radiofrequency-neurotomy, hydrocephalus-shunting, kyphoplasty,
lateral-lumbar-interbody-fusion, microdiscectomy, microvascular-decompression, minimally-invasive-laminectomy,
percutaneous-trigeminal-procedures, peripheral-nerve-decompression, peripheral-nerve-repair,
posterior-cervical-fusion, posterior-fossa-decompression, posterior-lumbar-fusion, programmable-pump-implantation,
radiofrequency-ablation, robotic-spinal-fusion, sacral-root-stimulation, spinal-biopsy, spinal-cord-stimulation,
spinal-cord-tumor-resection, spinal-injections, stereotactic-brain-biopsy, stereotactic-radiosurgery,
syringo-subarachnoid-shunt, thoracic-discectomy, thoracolumbar-stabilization, vagus-nerve-stimulation,
vertebral-tumor-radiofrequency-ablation.

Missing from that list and likely to appear (propose them as **new codes**, prefixed `NEW:`, with a count):
open lumbar laminectomy/decompression (non-MIS), hardware removal, wound revision/debridement, CSF leak repair,
external ventricular drain, ICP monitor, lumbar drain, shunt revision, decompressive craniectomy, corpectomy,
deformity correction/osteotomy, cervical laminoplasty, intradural exploration, IPG replacement.

**Diagnosis codes** (erweb condition names): arachnoid-cyst, arteriovenous-malformation, brain-metastases,
brain-tumor-glioma, carpal-tunnel-syndrome, cauda-equina-syndrome, cavernoma, cerebral-aneurysm,
cervical-disc-herniation, cervical-myelopathy, cervical-radiculopathy, chiari-malformation, chronic-low-back-pain,
colloid-cyst, cubital-tunnel-syndrome, drug-resistant-epilepsy, dural-arteriovenous-fistula, epidural-hematoma,
failed-back-surgery-syndrome, hemifacial-spasm, high-grade-glioma, idiopathic-intracranial-hypertension,
intracerebral-hematoma, low-grade-glioma, lumbar-disc-herniation, lumbar-spinal-stenosis, meningioma,
meralgia-paresthetica, normal-pressure-hydrocephalus, obstructive-hydrocephalus, osteoporotic-vertebral-fracture,
parkinsons-disease-tremor, peripheral-nerve-injury, peripheral-nerve-tumors, peroneal-nerve-entrapment,
pituitary-adenoma, sacroiliac-joint-dysfunction, shunt-obstruction, spasticity, spinal-cord-tumors,
spinal-epidural-abscess, spondylolisthesis, subarachnoid-hemorrhage, subdural-hematoma, syringomyelia,
thoracic-disc-herniation, traumatic-brain-injury, traumatic-spinal-fracture, trigeminal-neuralgia,
vertebral-metastases, vestibular-schwannoma. Same `NEW:` rule for anything else.

**Facet values** (closed; anything else → empty + note):

- domain: cranial, spinal, peripheral_nerve, functional_pain, CSF
- action: decompression, discectomy, excision_resection, biopsy, evacuation_drainage, fusion, fixation_without_fusion,
  arthroplasty, vertebral_augmentation, osteotomy_deformity_correction, CSF_diversion, fenestration, clipping,
  vascular_excision, ablation, lesioning, stimulation_implant, pump_implant, injection_block, neurolysis_release,
  nerve_repair_graft, nerve_transfer, dural_repair, cranioplasty_reconstruction, device_revision, device_removal,
  hardware_removal, exploration, other
- pathology class: degenerative, neoplastic_primary, neoplastic_metastatic, vascular, traumatic, infectious,
  congenital_developmental, inflammatory, CSF_disorder, functional, pain, entrapment, iatrogenic_postoperative, other
- spine region: craniocervical, cervical, cervicothoracic, thoracic, thoracolumbar, lumbar, lumbosacral,
  sacral_coccygeal, sacroiliac_pelvis — **derived from the levels**, not from words, when levels exist
- level_kind: segment (disc/fusion/decompression), vertebra (body procedures), root, range (laminae)
- side: left, right, bilateral, midline, n/a (blank in the text → leave empty, do not guess)
- approach_type: open, mini_open, tubular_MIS, endoscopic_uniportal, endoscopic_biportal, endoscopic_endonasal,
  percutaneous, stereotactic_frame, stereotactic_frameless, keyhole
- approach_route: see data dictionary "Procedure facets" #8 (pterional, retrosigmoid, transforaminal, …)
- technology: microscope, exoscope, endoscope, navigation, robot, intraop_CT, intraop_MRI, intraop_US,
  neuromonitoring, awake_mapping, 5-ALA, fluorescein, ICG, stereotactic_frame
- episode: primary, revision_same_level, revision_adjacent_level, staged, return_to_theatre

Confidence rules: **high** = code and every stated facet determined by explicit tokens; **medium** = code certain,
a facet inferred (e.g. approach from a named procedure); **low** = code guessed from a single weak word; **none** = no fit.
Use the pathology text of the same operation (`14_pairs.csv`) to raise or lower confidence, never to invent a level or side.

`12_op_type.csv` → `42_mapping_op_type.csv`: each APYweb «Είδος» value mapped to a NeuroLog `op_category` and, where it
clearly implies it, a `domain`. Report values that mix categories.

## 6. What does not fit → `50_unmapped.md`, `60_coverage.md`

`50_unmapped.md`:
- texts with `confidence = none`, grouped by their most frequent tokens;
- proposed `NEW:` codes with counts and example texts;
- frequent tokens (≥ 3 operations) that matched no category in step 4;
- facets that the data never fills (e.g. side almost never written) — this decides which facets must be
  asked for at entry rather than imported;
- ambiguous abbreviations (same token, two meanings) with examples.

`60_coverage.md`: share of **operations** (weighted by `n`, not distinct texts) whose operation text maps with
high / medium / low / none confidence, overall and per year; same for pathology; and how many distinct texts
cover 80 % and 95 % of operations.

## 7. Privacy check → `90_privacy_check.md` (must pass before anything leaves the server)

1. Build the set of normalised patient surnames and given names from `operations.patient_name`
   (tokens of ≥ 4 letters), **in memory only — never written to disk**.
2. Scan every output file for any of those tokens as whole words. Exclude tokens that are also ordinary
   medical words found in ≥ 5 different operation texts (e.g. a surname that is also a disease eponym).
3. Every hit: replace the token in the output with `[REDACTED]` and list in `90_privacy_check.md` only the file,
   line number and the fact that a redaction happened (not the token).
4. Confirm by grep that no output contains: digits matching AMKA (11 digits), dates `dd/mm/yyyy` or `yyyy-mm-dd`,
   e-mail addresses, phone numbers (≥ 10 digits).
5. Delete `extract.php` intermediate caches if any held names. Keep the script itself only if it never wrote names.
6. Write the result: PASS or FAIL with counts. On FAIL, fix and re-run steps 3–7; do not hand over a failing set.

## 8. Hand-over

- Output folder: `var/neurolog-seed/` with files `00_…` to `90_…` and a `README.md` listing each file, row counts,
  the run date and the `apydb` row count at the time.
- Show the person running the session `60_coverage.md` and the top 20 rows of `50_unmapped.md` in chat.
- Do not copy, upload or e-mail the folder. The person decides how it reaches the NeuroLog design session
  (e.g. they pass the CSVs back by hand after reading `90_privacy_check.md`).
- Leave `apydb` and the git checkout exactly as found: `git status` must show nothing new outside `var/`.

## 9. Done when

- [ ] Every normalised operation and pathology text appears in a mapping file, rarest included
- [ ] No code outside the lists above, except clearly marked `NEW:` proposals
- [ ] Coverage report written, weighted by operations
- [ ] Privacy check PASS
- [ ] `git status` clean apart from `var/`; no write was made to `apydb`
