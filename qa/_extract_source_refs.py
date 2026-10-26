import os, re, json
root='mobile/test'
groups=[]
for dirpath,_,files in os.walk(root):
    for fn in files:
        if not fn.endswith('.dart'): continue
        p=os.path.join(dirpath,fn)
        lines=open(p,encoding='utf-8',errors='replace').read().splitlines()
        for i,line in enumerate(lines,1):
            for m in re.finditer(r"group\('([^']*)'", line):
                gid=re.match(r'((?:TC-(?:BUY|RID|COU)|BUY|RID|COU)-[A-Z]+-\d{3})', m.group(1))
                if gid:
                    groups.append((gid.group(1),p.replace('\\','/'),i,m.group(1)))
            for m in re.finditer(r"test(?:Widgets)?\('([^']*)'", line):
                cid=re.match(r'((?:TC-(?:BUY|RID|COU)|BUY|RID|COU)-[A-Z]+-\d{3})', m.group(1))
                if cid:
                    groups.append((cid.group(1),p.replace('\\','/'),i,m.group(1)))
with open('qa/source_case_refs.txt','w',encoding='utf-8') as f:
    for g in sorted(set(groups)):
        f.write(f'{g[0]}\t{g[1]}:{g[2]}\t{g[3]}\n')
from collections import Counter
print(len(set(g[0] for g in groups)), 'ids')
for cid,c in sorted(Counter(g[0] for g in groups).items()):
    print(cid,c)
