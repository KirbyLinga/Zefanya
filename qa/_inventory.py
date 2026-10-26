import os,re,collections
root='mobile/test'
by=collections.defaultdict(list)
for fn in sorted(os.listdir(root)):
    if not fn.endswith('.dart'): continue
    lines=open(os.path.join(root,fn),encoding='utf-8',errors='replace').read().splitlines()
    for i,l in enumerate(lines,1):
        m=re.search(r"(?:testWidgets|test)\('([^']*)'",l)
        if m:
            by[fn].append((i,m.group(1)))
with open('qa/test_inventory.txt','w',encoding='utf-8') as f:
    for fn,items in by.items():
        f.write(f'### {fn} ({len(items)})\n')
        for i,n in items:
            f.write(f'{i}: {n}\n')
print(sum(len(v) for v in by.values()),'tests in',len(by),'files')
