import json, csv, re
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
tri={r['Case ID']:r for r in csv.DictReader(open('qa/triage.csv',encoding='utf-8-sig'))}
# tests: collect all file:line strings and test names
test_lines=open('qa/all_test_names.txt',encoding='utf-8',errors='replace').read().splitlines()
test_text='\n'.join(test_lines)
def has_id(cid):
    return re.search(r'(?<![A-Z])'+re.escape(cid)+r'(?![0-9])', test_text) is not None
out=[]
for r in rows:
    cid=r['Test Case ID']
    t=tri.get(cid,{})
    out.append((r['Workbook'],r['Sheet'],cid,r['Status'],t.get('Group','-'),r['Test Scenario'],t.get('Resolution','')))
out.sort()
with open('qa/_final_work.tsv','w',encoding='utf-8') as f:
    for wb,sheet,cid,st,g,scen,res in out:
        f.write(f'{cid}\t{wb}\t{sheet}\t{st}\t{g}\t{scen}\t{res}\n')
print(len(out))
from collections import Counter
print(Counter(r[0] for r in out))
print(Counter(r[1] for r in out))
