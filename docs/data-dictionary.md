# NeuroLog — Data Dictionary (draft v0.2)

Draft v0.2 · 2026-11-15 · public copy (the working copy is a private living document; counts from the group's own operations are kept there, not here)

## Purpose and scope

NeuroLog is first an operation ledger: one coded, identified record of every neurosurgical operation the group performs. Audit and research statistics come second, and are produced from it through a pseudonymised export (see Governance). Every field below exists because the ledger, an audit or a research question needs it.

Research questions the minimum dataset must answer:

1. Volume and case mix: how many of which procedure, by surgeon, hospital and year.
2. Complications: rate and grade (Clavien-Dindo) by procedure, approach and surgeon role.
3. Outcomes: change in the condition-specific score (ODI, NDI, mJOA, VAS, KPS, mRS) from baseline to 3 and 12 months.
4. Technique comparisons: e.g. endoscopic vs microscopic discectomy, navigation/robot vs freehand screws.
5. Resource use: operating time, blood loss, length of stay, return to theatre, readmission.

Design rules:

- **Ledger first.** Records are identified (patient name is stored) because the ledger is part of patient care. Research never reads the ledger directly; it reads a pseudonymised export.
- **NeuroLog's own identifiers.** Patients and operations get NeuroLog IDs (see Identifiers). No ID is borrowed from ZPMS, APYweb or a hospital.
- **Controlled vocabularies, not free text**, for every field used in analysis. Free text is allowed only in note fields that are never analysed.
- **Imported, then coded.** Operations already in APYweb are imported with their source ID; their free-text pathology and operation are coded afterwards, and a code is only trusted once a person has confirmed it.
- **One operation = one record.** A patient with two operations has two operation records linked to one patient record.
- **Core vs extended.** Core fields are required for every case and take under 3 minutes to enter. Extended fields are filled only for cases enrolled in a specific study.

## Conventions

Every table below uses the same columns: **Field** (database name), **Type**, **Req** (C = core, required; E = extended, per study; A = automatic), **Values / units**, **Answers**.

| Type | Meaning |
| --- | --- |
| id | System-generated, never shown to patients |
| enum | One value from a list in *Controlled vocabularies* |
| multi | Several values from a list |
| date | YYYY-MM-DD; time of day only where stated |
| int / dec | Number; unit in the field name or the Values column |
| bool | yes / no |
| text | Free text, never analysed |

- **Missing data is coded, never blank.** Every enum includes `unknown`; scores and numbers allow `not_done` with a reason. Blank means "not yet entered" and blocks a case from being marked complete.
- **Dates relative to surgery.** Follow-up windows are counted from `op_date`: 6 weeks (±2 w), 3 months (±4 w), 12 months (±8 w).
- **Coding.** Diagnoses map to ICD-10; procedures use the group's own list (mapped to erweb procedure machinenames, so the public site and the registry share one vocabulary). Complications use Clavien-Dindo.
- **Audit.** Every record carries created/updated by and at, and a change log. A locked record (follow-up complete) changes only with a reason.

## Identifiers

Every patient and every operation gets a short NeuroLog ID: 8 random characters plus 1 check character, written in groups, e.g. `P-7K3Q-XM2A-R`. It is NeuroLog's own: not the ZPMS guid, not the APYweb id, not AMKA.

| Part | Rule |
| --- | --- |
| Prefix | `P` patient, `O` operation (also `A` admission, `C` complication, `F` follow-up) |
| Body | 8 characters from Crockford Base32 (`0–9`, `A–Z` without I, L, O, U), drawn from a cryptographic random generator: 40 bits, about 1.1 × 10¹² possible values |
| Check | 1 Crockford check symbol (mod 37) over the body; catches every single mistyped character and every swap of two neighbouring characters; 5 of the 37 values use the extra check symbols \* \~ $ = U, which appear only in this last position |
| Display | `P-XXXX-XXXX-C`; stored without hyphens (`P7K3QXM2AR`, 10 characters) |
| Input | Case-insensitive; hyphens and spaces ignored; O read as 0, I and L read as 1 |
| Uniqueness | Unique index per table; on the (very unlikely) collision a new ID is drawn |
| Lifetime | Never reused, never changed, also after a record is deleted |

Why this shape:

- **Safe.** Random, so it reveals nothing: not the patient's name, birth date, AMKA or operation date, and not how many patients the group has (a sequential number would).
- **Short.** 13 characters to read out over the phone or write on a form, against 36 for a standard UUID.
- **Enough.** At 10,000 patients the chance that one new ID collides with an existing one is about 1 in 100 million, and the unique index catches it anyway.

A random ID cannot be recomputed from the patient, so NeuroLog's patient table is the only place that links ID and identity. That table must be in the backup.

**Source keys.** Records imported from another system keep that system's key in their own column (`source`, `source_id`), next to the NeuroLog ID, never instead of it.

## Patient

One row per patient, identified. This table is the only place a NeuroLog patient ID is linked to a person; nothing in it leaves NeuroLog in a research export.

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| patient\_id | id | A | `P-XXXX-XXXX-C` (see Identifiers) | Links all operations of one patient |
| surname, given\_name | text | C | As on the patient's ID; stored also uppercased without accents for matching | Identification |
| birth\_date | date | C | Full date | Age at surgery (computed per operation) |
| sex | enum | C | male, female, unknown | Case mix, subgroup analysis |
| amka | text | E | 11 digits, checksum-validated; optional | Disambiguating same-name patients |
| match\_status | enum | A | new, matched\_by\_hand, possible\_duplicate | Duplicate control on import |
| bmi | dec | E | kg/m², at first operation | Risk factor (infection, fusion failure) |
| smoking | enum | C | never, former, current, unknown | Risk factor (fusion, wound) |
| diabetes | enum | C | none, diet, oral, insulin, unknown | Risk factor (infection) |
| anticoagulant | enum | C | none, antiplatelet, DOAC, warfarin, LMWH, other | Haemorrhage risk |
| charlson\_index | int | E | 0–37 | Comorbidity adjustment for outcome studies |
| research\_consent | enum | C | yes, no, not\_asked | Whether the patient's cases may enter a research export |
| research\_consent\_date | date | C if yes |  | Audit |

Same-name patients are common, so an imported operation is never attached to an existing patient on name alone: a name match with a different birth date, or no birth date, is marked `possible_duplicate` and a person decides. ASA class is stored per operation, not here.

## Admission

One row per hospital stay; an admission can hold more than one operation (staged surgery, early return to theatre).

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| admission\_id | id | A |  | Links operations of one stay |
| patient\_id | id | C |  |  |
| hospital | enum | C | Group's hospitals (list) | Volume by site |
| admission\_date | date | C |  | Length of stay |
| discharge\_date | date | C |  | Length of stay |
| urgency | enum | C | elective, urgent (<72 h), emergency (<24 h) | Outcome adjustment |
| admission\_route | enum | E | outpatient, ED, transfer, inpatient\_referral | Pathway analysis |
| discharge\_destination | enum | C | home, rehab, other\_hospital, nursing\_home, died | Functional outcome proxy |
| icu\_days | int | C | 0 = none | Resource use |
| readmission\_30d | bool | C | Filled at 6-week follow-up | Quality indicator |
| readmission\_reason | enum | C if yes | complication list, unrelated, unknown |  |

## Operation

The central table: one row per trip to the operating room. Procedures are a child list, because one operation often combines several (e.g. decompression + fusion).

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| op\_id | id | A | O-XXXX-XXXX-C (see Identifiers) |  |
| admission\_id | id | C |  |  |
| op\_date | date | C |  | Follow-up windows, volume by year |
| asa\_class | enum | C | I–VI (+E) | Risk adjustment |
| indication\_urgency | enum | C | elective, urgent, emergency | Outcome adjustment |
| reoperation | enum | C | primary, revision\_same\_level, revision\_adjacent, return\_to\_theatre | Revision rates |
| reoperation\_of | id | C if not primary | op\_id of the index operation | Links revision to index case |
| primary\_surgeon | enum | C | Group member list | Volume, learning curve |
| assistant\_1, assistant\_2 | enum | C / E | Group member, resident, other | Training data |
| anaesthetist | enum | C | Anaesthetist list | Team record, audit |
| op\_category | enum | C | Mapped from APYweb «Είδος» (op\_type) list | Case mix |
| admission\_code | text | E | Clinic's Κωδ. Εισαγωγής, as in APYweb | Cross-reference with clinic paperwork |
| trainee\_role | enum | E | none, observed, assisted, performed\_supervised | Training logbook |
| anaesthesia | enum | C | general, sedation, regional, local | Technique comparison |
| position | enum | C | supine, prone, lateral, park\_bench, sitting, other | Complication analysis |
| skin\_incision\_time / skin\_closure\_time | time | C | HH:MM | Operating time (computed) |
| blood\_loss\_ml | int | C | Estimated, mL | Resource use |
| transfusion\_units | int | C | 0 = none |  |
| neuromonitoring | multi | C | none, MEP, SSEP, EMG, D-wave, cortical\_mapping, subcortical\_mapping | Technique comparison |
| antibiotic\_prophylaxis | enum | E | standard, extended, none | Infection studies |
| drain | bool | C |  |  |
| durotomy\_incidental | bool | C | Spine only | Common complication, tracked separately |
| op\_note | text | E | Free text, not analysed |  |

**Procedure (child of Operation, one row per procedure performed)**

The database fields behind the Procedure facets (see Classification model and Procedure facets for the allowed values).

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| procedure\_row\_id | id | A |  |  |
| op\_id | id | C |  |  |
| procedure | enum | C | Procedure list (Controlled vocabularies) | Volume, outcomes per procedure |
| is\_main | bool | C | Exactly one per operation | Primary grouping for analysis |
| diagnosis\_id | id | C | The diagnosis this row treats | Links procedure to indication |
| domain | enum | A | Derived from procedure | Case mix |
| action | enum | A | Derived from procedure; overridable | Cross-procedure grouping |
| region | enum | A (spine) / C | Spine: derived from levels; other domains: chosen | Location |
| level\_kind | enum | A | segment, vertebra, root, range; set by the procedure | How the levels are read |
| level\_from, level\_to | enum | C if spine | Vertebra codes C0–Co; segments counted from them | Level-specific outcomes |
| levels\_list | multi | C if not contiguous | e.g. L3-L4 and L5-S1 | Skip levels |
| location | multi | C if cranial / peripheral / functional | Cranial location, nerve + site, or target list | Location |
| lesion\_side | enum | C | left, right, bilateral, midline, n/a | Side |
| approach\_type | enum | C | open, mini\_open, tubular\_MIS, percutaneous, endoscopic\_uniportal, endoscopic\_biportal, endoscopic\_endonasal, stereotactic\_frame, stereotactic\_frameless, keyhole | Technique comparison |
| approach\_route | enum | C if spine fusion or cranial | Route list (Procedure facets #8) | Technique comparison |
| approach\_side | enum | C if unilateral approach | left, right, bilateral, midline, n/a | ULBD and similar |
| compartment | enum | C if tumour or intradural | Compartment list |  |
| spinal\_column | enum | E | anterior, posterior, lateral, circumferential\_360 |  |
| extent | enum | C if resection | GTR, NTR, STR, partial, biopsy\_only | Tumour outcome |
| technology | multi | C | Technology list; `none` allowed | Technique comparison |
| episode | enum | C | primary, revision\_same\_level, revision\_adjacent\_level, staged, return\_to\_theatre | Revision rates |
| intent | enum | E | therapeutic, diagnostic, palliative, symptomatic |  |
| product | enum | C if intradiscal\_hydrogel; E otherwise | DiscoGel, GelStix; cage or implant product if stated | Device comparison |
| coding\_status | enum | A | uncoded, suggested, confirmed (+ coded\_by, coded\_at) | Only confirmed rows enter statistics |

Construct (`cage_only`, `screws_only`, `cage_and_screws`, `none`) and the review flag are stored on the Operation, derived from these rows (see Controlled vocabularies).

## Import from APYweb and coding

Operations already logged in APYweb are imported, not retyped. APYweb is a billing app: its date, age, clinic and team are usable as they are, but `pathology` and `operation` are free text and must be coded in NeuroLog. APYweb itself is never changed.

**What comes across from APYweb `operations`:**

| APYweb column | NeuroLog field | Handling |
| --- | --- | --- |
| id | source\_id (with source = `apyweb`) | Re-import updates the same record, never duplicates it |
| op\_date | op\_date | As is |
| patient\_name, patient\_age | Patient match (see Patient) | Name alone never merges two patients |
| clinic | hospital | Name mapped once to the hospital list |
| surgeon, surgeon2, anesthesiologist | primary\_surgeon, assistant\_1, anaesthetist | Name mapped once to the member list; renamed names keep their mapping |
| op\_type | op\_category | Mapped from the APYweb list |
| pathology | diagnosis\_main | Coded (below) |
| operation | procedure, approach, level\_from/level\_to | Coded (below) |
| admission\_code | admission\_code | Kept for cross-reference with clinic paperwork |
| amounts, payments, invoices | — | Not imported: billing stays in APYweb |

**Provenance fields on every imported record:**

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| source | enum | A | manual, apyweb | Where the record came from |
| source\_id | text | A | APYweb `operations.id` | Idempotent re-import, audit trail |
| imported\_at | datetime | A |  | Audit |
| source\_text\_pathology, source\_text\_operation | text | A | APYweb text, kept verbatim | Shows what the code was derived from |

**Coding status, next to every coded field** (`diagnosis_main`, `procedure`, `approach`, `level_from/level_to`):

| Status | Meaning | Used in statistics? |
| --- | --- | --- |
| uncoded | Free text only | No |
| suggested | Proposed by matching the APYweb text against the controlled lists | No |
| confirmed | A surgeon accepted or chose the code; `coded_by` and `coded_at` recorded | Yes |

A suggested code is shown as a suggestion and never counted as a fact. The ledger can list every operation from day one; statistics include only confirmed codes and say how many cases were left out.

## Diagnosis and baseline status

Recorded per operation, before surgery. Baseline scores are what every outcome is measured against: a case with no baseline score cannot enter an outcome study.

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| diagnosis\_main | enum | C | Diagnosis list (mapped to ICD-10) | Case mix, indication |
| diagnosis\_other | multi | E | Same list | Secondary indications |
| histology | enum | C if tumour | WHO CNS 5 type + grade; filled when pathology returns | Tumour outcome |
| molecular\_markers | multi | E | IDH, 1p/19q, MGMT, H3K27M, other | Glioma studies |
| symptom\_duration | enum | C | <6 w, 6 w–3 m, 3–12 m, >12 m | Predictor of outcome |
| neuro\_deficit | enum | C | none, sensory, motor\_mild (MRC 4), motor\_severe (MRC ≤3), sphincter | Indication severity |
| prior\_surgery\_same\_region | bool | C |  | Revision vs primary |
| baseline\_kps | int | C if cranial | 0–100 | Tumour outcome |
| baseline\_mrs | int | C if cranial/vascular | 0–6 | Functional outcome |
| baseline\_gcs | int | C if trauma/emergency | 3–15 | Trauma outcome |
| baseline\_vas\_back / baseline\_vas\_leg | int | C if lumbar | 0–10 | Pain outcome |
| baseline\_vas\_neck / baseline\_vas\_arm | int | C if cervical | 0–10 | Pain outcome |
| baseline\_odi | int | C if lumbar | 0–100 % | Disability outcome |
| baseline\_ndi | int | C if cervical | 0–100 % | Disability outcome |
| baseline\_mjoa | int | C if myelopathy | 0–18 | Myelopathy outcome |
| baseline\_eq5d | dec | E | Index value | Quality of life, cost studies |
| baseline\_seizures\_month | dec | C if epilepsy | Seizures per month, average of the last 3 months | Epilepsy surgery |

Patients fill VAS/ODI/NDI/EQ-5D themselves (validated Greek versions); the surgeon fills KPS, mRS, GCS, mJOA. Record who filled it (`score_source`: patient, surgeon, nurse).

## Implants and technology

One row per implant type used in an operation, plus the guidance technology. Record the category and maker, not lot numbers: traceability belongs to the hospital record, not the research registry.

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| implant\_category | enum | C | pedicle\_screw, lateral\_mass\_screw, cage\_PLIF/TLIF, cage\_ALIF/LLIF, cage\_ACDF, cervical\_disc, plate, vertebral\_augmentation\_cement, shunt\_valve, external\_drain, neurostimulator\_lead, IPG, intrathecal\_pump, cranioplasty\_implant, other | Implant studies |
| implant\_count | int | C |  | Construct size |
| manufacturer | enum | E | Group list | Device comparisons |
| valve\_type | enum | C if shunt | fixed, programmable, anti-siphon | Shunt failure studies |
| valve\_setting | text | E | Opening pressure |  |
| guidance | multi | C | none, fluoroscopy, navigation\_CT\_intraop, navigation\_preop, robot, ultrasound, exoscope, microscope, endoscope | Technique comparison |
| screws\_robot\_placed | int | C if robot |  | Robot accuracy |
| screw\_breach | enum | E | Gertzbein-Robbins grade per screw, from post-op CT (A–E) | Accuracy, navigation vs freehand |
| fluoroscopy\_time\_s | int | E | Seconds | Radiation exposure |
| adjuncts | multi | E | 5-ALA, fluorescein, ICG, intraop\_MRI, intraop\_US | Resection studies |

## Complications

One row per complication, linked to the operation it follows, up to 90 days (30 days for readmission). "No complication" is recorded explicitly at each follow-up, so silence never counts as a clean outcome.

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| complication\_id | id | A |  |  |
| op\_id | id | C |  |  |
| complication\_type | enum | C | Complication list (Controlled vocabularies) | Rates by type |
| timing | enum | C | intraoperative, <48 h, 48 h–30 d, 31–90 d | Early vs late |
| onset\_date | date | C |  |  |
| clavien\_dindo | enum | C | I, II, IIIa, IIIb, IVa, IVb, V | Severity-weighted rates |
| treatment | enum | C | none, medication, bedside\_procedure, reoperation, ICU, other |  |
| reoperation\_op\_id | id | C if reoperation | Links to the new Operation | Return-to-theatre rate |
| outcome\_at\_90d | enum | C | resolved, improving, permanent\_deficit, died | Permanent harm rate |
| related\_to\_surgery | enum | C | definite, probable, unrelated | Separates surgical from medical events |
| detected\_by | enum | E | ward, clinic, readmission, patient\_report | Completeness check |

The complication list must be fixed before the first entry. A list that grows by free text mid-study breaks comparisons between early and late cases.

## Outcomes and follow-up

One row per follow-up visit, at fixed windows from `op_date`: 6 weeks, 3 months, 12 months (24 months for fusion and tumour studies). The same score fields as baseline are repeated, so change = follow-up minus baseline.

| Field | Type | Req | Values / units | Answers |
| --- | --- | --- | --- | --- |
| followup\_id | id | A |  |  |
| op\_id | id | C |  |  |
| window | enum | C | 6w, 3m, 12m, 24m, unscheduled | Time-point analysis |
| visit\_date | date | C | Must fall inside the window, else `unscheduled` |  |
| status | enum | C | seen, phone, lost, died, declined | Loss-to-follow-up rate |
| scores | — | C | Same fields as baseline (VAS, ODI, NDI, mJOA, KPS, mRS, EQ-5D) | Outcome change |
| neuro\_status | enum | C | improved, unchanged, worse, new\_deficit | Neurological outcome |
| patient\_satisfaction | enum | E | 1–5 Likert, or "would have the operation again" yes/no | Patient-reported |
| return\_to\_work | enum | E | yes, partial, no, not\_applicable | Socio-economic outcome |
| fusion\_status | enum | C if fusion, 12m | fused, partial, pseudarthrosis, not\_assessed (CT-based) | Fusion rate |
| tumour\_status | enum | C if tumour | stable, progression (RANO), recurrence, not\_assessed | Progression-free survival |
| seizure\_outcome | enum | C if epilepsy | Engel I–IV | Epilepsy surgery |
| date\_of\_death | date | C if died |  | Survival analysis |

The 12-month follow-up is the one most often missed. Plan who chases it (secretary list, phone questionnaire) before enrolment starts, or the outcome data will be unusable: more than 20 % loss to follow-up makes most outcome papers hard to publish.

## Controlled vocabularies

The procedure and diagnosis lists below were built from the group's real caseload (APYweb operation and pathology texts, read-only, aggregated); the counts are kept in the private working copy. They are registry codes, separate from the erweb page names; each code names at most one erweb page, which the last column gives, so a statistic can still link to the matching patient page. Each value has a stable code plus a Greek and an English label; a value is retired, never deleted or renamed.

**Codes say what was done, never how the surgeon got there.** «Διαδερμική δισκεκτομή» is `discectomy` with approach `tubular_MIS`, not a separate code; an open microdiscectomy is the same `discectomy` with approach `open`. In this practice «Διαδερμική» means tubular MIS through a tubular retractor, never endoscopic, for discectomy, laminectomy/decompression, foraminotomy and interbody fusion. Procedures that are percutaneous by nature (vertebral augmentation, intradiscal, injections, RF, trigeminal, biopsy) stay \`percutaneous\`. One fusion case is different: in «Διαδερμική σπονδυλοδεσία» the pedicle screws are placed percutaneously, so \`pedicle\_screw\_fixation\` gets approach \`percutaneous\`. In «Διαδερμική δισκεκτομή Ο4/5, TLIF» with «σπονδυλοδεσία», the discectomy and cage rows are tubular\_MIS and the screw row is percutaneous. The import reads it that way.

### Procedure list

| Domain | Code | Greek label | Notes / qualifiers | erweb page |
| --- | --- | --- | --- | --- |
| spinal | discectomy | Δισκεκτομή | lumbar, cervical (posterior) or thoracic, from the level | microdiscectomy, thoracic-discectomy |
| spinal | laminectomy\_decompression | Πεταλεκτομή / αποσυμπίεση | qualifier: laminotomy, laminectomy, unilateral-for-bilateral (ULBD) | minimally-invasive-laminectomy |
| spinal | foraminotomy | Τρηματεκτομή | root level | — |
| spinal | interbody\_fusion | Μεσοσπονδύλια σπονδυλοδεσία | cage only, never implies screws; route: transforaminal (TLIF), posterior (PLIF), lateral (XLIF/LLIF), oblique (OLIF), anterior (ALIF); cage product if stated | posterior-lumbar-fusion, lateral-lumbar-interbody-fusion |
| spinal | pedicle\_screw\_fixation | Διαυχένια κοχλίωση | only when «σπονδυλοδεσία» is written; «Διαδερμική» = percutaneous; own levels; navigation (O-arm) if stated | thoracolumbar-stabilization |
| spinal | acdf | ACDF | levels; cage or cage + plate | anterior-cervical-discectomy-fusion |
| spinal | cervical\_corpectomy | Πρόσθια αυχενική σωματεκτομή | vertebra | — |
| spinal | cervical\_disc\_arthroplasty | Τεχνητός δίσκος |  | cervical-disc-arthroplasty |
| spinal | posterior\_cervical\_fusion | Οπίσθια αυχενική σπονδυλοδεσία |  | posterior-cervical-fusion |
| spinal | vertebral\_augmentation | Κυφοπλαστική | qualifier: kyphoplasty, vertebroplasty; vertebrae | kyphoplasty |
| spinal | intradiscal\_hydrogel | Δισκοπλαστική | product: DiscoGel, GelStix | — |
| spinal | intradiscal\_thermal | IDET |  | — |
| spinal | spinal\_biopsy | Βιοψία σπονδύλου |  | spinal-biopsy |
| spinal | vertebral\_tumour\_resection | Αφαίρεση όγκου σπονδύλου |  | — |
| spinal | vertebral\_tumour\_ablation | RF αποδομή όγκου σπονδύλου | OsteoCool | vertebral-tumor-radiofrequency-ablation |
| spinal | intradural\_tumour\_resection | Αφαίρεση ενδομηνιγγικού όγκου | compartment: extramedullary, intramedullary | spinal-cord-tumor-resection |
| spinal | transverse\_process\_resection | Αφαίρεση εγκάρσιας απόφυσης | Bertolotti | — |
| spinal | hardware\_removal | Αφαίρεση υλικών | which implant, levels | — |
| spinal | implant\_revision | Αναθεώρηση υλικών | cage, screw, rod | — |
| spinal | dural\_repair | Μηνιγγοπλαστική | primary or for CSF leak | — |
| spinal | posterior\_fossa\_decompression | Αποσυμπίεση οπίσθιας κρανιακής βόθρου | Chiari | posterior-fossa-decompression |
| spinal | syrinx\_shunt | Shunt συριγγομυελίας |  | syringo-subarachnoid-shunt |
| pain | spinal\_injection | Έγχυση / block | target: transforaminal, epidural, facet, medial branch, SI joint | spinal-injections |
| pain | rf\_neurotomy | RF θερμοπηξία νεύρου | target: medial branch, SI joint, peripheral nerve | facet-joint-radiofrequency-neurotomy, radiofrequency-ablation |
| pain | trigeminal\_percutaneous | Θερμοπηξία γασσερίου | qualifier: RF thermocoagulation, balloon, glycerol; branch | percutaneous-trigeminal-procedures |
| pain | spinal\_cord\_stimulation | Νευροδιέγερση νωτιαίου μυελού | trial or implant | spinal-cord-stimulation |
| pain | deep\_brain\_stimulation | DBS | target | deep-brain-stimulation |
| pain | intrathecal\_pump | Αντλία | baclofen, morphine | programmable-pump-implantation |
| pain | sacral\_or\_vagus\_stimulation | Ιερή / πνευμονογαστρική διέγερση |  | sacral-root-stimulation, vagus-nerve-stimulation |
| pain | neurostimulator\_hardware | Αντικατάσταση / αφαίρεση διεγέρτη | IPG change, lead revision, removal | — |
| pain | microvascular\_decompression | Μικροαγγειακή αποσυμπίεση | nerve V, VII, IX | microvascular-decompression |
| cranial | burr\_hole\_evacuation | Κρανιοανάτρηση / παροχέτευση | chronic SDH, hygroma | burr-hole-hematoma-evacuation |
| cranial | craniotomy\_haematoma | Κρανιοτομία αιματώματος | epidural, subdural, intracerebral | craniotomy-hematoma-evacuation |
| cranial | craniotomy\_tumour | Κρανιοτομία όγκου | awake yes/no; extent of resection | craniotomy-tumor-resection, awake-craniotomy |
| cranial | stereotactic\_biopsy | Στερεοτακτική βιοψία |  | stereotactic-brain-biopsy |
| cranial | decompressive\_craniectomy | Αποσυμπιεστική κρανιεκτομή |  | — |
| cranial | cranioplasty | Κρανιοπλαστική |  | cranioplasty |
| cranial | aneurysm\_clipping, avm\_excision, skull\_base\_endonasal, cpa\_surgery, epilepsy\_surgery, radiosurgery |  | kept for completeness | matching erweb pages |
| CSF | shunt\_insertion | Τοποθέτηση βαλβίδας | VP, VA, LP; valve type | hydrocephalus-shunting |
| CSF | shunt\_revision | Revision βαλβίδας | which component | — |
| CSF | external\_ventricular\_drain | EVD |  | — |
| CSF | ommaya\_reservoir | Ommaya |  | — |
| CSF | etv | Ενδοσκοπική κοιλιοστομία |  | endoscopic-third-ventriculostomy |
| peripheral | nerve\_decompression | Απελευθέρωση νεύρου | nerve + site (carpal tunnel, cubital tunnel, …) | peripheral-nerve-decompression |
| peripheral | nerve\_repair | Αποκατάσταση νεύρου |  | peripheral-nerve-repair |
| other | soft\_tissue\_excision | Αφαίρεση βλάβης μαλακών μορίων | lipoma etc. | — |
| other | abscess\_drainage | Παροχέτευση αποστήματος | site | — |
| other | other\_procedure | Άλλη | note required; reviewed quarterly | — |

**Cage and screws are recorded separately, exactly as written.** PLIF or TLIF means an interbody cage only; it never implies screws. Pedicle screws are recorded only when the text says «(Διαδερμική) σπονδυλοδεσία». Each operation is marked with one construct value, derived from its procedure rows:

| Construct | Procedure rows | Written as |
| --- | --- | --- |
| cage\_only | `interbody_fusion` | «Διαδερμική δισκεκτομή Ο3/4, TLIF» |
| screws\_only | `pedicle_screw_fixation` | «Διαδερμική σπονδυλοδεσία Ο4/5» |
| cage\_and\_screws | `interbody_fusion` + `pedicle_screw_fixation` | «Διαδερμική σπονδυλοδεσία Ο4/5/Ι1, PLIF Ο5/Ι1» |
| none | neither | everything else |

«Αφαίρεση κοχλιών» is `hardware_removal`, not screw fixation, and does not set a construct. The levels of the cage and of the screws are stored separately because they often differ (screws L3–L5, cage at L4-L5 only).

**Flagged for review on import:** operations whose «Είδος» is «Διαδερμικό PLIF/TLIF» or «Διαδερμική σπονδυλοδεσία» but whose text names no cage or no screws (e.g. «Διαδερμική δισκεκτομή Ο5/Ι1» under «Διαδερμικό PLIF»). They are imported with construct `none` and `review = construct`, and a surgeon sets the construct from the patient file. The text is never overridden by the «Είδος» automatically.

### Diagnosis list

Region comes from the level, so one code covers all spinal regions: «ΚΜΔ Ο5/Ι1» and «ΚΜΔ Α5/6» are both `disc_herniation`.

| Class | Code | Greek label | Qualifiers |
| --- | --- | --- | --- |
| degenerative | disc\_herniation | Κήλη μεσοσπονδυλίου δίσκου (ΚΜΔ) | zone, type; recurrent yes/no |
| degenerative | spinal\_stenosis | Στένωση | central, lateral recess, foraminal |
| degenerative | spondylolisthesis | Σπονδυλολίσθηση | degenerative, isthmic; Meyerding |
| degenerative | segmental\_instability | Αστάθεια |  |
| degenerative | degenerative\_disc\_disease | Εκφυλιστική δισκοπάθεια |  |
| degenerative | myelopathy | Μυελοπάθεια | mJOA; cause in a second diagnosis |
| degenerative | facet\_joint\_syndrome | Σύνδρομο αρθρώσεων facet |  |
| developmental | bertolotti\_syndrome, spondylolysis, tarlov\_cyst |  |  |
| pain | radicular\_pain\_unspecified | Ισχιαλγία / βραχιοναλγία | root; use only when no structural cause is named |
| pain | axial\_pain\_unspecified | Οσφυαλγία / ραχιαλγία |  |
| pain | trigeminal\_neuralgia | Νευραλγία τριδύμου | classical, secondary; branch |
| pain | glossopharyngeal\_neuralgia | Νευραλγία γλωσσοφαρυγγικού |  |
| pain | intercostal\_neuralgia | Μεσοπλεύριος νευραλγία |  |
| traumatic / bone | vertebral\_fracture | Κάταγμα σπονδύλου | cause: osteoporotic, traumatic, pathological (required); AO Spine |
| traumatic | traumatic\_brain\_injury | Κρανιοεγκεφαλική κάκωση | GCS |
| vascular / haemorrhage | subdural\_collection | Υποσκληρίδιο αιμάτωμα / ύγρωμα | acute, subacute, chronic, hygroma |
| vascular / haemorrhage | intracerebral\_haematoma, epidural\_haematoma, subarachnoid\_haemorrhage |  |  |
| vascular | aneurysm, avm, davf, cavernoma |  | as in Diagnosis facets |
| neoplastic | brain\_tumour | Όγκος εγκεφάλου (εξεργασία) | status: provisional until histology, then WHO type + grade (glioma, meningioma, metastasis, lymphoma…) |
| neoplastic | spinal\_tumour | Όγκος σπονδυλικής στήλης | compartment; primary or metastatic; histology (plasmacytoma, lymphoma…) |
| neoplastic | leptomeningeal\_carcinomatosis | Καρκινωματώδης μηνιγγίτιδα |  |
| neoplastic | radiation\_necrosis | Μετακτινική νέκρωση |  |
| CSF | hydrocephalus | Υδροκέφαλος | normal pressure, obstructive, communicating |
| CSF | shunt\_malfunction | Δυσλειτουργία βαλβίδας | obstruction, overdrainage, infection |
| CSF | chiari, syringomyelia, iih |  |  |
| infectious | spinal\_infection | Απόστημα / σπονδυλοδισκίτιδα | epidural abscess, discitis |
| entrapment | nerve\_entrapment | Παγίδευση νεύρου | nerve + site (ΣΚΣ = carpal tunnel); severity |
| functional | movement\_disorder, epilepsy, spasticity, hemifacial\_spasm |  |  |
| iatrogenic | implant\_failure | Αποτυχία υλικών | loosening, cage migration, breakage; links to index operation |
| iatrogenic | csf\_leak | Διαφυγή ΕΝΥ | links to index operation |
| iatrogenic | postoperative\_haemorrhage | Μετεγχειρητική αιμορραγία | links to index operation |
| iatrogenic | stimulator\_malfunction | Δυσλειτουργία διεγέρτη | DBS, SCS |
| soft tissue | soft\_tissue\_lesion | Λίπωμα κ.λπ. |  |
| other | other\_diagnosis | Άλλη | note required |

An `iatrogenic` diagnosis carries `index_op_id` (the earlier NeuroLog operation it complicates), so reoperation and complication rates can be counted from either side.

### Other lists

| List | Values |
| --- | --- |
| Intradiscal hydrogel product | DiscoGel, GelStix |
| Complication | Wound infection superficial / deep, CSF leak, incidental durotomy, haematoma (epidural / subdural / intracerebral), new motor deficit, new sensory deficit, C5 palsy, dysphagia, recurrent laryngeal palsy, hardware malposition, hardware failure, pseudarthrosis, adjacent-segment disease, recurrent disc herniation, shunt malfunction, shunt infection, seizure, hydrocephalus, DVT / PE, pneumonia, UTI, delirium, death (graded by Clavien-Dindo) |
| Hospital | From APYweb's clinic list, mapped once |
| Surgeon, anaesthetist | Group members from APYweb's doctor list, plus `resident` and `external` |

**Level entry.** Levels are typed the way the group already writes them, `Ο4/5`, `Α5/6/7`, `Ο4/5,Ο5/Ι1`, and shown back immediately in standard form (L4-L5; C5-C6, C6-C7; L4-L5 and L5-S1). Greek and Latin letters are both accepted. Impossible or reversed levels (Ο7, Θ13, 8/7, Ο4/6 without a transitional-vertebra flag) are refused at entry.
## Classification model

Every operation and every diagnosis is classified as **one core concept plus independent facets**, each facet chosen from a closed list. Level, side and area are facets, never part of the core code.

- **Core concept** = what was done (procedure) or what the patient has (diagnosis): e.g. `discectomy`, `disc_herniation`. About 60 procedures and 80 diagnoses in total.
- **Facets** = where, which side, how, with what: e.g. region `lumbar`, segment `L4-L5`, side `left`, approach `tubular_MIS`.

Why not one combined code (e.g. "L4-L5 left microdiscectomy"): discectomy × 24 segments × 3 sides alone would need about 70 codes, and the list would still miss combinations. Separate facets keep each list short and make every question searchable on its own: all L4-L5 operations, all left-sided ones, all endoscopic ones, or any combination.

Rules:

- Every facet is a closed list with an `other` value plus a note, so nothing is forced into a wrong value; `other` entries are reviewed every quarter and either added to the list or recoded.
- A facet that does not apply is stored as `n/a`, never left blank (blank = not yet entered).
- One operation can hold several procedures (decompression + fusion). Each procedure row carries its own facets, so "L3-L5 decompression with L4-L5 fusion" is stored exactly.
- Diagnosis and procedure are linked: each procedure row names the diagnosis it treats.

## Procedure facets

One row per procedure performed. Facets 1–7 are required for every procedure; 8–15 when they apply.

| # | Facet | Values (closed) | Example |
| --- | --- | --- | --- |
| 1 | **Domain** | cranial, spinal, peripheral\_nerve, functional\_pain (neuromodulation and pain procedures at any site), CSF (shunts, ETV, drains) | spinal |
| 2 | **Action** (what was done to the tissue) | decompression, discectomy, excision\_resection, biopsy, evacuation\_drainage, fusion, fixation\_without\_fusion, arthroplasty, vertebral\_augmentation, osteotomy\_deformity\_correction, CSF\_diversion, fenestration, clipping, vascular\_excision, ablation, lesioning, stimulation\_implant, pump\_implant, injection\_block, neurolysis\_release, nerve\_repair\_graft, nerve\_transfer, dural\_repair, cranioplasty\_reconstruction, device\_revision, device\_removal, hardware\_removal, exploration, other | discectomy |
| 3 | **Procedure** (core concept) | Closed procedure list (Controlled vocabularies); each procedure has one default Action | discectomy |
| 4 | **Region** | Anatomical region list (below), by domain | lumbar |
| 5 | **Location / level** | Spine: segment or vertebra range; cranial: location list; peripheral: nerve + site (below) | L4-L5 |
| 6 | **Lesion side** | left, right, bilateral, midline, n/a | left |
| 7 | **Approach type** | open, mini\_open, tubular\_MIS, endoscopic\_uniportal, endoscopic\_biportal, endoscopic\_endonasal, percutaneous, stereotactic\_frame, stereotactic\_frameless, keyhole | tubular\_MIS |
| 8 | **Approach route / corridor** | Spine: anterior\_cervical, anterior\_retroperitoneal, lateral\_transpsoas, oblique\_antepsoas, posterior\_midline, paraspinal\_Wiltse, transforaminal, interlaminar, transpedicular, costotransversectomy, transthoracic, transoral; cranial: pterional, supraorbital\_eyebrow, frontal, bifrontal, temporal, parietal, occipital, interhemispheric, transcallosal, transcortical, transsylvian, retrosigmoid, suboccipital\_midline, far\_lateral, telovelar, supracerebellar\_infratentorial, transsphenoidal, expanded\_endonasal, burr\_hole, twist\_drill; peripheral: named site | posterior\_midline |
| 9 | **Approach side** | left, right, bilateral, midline, n/a; differs from lesion side, e.g. unilateral approach for bilateral decompression | left |
| 10 | **Compartment** | Spine: extradural, intradural\_extramedullary, intramedullary; cranial: extra\_axial, intra\_axial, intraventricular, epidural, subdural, skull\_bone | extradural |
| 11 | **Spinal column** | anterior, posterior, lateral, circumferential\_360 | posterior |
| 12 | **Extent** | Resection: GTR, NTR, STR, partial, biopsy\_only; decompression: number of levels; fusion: number of segments | — |
| 13 | **Technology** (multi) | microscope, exoscope, endoscope, navigation, robot, intraop\_CT, intraop\_MRI, intraop\_US, neuromonitoring, awake\_mapping, 5-ALA, fluorescein, ICG, stereotactic\_frame | microscope, neuromonitoring |
| 14 | **Episode** | primary, revision\_same\_level, revision\_adjacent\_level, staged (n of m), return\_to\_theatre | primary |
| 15 | **Intent** | therapeutic, diagnostic, palliative, symptomatic | therapeutic |

Urgency, team, implants and times stay on the Operation record (above), not on each procedure row.

## Diagnosis facets

One main diagnosis per operation (plus optional secondary ones), each classified the same way. Location facets reuse the procedure lists exactly, so diagnosis and procedure can be searched with the same terms.

| # | Facet | Values (closed) | Example |
| --- | --- | --- | --- |
| 1 | **Domain** | cranial, spinal, peripheral\_nerve, functional\_pain, CSF | spinal |
| 2 | **Pathology class** | degenerative, neoplastic\_primary, neoplastic\_metastatic, vascular, traumatic, infectious, congenital\_developmental, inflammatory, CSF\_disorder, functional (movement, epilepsy, spasticity), pain, entrapment, iatrogenic\_postoperative, other | degenerative |
| 3 | **Diagnosis** (core concept) | Closed diagnosis list, each mapped to one ICD-10 code | disc\_herniation |
| 4 | **Region** | Same list as procedures | lumbar |
| 5 | **Location / level** | Same rules as procedures | L4-L5 |
| 6 | **Side** | left, right, bilateral, midline, n/a | left |
| 7 | **Compartment** | Same list as procedures | extradural |
| 8 | **Clinical syndrome** (multi) | radiculopathy (+ root, e.g. L5), myelopathy, cauda\_equina, neurogenic\_claudication, axial\_pain, instability, deformity, motor\_deficit, raised\_ICP, seizures, cranial\_neuropathy (+ nerve), cognitive, asymptomatic\_incidental | radiculopathy L5 |
| 9 | **Acuity** | acute (<3 w), subacute (3 w–3 m), chronic (>3 m); for haematomas: acute, subacute, chronic, acute\_on\_chronic | chronic |
| 10 | **Recurrence** | first, recurrent, residual | first |

**Qualifiers per diagnosis** (only the ones that change treatment or outcome; each a closed list):

| Diagnosis | Qualifier | Values |
| --- | --- | --- |
| Disc herniation | Zone | central, paracentral\_subarticular, foraminal, extraforaminal |
|  | Type | protrusion, extrusion, sequestration, calcified |
| Stenosis | Type | central, lateral\_recess, foraminal, combined |
| Spondylolisthesis | Type | degenerative, isthmic, dysplastic, traumatic, iatrogenic |
|  | Grade | Meyerding I–V |
| Spinal fracture | Classification | AO Spine (type A/B/C + subtype), osteoporotic yes/no |
|  | Neurology | ASIA A–E |
| Brain tumour | Histology + grade | WHO CNS 5 type and grade (from pathology) |
|  | Markers | IDH, 1p/19q, MGMT, H3K27M |
| Metastasis | Primary site | lung, breast, kidney, prostate, melanoma, colorectal, other, unknown |
|  | Number | single, 2–4, ≥5 |
| Spinal metastasis | Scores | SINS (0–18), ESCC grade (Bilsky 0–3) |
| Meningioma | Simpson grade | I–V (set after surgery) |
| Aneurysm | Status | unruptured, ruptured (+ Hunt-Hess, WFNS, Fisher) |
|  | Vessel | ACom, PCom, MCA, ICA, basilar, PICA, other |
|  | Size | <7 mm, 7–12, 13–24, ≥25 |
| AVM | Grade | Spetzler-Martin I–V |
| Traumatic brain injury | Severity | GCS 13–15, 9–12, 3–8 |
| Hydrocephalus | Type | obstructive, communicating, normal\_pressure, shunt\_malfunction |
| Chiari | Type | I, 1.5, II; syrinx yes/no |
| Epilepsy | Type | focal (+ lobe), generalised; lesional yes/no |
| Trigeminal neuralgia | Type | classical (vascular contact), secondary (MS, tumour), idiopathic; branch V1/V2/V3 |
| Entrapment neuropathy | Severity | mild, moderate, severe (by nerve conduction) |

## Anatomical value lists

**Spine**

| Region | Levels it covers |
| --- | --- |
| craniocervical | C0–C2 |
| cervical | C2–C7 |
| cervicothoracic | C7–T2 |
| thoracic | T1–T12 |
| thoracolumbar | T11–L2 |
| lumbar | L1–L5 |
| lumbosacral | L5–S1 |
| sacral\_coccygeal | S1–Co |
| sacroiliac\_pelvis | SI joint, pelvis |

How a spinal level is written depends on what the procedure acts on, so the same field always means the same thing:

| Acts on | Written as | Stored as | Example |
| --- | --- | --- | --- |
| Disc or motion segment (discectomy, fusion, arthroplasty, decompression) | Segment range | `level_from`, `level_to` (vertebra codes); segments counted from them | L4-L5; L3-L5 = 2 segments |
| Vertebral body (kyphoplasty, corpectomy, biopsy) | Vertebra list | one row per vertebra | L1, L2 |
| Nerve root | Root | root code | L5 (left) |
| Lamina / posterior elements (laminectomy) | Vertebra range | `level_from`, `level_to` | C3–C6 |

Vertebra codes: C0 (occiput), C1–C7, T1–T12, L1–L5 (L6 allowed for a transitional vertebra), S1–S5, Co. A segment is always written cranial-to-caudal (L4-L5, never L5-L4). Transitional anatomy is a yes/no flag on the patient, so level counts stay comparable.

**Cranial**

| Region | Locations |
| --- | --- |
| supratentorial\_hemisphere | frontal, temporal, parietal, occipital, insular, central (motor/sensory strip) |
| deep\_midline | basal\_ganglia, thalamus, corpus\_callosum, pineal, hypothalamus |
| ventricular | lateral, third, fourth, aqueduct |
| sellar\_parasellar | sella, suprasellar, cavernous\_sinus |
| skull\_base | anterior\_fossa, middle\_fossa, petroclival, foramen\_magnum, CPA (cerebellopontine angle) |
| posterior\_fossa | cerebellar\_hemisphere, vermis, brainstem (midbrain, pons, medulla) |
| convexity\_extra\_axial | convexity, parasagittal\_falx, tentorium, venous\_sinus |
| skull\_scalp | calvarium, scalp, orbit |
| vascular\_territory | ICA, ACA, MCA, PCA, vertebrobasilar (for aneurysm/AVM/stroke) |

Location is multi-select (a tumour can be frontal + insular). Side applies to every hemispheric location.

**Peripheral nerve**

| Nerve | Sites |
| --- | --- |
| brachial\_plexus | roots, trunks, cords, terminal |
| median | wrist\_carpal\_tunnel, forearm, elbow |
| ulnar | elbow\_cubital\_tunnel, wrist\_Guyon |
| radial | arm\_spiral\_groove, elbow\_posterior\_interosseous |
| sciatic | buttock\_piriformis, thigh |
| common\_peroneal | fibular\_head |
| tibial | tarsal\_tunnel |
| lateral\_femoral\_cutaneous | inguinal\_ligament |
| other | named in note |

**Functional / pain targets** (when Domain = functional\_pain): STN, GPi, VIM, ALIC/NAcc, anterior thalamus, motor cortex, dorsal column (cervical/thoracic level), DRG (level), sacral root S3, vagus nerve, occipital nerve, intrathecal (catheter tip level), medial branch (level), facet joint (level), sacroiliac joint, trigeminal ganglion.

## Worked examples and seeding

**How four real-shaped operations are classified:**

| Operation as written | Procedure rows (procedure · region · level · side · approach · route) | Diagnosis |
| --- | --- | --- |
| Διαδερμική δισκεκτομή Ο4/5 αρ | discectomy · lumbar · L4-L5 · left · tubular\_MIS · interlaminar | disc\_herniation · L4-L5 · left · paracentral · extrusion · radiculopathy L5 |
| L3-L5 decompression with L4-L5 TLIF | (1) laminectomy\_decompression · lumbar · L3-L5 · bilateral · open · posterior\_midline; (2) interbody\_fusion · lumbar · L4-L5 · left · tubular\_MIS · transforaminal | spondylolisthesis · L4-L5 · degenerative · Meyerding I + stenosis · L3-L5 · central |
| Right pterional clipping of MCA aneurysm | aneurysm\_clipping · vascular\_territory · MCA · right · open · pterional | cerebral\_aneurysm · MCA · right · unruptured · 7–12 mm |
| Left carpal tunnel release | nerve\_decompression · median · wrist\_carpal\_tunnel · left · open | nerve\_entrapment · median · left · moderate |

**Rules that keep coding consistent:**

- For the spine, the region is derived from the levels (L4-L5 → lumbar; L5-S1 → lumbosacral; a range crossing a junction → the junction region). It is never chosen by hand.
- Side is the patient's side, never the image's.
- A procedure stopped before its goal is still coded as attempted, with Extent = partial and a note.

**Seeding from APYweb**

The APYweb repository holds no operation data: the texts live only in the live `apydb` database. Running these three read-only queries there produces the lists to seed from, each with how often it occurs:

```sql
SELECT TRIM(operation) AS text, COUNT(*) AS n FROM operations GROUP BY text ORDER BY n DESC;
SELECT TRIM(pathology) AS text, COUNT(*) AS n FROM operations GROUP BY text ORDER BY n DESC;
SELECT TRIM(op_type)   AS text, COUNT(*) AS n FROM operations GROUP BY text ORDER BY n DESC;
```

From those lists:

1. Map the most frequent texts first; about 30 texts usually cover most operations in a single-specialty practice (to be confirmed on the real counts).
2. Every distinct text becomes one row in a mapping table (APYweb text → procedure + facets, or diagnosis + facets), confirmed by a surgeon once. Later imports reuse the confirmed mapping.
3. A text that fits no list value is the signal to add a value, not to stretch an existing one.
4. The output is only operation and pathology texts with counts, no patient names, so it can be shared for mapping.

## Governance and open decisions

The ledger and research have different legal footing, so they are governed separately. The ledger can start once its four tasks are done; research exports wait for their own.

**The ledger (identified data, part of patient care)**

- [ ] Legal basis: GDPR Art. 6(1)(c)/(e) and 9(2)(h) (provision of health care); confirm with the hospitals' DPOs who the data controller is
- [ ] Access rules: who can see whose cases, who can export; every view and export logged
- [ ] Retention period, and what happens to the data if a member leaves the group
- [ ] Backups that include the patient table (the only link between NeuroLog IDs and patients)

**Audit and research (pseudonymised exports only)**

Research never reads the ledger. A study gets an export with:

- a **new random ID per study** (same format as Identifiers, prefix `S`), so two studies' datasets cannot be joined to each other; the link from study ID to NeuroLog ID stays inside NeuroLog;
- no name, birth date (age at surgery instead), AMKA, admission code or free text;
- dates shifted or reduced to month and year where the study allows;
- only patients with `research_consent = yes`, unless the ethics approval waives consent;
- only fields with `confirmed` coding.

* [ ] Legal basis for research: GDPR Art. 9(2)(j), under Greek Law 4624/2019 Art. 30
* [ ] Scientific/ethics committee approval at each participating hospital
* [ ] Data protection impact assessment (DPIA)
* [ ] Patient information sheet and research consent form, Greek and English
* [ ] Authorship and data-use rules: who may propose a study on the shared data, and how co-authorship is decided
* [ ] Final procedure, diagnosis and complication lists signed off by all members
* [ ] Follow-up chasing: who collects the 12-month scores

Open questions for the group:

- Import all APYweb operations, or only from a start date? Older APYweb rows have the least detail and the most free text to code.
- Who confirms suggested codes: the operating surgeon only, or any member?
- Which validated Greek translations of ODI, NDI and EQ-5D will be used? EQ-5D needs a licence from the EuroQol group for registry use.
- «Διαδερμική αποσυμπίεση» : imported as `laminectomy_decompression` (tubular\_MIS) unless the group says otherwise.
- APYweb operations whose text is only «Αφαίρεση», «Αποσυμπίεση» or «Κρανιοτομία»: imported as `uncoded` and coded by hand from the patient file.
