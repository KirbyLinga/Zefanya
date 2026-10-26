import csv, io
rows=list(csv.DictReader(open('qa/triage.csv',encoding='utf-8-sig')))
with io.open('qa/_triage_dump.txt','w',encoding='utf-8') as f:
    for r in rows:
        f.write(f"{r['Case ID']}\t{r['Group']}\t{r['Original']}\t{r['Scenario']}\t{r['Resolution']}\n")
print(len(rows))
