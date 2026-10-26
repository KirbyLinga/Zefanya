import os,re
files=['registration_flow_test.dart','validators_test.dart','otp_flow_test.dart','auth_flow_test.dart','login_gate_test.dart','registration_payload_test.dart','live_auth_test.dart']
for fn in files:
    p='mobile/test/'+fn
    if not os.path.exists(p): continue
    print('###',fn)
    lines=open(p,encoding='utf-8',errors='replace').read().splitlines()
    for i,l in enumerate(lines,1):
        if re.search(r"\b(group|test|testWidgets)\(",l):
            print(f'{i}: {l.strip()[:180]}')
