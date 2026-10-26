import csv, collections
rows=list(csv.DictReader(open('qa/triage.csv',encoding='utf-8-sig')))
print(len(rows))
print(collections.Counter(r['Group'] for r in rows))
for g in 'ABCDE':
    ids=[r['Case ID'] for r in rows if r['Group']==g]
    print(g, len(ids), ' '.join(ids[:20]))
with open('qa/triage_groups.txt','w',encoding='utf-8') as f:
    for g in 'ABCDE':
        f.write(f'== {g} ==\n')
        for r in rows:
            if r['Group']==g:
                f.write(f"{r['Case ID']}\t{r['Original']}\t{r['Projected']}\t{r['Scenario']}\t{r['Resolution']}\n")
