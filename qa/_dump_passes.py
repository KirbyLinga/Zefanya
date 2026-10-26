import json, collections, io, re
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
src=open('qa/_classify_tmp.py',encoding='utf-8-sig').read()
ns={}
exec(src.split("for s in [")[0],ns)
classify=ns['classify']
for wb,label in [('Buyer - Account Creation','buyer_ac'),('Buyer - Modules','buyer_mod'),('Rider - Account Creation','rider_ac'),('Rider - Modules','rider_mod')]:
    with io.open(f'qa/_pass_{label}.txt','w',encoding='utf-8') as f:
        for r in rows:
            cid=r['Test Case ID']
            if r['Workbook']==wb and classify(cid)=='Pass':
                f.write(f"=== {cid} | {r['Sheet']} | {r['Test Scenario']}\nEXPECTED: {r['Expected Result']}\nACTUAL: {r['Actual Result']}\n\n")
print('done')
