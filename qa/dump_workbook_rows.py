import json, openpyxl
wb = openpyxl.load_workbook('qa/Mobile_App_Combined_Test_Results.xlsx', data_only=True)
ws = wb['All Results']
headers = [ws.cell(1, c).value for c in range(1, ws.max_column + 1)]
rows = []
for r in range(2, ws.max_row + 1):
    row = {headers[c - 1]: ws.cell(r, c).value for c in range(1, ws.max_column + 1)}
    if row.get('Test Case ID'):
        rows.append(row)
with open('qa/workbook_rows.json', 'w', encoding='utf-8') as f:
    json.dump(rows, f, ensure_ascii=False, indent=1)
print(len(rows))
