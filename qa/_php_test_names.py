import re
for fn in ['BuyerRegistrationApiTest.php','BuyerOtpApiTest.php','BuyerLoginApiTest.php','AccountSecurityApiTest.php']:
    p='web/tests/Feature/Api/'+fn
    try: lines=open(p,encoding='utf-8',errors='replace').read().splitlines()
    except FileNotFoundError: 
        print('MISSING',p); continue
    print('###',fn)
    for i,l in enumerate(lines,1):
        if 'public function test_' in l:
            print(f'{i}: {l.strip()}')
