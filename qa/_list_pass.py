import json, collections, importlib.util
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
ns={}; exec(open('qa/_classify_tmp.py',encoding='utf-8-sig').read().split('for s in [')[0],ns)
for r in rows:
    if ns['classify'](r['Test Case ID'])=='Pass':
        print(f"{r['Test Case ID']}\t{r['Workbook']}\t{r['Module']}\t{r['Test Scenario']}")
