# Sierra Leone admin areas: seed data

`admin-areas.csv` is the default administrative area list for the **Sierra Leone country profile** (`AGRI-INPUTS.md` §16.1). It's used for:
- the Farmer List Template drop-downs;
- place matching during Excel import;
- report breakdowns.

## Source

| | |
|---|---|
| Dataset | Sierra Leone – Subnational Administrative Boundaries (COD-AB), version 02 |
| Source | Government of Sierra Leone, contributed by OCHA West and Central Africa, published by OCHA and HDX |
| Link | https://data.humdata.org/dataset/cod-ab-sle (file `sle_admin_boundaries.xlsx`) |
| Valid on | 15/12/2023 (boundaries created 16/08/2017; dataset reviewed by OCHA 30/10/2025) |
| Licence | Creative Commons Attribution for Intergovernmental Organisations (CC BY-IGO). Keep this attribution wherever the list is shown or exported. |
| Downloaded | 06/11/2026 |

## Contents

| Level | Label | Count | P-code format |
|---|---|---|---|
| 1 | Region | 5 | `SL01` |
| 2 | District | 16 | `SL0101` |
| 3 | Chiefdom (Western Area: *Area*) | 167 | `SL010101` |
| 4 | Section | 1,316 | `SL01010101` |

Columns:

| Column | Meaning |
|---|---|
| `level`, `level_label` | The admin level and its label |
| `pcode` | The official place code. **Use this as the stable key, not the name.** |
| `name` | The name as used in the system |
| `source_name` | The original source spelling, filled in only when `name` was corrected |
| `parent_pcode` | The p-code of the area one level up |
| `region`, `district`, `chiefdom` | The ancestor names, for convenience |
| `center_lat`, `center_lon` | The area's centre point, used for map defaults and the "household location inside its area" check |
| `review_flag` | Items to check (see below) |

Villages aren't in the official dataset. They're created from beneficiary lists and field registration, under their section.

## Known issues (to check during the pilot)

1. **Corrected spelling.** District `SL0206` is spelled "Fabala" in the source. It's corrected to **Falaba** (`review_flag = name_corrected`), and the original is kept in `source_name`.
2. **Names that may be cut off** (`review_flag = possibly_truncated`, 67 rows). The source seems to have cut chiefdom names at 15 characters and section names at 18. The names are kept exactly as in the source until someone confirms the full name. Suggested full chiefdom names, **to be confirmed locally before they're changed**:

   | District | Source name | Suggested full name (unconfirmed) |
   |---|---|---|
   | Bombali | Gbanti Kamarank | Gbanti Kamaranka |
   | Bombali | Gbendembu Ngowa | Gbendembu Ngowahun |
   | Bombali | Magbaimba Ndorh | Magbaimba Ndorhahun |
   | Koinadugu | Wara Wara Bafod | Wara Wara Bafodia |
   | Koinadugu | Wara Wara Yagal | Wara Wara Yagala |
   | Falaba | Dembelia - Sink | Dembelia Sinkunia |
   | Falaba | Folosaba Dembel | Folosaba Dembelia |
   | Pujehun | Galliness Perri | Gallinas Perri |
   | Pujehun | Pejeh(Futa peje | Pejeh (Futa Pejeh) |
   | Karene | Sanda Magbolont | Sanda Magbolontor |
   | Port Loko | Bureh Kasseh Ma | Bureh Kasseh Maconteh |

3. **Boundary date.** The boundaries were created in 2017. Check whether any chiefdoms or sections have changed since then, against the current list from Statistics Sierra Leone or the Ministry of Local Government. Add or rename them in the console. **Never reuse a p-code** for a different area.
4. **Western Area.** Level 3 under Western Area Rural and Western Area Urban is a rural or urban area (e.g. "Koya Rural", "East I"), not a chiefdom, so its label is **Area**.

## Loading and changing the list

- Load or refresh it with `php artisan pod:seed-admin-areas SL`.
  - Areas are matched by p-code: new ones are added and changed ones updated.
  - Areas that are in the database but not in the file are **kept** and reported, because beneficiaries may point at them.
  - Add `--dry-run` to see the changes first, or `--file=path.csv` to load a corrected copy in this format.
- To correct a name: edit `name` in a copy of this file (keep the p-code), and load it with `--file`. Keep the original in `source_name` if it isn't already there. A console screen for corrections, with an audit log, is planned with the console UI.
- **Never reuse a p-code** for a different area.
- Corrections in this format can be shared with Statistics Sierra Leone or OCHA, so the official list can be fixed at the source.
