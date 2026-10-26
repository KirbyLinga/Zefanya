import json
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
with open('qa/workbook_rows.txt','w',encoding='utf-8') as f:
    for r in rows:
        f.write(f"{r['Test Case ID']}\t{r['Workbook']}\t{r['Sheet']}\t{r['Status']}\t{r['Test Scenario']}\t{r['Expected Result']}\n")
print('written', len(rows))
