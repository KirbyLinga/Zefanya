import json, collections
work={}
with open('qa/_final_work.tsv',encoding='utf-8') as f:
    for line in f:
        p=line.rstrip('\n').split('\t')
        if len(p)<6: continue
        work[p[0]]=dict(wb=p[1],group=p[2],old=p[3],tri=p[4],scenario=p[5])
buyer_ac_needs={'TC-BUY-001','TC-BUY-027','TC-BUY-029','TC-BUY-035','TC-BUY-036','TC-BUY-040'}
buyer_ac_fail={'TC-BUY-015','TC-BUY-016'}
buyer_ac_na={'TC-BUY-023','TC-BUY-028'}
buyer_mod_fail={'BUY-TRK-002','BUY-CAN-001','BUY-CAN-003','BUY-CAN-006','BUY-RATE-001','BUY-CART-008','BUY-PDP-006','BUY-CHK-003','BUY-CHAT-003','BUY-CHAT-004','BUY-ADDR-008'}
buyer_mod_needs={'BUY-ACCT-001','BUY-ACCT-010','BUY-TRK-005','BUY-TRK-006','BUY-TRK-007','BUY-RET-001','BUY-RET-005','BUY-RET-006','BUY-RATE-006','BUY-RATE-007','BUY-CHAT-001','BUY-CHAT-006','BUY-CHAT-007','BUY-CHAT-008','BUY-ADDR-009','BUY-ADDR-010','BUY-SRCH-005','BUY-SRCH-010','BUY-SRCH-013','BUY-PDP-009','BUY-PDP-010','BUY-WISH-003','BUY-CART-006','BUY-VOU-012','BUY-CHK-004','BUY-CHK-008','BUY-CHK-011','BUY-CHK-012','BUY-CHK-013','BUY-PAY-002','BUY-PAY-003','BUY-PAY-004','BUY-PAY-005','BUY-PAY-006','BUY-PAY-007','BUY-PAY-008','BUY-PAY-009'}
buyer_mod_na={'BUY-CHK-002','BUY-CART-010','BUY-CART-011'}
rider_mod_fail={'RID-AVAIL-001','RID-AVAIL-002','RID-AVAIL-004','RID-JOB-001','RID-JOB-002','RID-JOB-003','RID-JOB-004','RID-JOB-006','RID-PICK-002','RID-PICK-006','RID-DLV-008','RID-FAIL-005','RID-PROF-008'}
rider_mod_needs={'RID-PROF-007','RID-AVAIL-005','RID-AVAIL-006','RID-AVAIL-007','RID-JOB-005','RID-JOB-007','RID-JOB-008','RID-JOB-009','RID-PICK-005','RID-PICK-007','RID-DLV-007','RID-DLV-009','RID-DLV-010','RID-DLV-011','RID-EARN-006'}
rider_ac_pass={'TC-RID-029','TC-RID-046','TC-RID-047'}
# assert set membership
def classify(cid):
    wb=work[cid]['wb']
    if wb=='Buyer - Account Creation':
        return 'Fail' if cid in buyer_ac_fail else 'Needs device/API' if cid in buyer_ac_needs else 'N/A' if cid in buyer_ac_na else 'Pass'
    if wb=='Buyer - Modules':
        return 'Fail' if cid in buyer_mod_fail else 'Needs device/API' if cid in buyer_mod_needs else 'N/A' if cid in buyer_mod_na else 'Pass'
    if wb=='Rider - Account Creation':
        return 'Pass' if cid in rider_ac_pass else 'N/A'
    if wb=='Rider - Modules':
        return 'Fail' if cid in rider_mod_fail else 'Needs device/API' if cid in rider_mod_needs else 'Pass'
    return 'N/A'
for s in [buyer_ac_needs,buyer_ac_fail,buyer_ac_na,buyer_mod_fail,buyer_mod_needs,buyer_mod_na,rider_mod_fail,rider_mod_needs,rider_ac_pass]:
    for cid in s:
        assert cid in work, cid
# no overlap sanity
for a,b,name in [(buyer_ac_needs,buyer_ac_fail,'ac'),(buyer_mod_fail,buyer_mod_needs,'mod'),(rider_mod_fail,rider_mod_needs,'rider')]:
    assert not a & b, (name,a&b)
counts=collections.Counter((work[c]['wb'],classify(c)) for c in work)
for wb in ['Buyer - Account Creation','Buyer - Modules','Rider - Account Creation','Courier - Account Creation','Rider - Modules','Courier - Modules']:
    print(wb, {k:counts[(wb,k)] for k in ['Pass','Needs device/API','Fail','N/A']})
print('TOTAL',collections.Counter(classify(c) for c in work))
print('\nPASS BY WORKBOOK')
for wb in ['Buyer - Account Creation','Buyer - Modules','Rider - Account Creation','Rider - Modules']:
    ids=[c for c in work if work[c]['wb']==wb and classify(c)=='Pass']
    print('\n##',wb,len(ids))
    print(' '.join(ids))
print('\nNEEDS')
for wb in ['Buyer - Account Creation','Buyer - Modules','Rider - Modules']:
    print('\n##',wb)
    print(' '.join(c for c in work if work[c]['wb']==wb and classify(c)=='Needs device/API'))
print('\nFAIL')
for wb in ['Buyer - Account Creation','Buyer - Modules','Rider - Modules']:
    print('\n##',wb)
    print(' '.join(c for c in work if work[c]['wb']==wb and classify(c)=='Fail'))
