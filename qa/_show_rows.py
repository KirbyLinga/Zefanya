import json,sys
rows=json.load(open('qa/workbook_rows.json',encoding='utf-8'))
ids=set(sys.argv[1:])
for r in rows:
    if r['Test Case ID'] in ids:
        print('===',r['Test Case ID'],r['Test Scenario'])
        print('EXPECTED:',r['Expected Result'])
        print('ACTUAL:',r['Actual Result'])
        print('STATUS:',r['Status'])
