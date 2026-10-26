import re
text=open('qa/phase4_full_test.txt',encoding='utf-8',errors='replace').read()
# Reporter lines: timestamp + count: path: name; path may be on previous line due wrapping is not actual newline? In file each event line usually one.
pat=re.compile(r'^\d\d:\d\d \+\d+(?: -\d+)?: (.+?\.dart): (.+)$',re.M)
seen=set(); out=[]
for path,name in pat.findall(text):
    key=(path,name.strip())
    if key not in seen:
        seen.add(key); out.append(key)
with open('qa/all_test_names_phase4.txt','w',encoding='utf-8') as f:
    for path,name in out:
        f.write(f'{path}\t{name}\n')
print(len(out))
