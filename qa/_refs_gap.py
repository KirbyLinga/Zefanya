import json
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
ns={}; exec(open('qa/_classify_tmp.py',encoding='utf-8-sig').read().split('for s in [')[0],ns)
refs={}
for line in open('qa/source_case_refs.txt',encoding='utf-8'):
    p=line.rstrip('\n').split('\t')
    if len(p)>=2: refs.setdefault(p[0],[]).append(p[1])
passes=[r['Test Case ID'] for r in rows if ns['classify'](r['Test Case ID'])=='Pass']
print('NO REFS',len([c for c in passes if c not in refs]))
for c in passes:
    if c not in refs: print(c)
print('\nREFS NOT PASS')
for c in refs:
    if c not in passes: print(c,refs[c])
