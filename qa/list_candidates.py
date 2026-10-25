import csv

with open('qa/triage.csv', encoding='utf-8-sig', newline='') as f:
    rows = list(csv.DictReader(f))

out = []
for r in rows:
    if r['Group'] not in ('B-M', 'B-L', 'A'):
        continue
    out.append(
        f"{r['Group']}\t{r['Case ID']}\t{r['Module']}\t{r['Scenario']}\n"
        f"  RES: {r['Resolution']}\n"
        f"  ACT: {r['Actual']}\n"
    )

with open('qa/phase3_candidates.txt', 'w', encoding='utf-8', newline='\n') as f:
    f.write('\n'.join(out))
