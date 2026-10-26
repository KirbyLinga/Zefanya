import re
from collections import defaultdict
text = open('qa/phase4_full_test.txt', encoding='utf-8', errors='replace').read()
pat = re.compile(r'^\d\d:\d\d \+\d+(?: -\d+)?: .*?test/([^:]+\.dart): (.+)$', re.M)
ids = defaultdict(list)
for path, name in pat.findall(text):
    for cid in re.findall(r'(?<![A-Z])(?:TC-(?:BUY|RID|COU)|BUY|RID|COU)-[A-Z]+-\d{3}\b', name):
        ids[cid].append((path, name.strip()))
with open('qa/test_id_map.txt', 'w', encoding='utf-8') as f:
    for cid in sorted(ids):
        f.write(f'{cid} {len(ids[cid])}\n')
        seen = set()
        for path, name in ids[cid]:
            if name not in seen:
                seen.add(name)
                f.write(f'   {path} | {name}\n')
    f.write(f'TOTAL IDS {len(ids)}\n')
print('ids', len(ids))
