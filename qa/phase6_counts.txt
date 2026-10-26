# Phase 6 workbook counts

Source: `qa/Mobile_App_Combined_Test_Results.xlsx` (377 cases).
Generated from `qa/final_results.csv` by `qa/build_final_results.py`.

| Workbook | Pass | Needs device/API | Fail | N/A | Total |
|---|---:|---:|---:|---:|---:|
| Buyer - Account Creation | 32 | 6 | 2 | 2 | 42 |
| Buyer - Modules | 80 | 37 | 11 | 3 | 131 |
| Rider - Account Creation | 3 | 0 | 0 | 44 | 47 |
| Courier - Account Creation | 0 | 0 | 0 | 52 | 52 |
| Rider - Modules | 25 | 15 | 13 | 0 | 53 |
| Courier - Modules | 0 | 0 | 0 | 52 | 52 |
| **Total** | **140** | **58** | **26** | **153** | **377** |

Passes require a passing test or a source line showing the behaviour; the evidence is in `qa/final_results.csv`.
`Needs device/API` rows are scripted in `qa/MANUAL_TESTS.md`.
`Fail` rows record a missing behaviour, not an unrun test.
