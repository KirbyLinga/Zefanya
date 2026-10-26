import json
src=open('qa/source_case_refs.txt',encoding='utf-8-sig').read().splitlines()
refs=set(l.split('\t')[0] for l in src if '\t' in l)
print(len(refs))
# pass sets from script
ns={}; exec(open('qa/_classify_tmp.py',encoding='utf-8-sig').read().split('for s in [')[0],ns)
pass_ids=[c for c in ns['work'] if ns['classify'](c)=='Pass']
print('pass',len(pass_ids),'with refs',len(refs & set(pass_ids)),'without',len(set(pass_ids)-refs))
print('\n'.join(sorted(set(pass_ids)-refs)))
