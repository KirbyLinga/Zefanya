import json, io
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
ns={}; exec(open('qa/_classify_tmp.py',encoding='utf-8-sig').read().split('for s in [')[0],ns)
for status,label in [('Needs device/API','needs'),('Fail','fail')]:
    with io.open(f'qa/_rows_{label}.txt','w',encoding='utf-8') as f:
        for r in rows:
            if ns['classify'](r['Test Case ID'])==status:
                f.write(f"=== {r['Test Case ID']} | {r['Workbook']} | {r['Module']} | {r['Test Scenario']}\nEXPECTED: {r['Expected Result']}\nACTUAL: {r['Actual Result']}\nREMARKS: {r['Remarks']}\n\n")
print('done')
