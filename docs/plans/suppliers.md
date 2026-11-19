# Suppliers: a company and product for every technology used (approved and built 2026-11-19)

Asked by the surgeon on 2026-11-19: record **which company supplied each technology** on an operation (neuromonitoring,
navigation, endoscope…) **with its product**, using **one list of all suppliers**, not only implant companies. The
data will be imported from APYweb (M5), so the design follows what APYweb already records.

## What APYweb has

APYweb (`scripts/migrate_technologies.sql`, CLAUDE.md "Technologies × companies matrix") keeps four tables:

| Table | Holds |
| --- | --- |
| `technologies` | a free name list, managed by the user |
| `companies` | a free name list, **one list for every supplier** |
| `company_technologies` | the catalogue: which company supplies which technology (a tick grid) |
| `operation_technologies` | what one operation used: (operation, technology, company), nothing else; one technology may come from two companies on one operation |

There is **no product**, no size and no level. APYweb's technology list mixes four kinds of thing, and NeuroLog keeps them
apart:

1. **Equipment and services used during the operation** (neuromonitoring, navigation, endoscopy, RF) → the new
   *supply* rows below.
2. **Implants** (cages, cervical discs, kyphoplasty, MIS fusion sets) → NeuroLog's existing implant rows, which
   already carry company and product, plus sizes.
3. **Consumables** (dural patches and sealants, drains) → *supply* rows (open question S1).
4. **Products that already have a NeuroLog field** (intradiscal hydrogel → the procedure row's `product`) → that field, with the
   company added as a supply row.

The private working copy maps each APYweb name to one of these (`docs/apyweb-technologies.md` there).

## Design

**One supplier list.** The existing `companies` table becomes the list of all suppliers. The admin page is renamed
"Companies" and gains **what the company supplies** (multi, from `field.company.supplies`), so each picker offers
only the companies that fit. This works like APYweb's catalogue, but as tags on the company. A company with nothing ticked
appears everywhere. Existing implant companies get `implants`.

**Supply rows** (new table `supplies`): one row per technology, company and product used on an operation.

| Field | Type | Req | Values |
| --- | --- | --- | --- |
| operation_ref | ref | C | the operation |
| supply_kind | enum | C | `field.supply.kind` (below); retired, never renamed |
| company_ref | ref | C | `companies`, filtered by what the company supplies |
| product | text | E | system or product name as on the label; suggested from earlier rows for the same company and kind |
| procedure_ref | ref | E | the procedure row it was used for, when an operation has several |
| source | enum | — | `entered` or `imported` (from APYweb, with its source key) |

`field.supply.kind` (draft): `neuromonitoring`, `navigation`, `robot`, `endoscope`, `microscope`, `exoscope`,
`intraop_CT`, `intraop_US`, `intraop_MRI`, `RF_generator`, `ultrasonic_aspirator`, `dural_graft`, `dural_sealant`,
`haemostat`, `drain`, `intradiscal_product`, `other` (with a description). `field.company.supplies` is the same list plus
`implants`.

**At entry.** When a procedure row's `technology` includes something that has a supply kind (neuromonitoring,
navigation, robot, endoscope, microscope, exoscope, intra-op CT/US/MRI), the operation page offers a supplier line for
it: company chip, then product. Filling it is optional (E): the technology facet stays required, the supplier does not.
Other kinds (sealant, graft, drain…) are added from an "+ supply" button. The quick line gets `@προμ` (supplier) like
`@υλ` for implants; an `@υλ` tag that names a drain or sealant becomes a supply row.

**Finding.** Search filters: supply kind, company (also matching implant rows when asked "anything from company X"),
product contains. Lists show the supplier next to the technology.

**Import (M5).** Each APYweb `operation_technologies` pair becomes:

- a supply row (kind from the mapping, company matched on `name_match`, product empty), or
- an implant row with category and company but no sizes, marked incomplete for review, or
- for intradiscal products, the procedure row's `product` plus a supply row for the company.

APYweb companies are created in the supplier list with their tags taken from the APYweb catalogue. A name the mapping does
not know goes to the review queue, never to `other` silently.

## Answers (surgeon, 2026-11-19)

- **S1** Consumables (dural graft/sealant, haemostats, drains) are supply rows: yes.
- **S2** Kyphoplasty → implant row `vertebral_augmentation_cement` with company and product; MIS fusion → the company of
  the screw and rod implant rows; RF → supply `RF_generator`: yes.
- **S3** The supplier is optional; a technology without its supplier is listed as missing: yes.
- **S4** **All drains and catheters are one category**: supply kind `drain` (wound, ventricular, lumbar). The implant
  category `external_drain` is retired; the quick line reads EVD, drain and catheter words as `drain`.
- AnsoluteGel joins the intradiscal hydrogel products.
- One company may supply several product lines of one kind (e.g. two PLIF cage systems) in several sizes: implant
  rows already record each group separately; product suggestions are per company and category.

## Built

`vocab/fields.yaml` (`field.supply.kind`, `field.company.supplies`), `web/classes/yaml/supplies.yaml`,
`companies.supplies`, `web/supplies.php` (pure validation, kinds a technology implies; `tests/static/supplies_test.php`),
supplier rows on the operation form (offered per technology, companies filtered by what they supply) and page, the
Companies admin page, search filters `supply`, `supplier`, `sproduct`, the quick-line tag `@προμ`, `bin/check_web.php`.

## APYweb's supplier list (imported 2026-11-19)

`bin/import_apyweb_companies.php` reads APYweb's companies and its technology × company catalogue through
`../apy/db_config.php`, in a read-only session, and fills NeuroLog's Companies with what each one supplies. Each
APYweb technology name is read with general words (`nl_supply_tag_for_name()`); a name it cannot read is reported,
never guessed, and ticked by hand. It previews by default (`--apply` writes), matches companies on `apyweb_name`
then on the name, and adds ticks without removing any, so it can be re-run. The operation ticks
(`operation_technologies`) need the operations and come with M5.

## Out of scope

Prices, lot or serial numbers (decision kept 2026-11-18), the hospital's stock.
