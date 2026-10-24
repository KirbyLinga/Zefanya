"""Builds qa/triage.csv from the parsed workbook cases.

Group definitions:
  A    app defect fixed in mobile/lib (code + test proof)
  B-M  missing app feature, medium
  B-L  missing app feature, large
  C    test-plan mismatch / wrong surface / mock-mode contradiction
  D    needs Laravel API, server rule, payment gateway, or server re-check
  E    needs physical device, OS permission, real network, or seeded fixtures

The parsed cases live next to this repo in the agent temp directory; the
CSV is committed so the report remains readable without that file.
"""

import csv
import json
from collections import Counter
from pathlib import Path

CASES = Path(r"C:\Users\Lenovo\AppData\Local\Temp\opencode\cases.json")
OUT = Path(__file__).with_name("triage.csv")

cases = json.loads(CASES.read_text(encoding="utf-8"))
nonpass = {c["id"]: c for c in cases if c["status"] != "Pass"}

groups = {}


def add(group, ids):
    for case_id in ids.split():
        assert case_id in nonpass, case_id
        assert case_id not in groups, (case_id, groups[case_id], group)
        groups[case_id] = group


# --- Buyer Account Creation -------------------------------------------------
add("A", "TC-BUY-004 TC-BUY-006 TC-BUY-007 TC-BUY-018 TC-BUY-021 "
       "TC-BUY-038 TC-BUY-039")
add("C", "TC-BUY-023 TC-BUY-028")
add("D", "TC-BUY-001 TC-BUY-010 TC-BUY-011 TC-BUY-015 TC-BUY-016 "
       "TC-BUY-026 TC-BUY-027 TC-BUY-029 TC-BUY-030 TC-BUY-035 "
       "TC-BUY-036")
add("E", "TC-BUY-032 TC-BUY-033 TC-BUY-034 TC-BUY-040")

# --- Buyer Modules ----------------------------------------------------------
add("A", "BUY-ACCT-002 BUY-SRCH-012 BUY-PDP-004 BUY-PDP-007 "
       "BUY-CART-005 BUY-CHK-005 BUY-CHK-010")
add("B-M", "BUY-ACCT-003 BUY-ACCT-004 BUY-ACCT-005 BUY-ACCT-006 "
       "BUY-ACCT-007 BUY-ACCT-008 BUY-ACCT-009 BUY-ACCT-011 "
       "BUY-SRCH-004 BUY-SRCH-007 BUY-SRCH-011 "
       "BUY-PDP-001 BUY-PDP-002 BUY-PDP-003 BUY-PDP-005 BUY-PDP-010 "
       "BUY-WISH-004 BUY-CART-002 BUY-CART-007 "
       "BUY-VOU-001 BUY-VOU-003 BUY-VOU-005 BUY-VOU-011 "
       "BUY-RATE-001 BUY-RATE-004 BUY-RATE-005 BUY-RATE-007 "
       "BUY-CHAT-002 BUY-CHAT-005 BUY-TRK-003 BUY-TRK-004 "
       "BUY-CAN-001 BUY-CAN-002 BUY-CAN-003 BUY-CAN-004 BUY-CAN-005 "
       "BUY-CAN-006")
add("B-L", "BUY-ADDR-001 BUY-ADDR-002 BUY-ADDR-003 BUY-ADDR-004 "
       "BUY-ADDR-005 BUY-ADDR-006 BUY-ADDR-007 BUY-ADDR-008 "
       "BUY-ADDR-009 BUY-ADDR-010 BUY-ADDR-011 "
       "BUY-CHK-001 BUY-CHK-003 BUY-TRK-002 BUY-VOU-012 "
       "BUY-RET-001 BUY-RET-002 BUY-RET-003 BUY-RET-004 BUY-RET-005 "
       "BUY-RET-006 BUY-CHAT-003 BUY-CHAT-004 BUY-PDP-006 "
       "BUY-CART-008")
add("C", "BUY-CHK-002 BUY-CART-010 BUY-CART-011")
add("D", "BUY-ACCT-001 BUY-VOU-006 BUY-CHK-004 BUY-CHK-008 "
       "BUY-CHK-009 BUY-CHK-011 BUY-CHK-012 BUY-TRK-005 BUY-TRK-006 "
       "BUY-RATE-006 BUY-CHAT-001 BUY-CHAT-008 BUY-SRCH-005 "
       "BUY-SRCH-010 BUY-CART-006 BUY-PAY-002 BUY-PAY-003 BUY-PAY-004 "
       "BUY-PAY-005 BUY-PAY-006 BUY-PAY-007 BUY-PAY-008 BUY-PAY-009")
add("E", "BUY-ACCT-010 BUY-SRCH-001 BUY-SRCH-002 BUY-SRCH-003 "
       "BUY-SRCH-013 BUY-PDP-008 BUY-PDP-009 BUY-WISH-003 "
       "BUY-VOU-002 BUY-VOU-004 BUY-VOU-010 BUY-CHK-013 BUY-TRK-007 "
       "BUY-CHAT-006 BUY-CHAT-007")

# --- Rider Account Creation -------------------------------------------------
# The workbook tests Logistics' web Add Rider/activation flow; the Flutter
# app has no Rider role. Upload picker variations are on that same form.
add("C", "TC-RID-001 TC-RID-002 TC-RID-003 TC-RID-004 TC-RID-005 "
       "TC-RID-006 TC-RID-007 TC-RID-008 TC-RID-009 TC-RID-010 "
       "TC-RID-011 TC-RID-012 TC-RID-013 TC-RID-014 TC-RID-015 "
       "TC-RID-016 TC-RID-017 TC-RID-018 TC-RID-019 TC-RID-020 "
       "TC-RID-021 TC-RID-022 TC-RID-023 TC-RID-024 TC-RID-025 "
       "TC-RID-026 TC-RID-027 TC-RID-033 TC-RID-034 TC-RID-035 "
       "TC-RID-036 TC-RID-037 TC-RID-038 TC-RID-039 TC-RID-040 "
       "TC-RID-041 TC-RID-042 TC-RID-043 TC-RID-044 TC-RID-045 "
       "TC-RID-046 TC-RID-047")
add("D", "TC-RID-028 TC-RID-030 TC-RID-031 TC-RID-032")

# --- Courier Account Creation -----------------------------------------------
add("C", "TC-COU-001 TC-COU-002 TC-COU-003 TC-COU-004 TC-COU-005 "
       "TC-COU-006 TC-COU-007 TC-COU-008 TC-COU-009 TC-COU-010 "
       "TC-COU-011 TC-COU-012 TC-COU-013 TC-COU-014 TC-COU-015 "
       "TC-COU-016 TC-COU-017 TC-COU-018 TC-COU-019 TC-COU-020 "
       "TC-COU-021 TC-COU-022 TC-COU-023 TC-COU-024 TC-COU-025 "
       "TC-COU-026 TC-COU-027 TC-COU-028 TC-COU-029 TC-COU-030 "
       "TC-COU-031 TC-COU-032 TC-COU-034 TC-COU-038 TC-COU-039 "
       "TC-COU-040 TC-COU-041 TC-COU-042 TC-COU-043 TC-COU-044 "
       "TC-COU-045 TC-COU-046 TC-COU-047 TC-COU-048 TC-COU-049 "
       "TC-COU-050")
add("D", "TC-COU-033 TC-COU-035 TC-COU-036 TC-COU-037")

# --- Rider Modules ----------------------------------------------------------
add("A", "RID-DLV-008 RID-FAIL-002 RID-FAIL-003 RID-FAIL-004 "
       "RID-PICK-001 RID-DLV-010")
add("B-M", "RID-PROF-001 RID-PROF-002 RID-PROF-004 RID-PROF-005 "
       "RID-PROF-008 RID-JOB-001 RID-JOB-002 RID-JOB-003 RID-JOB-004 "
       "RID-JOB-006 RID-PICK-002 RID-PICK-003 RID-PICK-004 "
       "RID-DLV-004 RID-DLV-005 RID-DLV-006 RID-FAIL-001 RID-FAIL-005 "
       "RID-EARN-003 RID-EARN-004 RID-EARN-005")
add("B-L", "RID-AVAIL-001 RID-AVAIL-002 RID-AVAIL-003 RID-AVAIL-004")
add("D", "RID-PROF-003 RID-PROF-006 RID-PROF-007 RID-JOB-005 "
       "RID-JOB-007 RID-PICK-007 RID-DLV-007 RID-DLV-009 RID-EARN-006")
add("E", "RID-AVAIL-005 RID-AVAIL-006 RID-AVAIL-007 RID-JOB-008 "
       "RID-JOB-009 RID-PICK-005 RID-PICK-006 RID-DLV-001 RID-DLV-011 "
       "RID-EARN-001 RID-EARN-002")

# --- Courier Modules --------------------------------------------------------
# The workbook's Courier is a hub-to-hub line-haul role. The app's Courier
# role is the pickup/delivery role tested by the Rider workbook.
add("C", "COU-PROF-001 COU-PROF-002 COU-PROF-003 COU-PROF-004 "
       "COU-PROF-005 COU-PROF-008 COU-SHIFT-001 COU-SHIFT-002 "
       "COU-SHIFT-003 COU-SHIFT-004 COU-SHIFT-005 COU-TRIP-001 "
       "COU-TRIP-002 COU-TRIP-003 COU-TRIP-004 COU-LOAD-001 "
       "COU-LOAD-002 COU-LOAD-003 COU-LOAD-004 COU-LOAD-005 "
       "COU-LOAD-006 COU-LOAD-007 COU-TRN-001 COU-TRN-002 "
       "COU-TRN-003 COU-TRN-004 COU-TRN-005 COU-ARR-001 COU-ARR-002 "
       "COU-ARR-003 COU-ARR-004 COU-ARR-005 COU-PAY-001 COU-PAY-002 "
       "COU-PAY-003 COU-PAY-004")
add("D", "COU-PROF-006 COU-PROF-007 COU-TRIP-005 COU-TRIP-006 "
       "COU-ARR-006 COU-PAY-005")
add("E", "COU-SHIFT-006 COU-SHIFT-007 COU-TRIP-007 COU-TRIP-008 "
       "COU-LOAD-008 COU-LOAD-009 COU-TRN-006 COU-TRN-007 COU-ARR-007 "
       "COU-PAY-006")

# Cases implemented after the first triage pass. A rows are fixed by
# definition; these are the B rows that now have app code and targeted tests.
IMPLEMENTED = {
    "BUY-SRCH-004", "BUY-SRCH-007", "BUY-WISH-004",
    "BUY-CAN-002", "BUY-CAN-004", "BUY-CAN-005",
    "BUY-TRK-004", "BUY-RATE-004", "BUY-RATE-005", "BUY-RATE-007",
    "BUY-ADDR-001", "BUY-ADDR-002", "BUY-ADDR-003", "BUY-ADDR-004",
    "BUY-ADDR-005", "BUY-ADDR-006", "BUY-ADDR-007", "BUY-ADDR-011",
    "RID-PICK-003", "RID-PICK-004",
    "BUY-RET-002", "BUY-RET-003", "BUY-RET-004",
    "BUY-PDP-001", "BUY-PDP-002", "BUY-PDP-003", "BUY-CART-002",
}

# App half is done; the expected outcome also names a server/device action that
# this repo cannot perform. These stay out of the projected Pass count.
PARTIAL = {
    "BUY-ADDR-008",      # order/waybill snapshot and seller view are server-side
    "BUY-CAN-001",       # cancellation works; stock restore + seller notice are server-side
    "BUY-CAN-003",       # cancellation works; refund + voucher return are server-side
    "BUY-CAN-006",       # double tap is guarded; stock is not restored at all yet
    "BUY-RATE-001",      # photos work; variant + masked name still missing
    "BUY-TRK-003",       # receipt/rate works; seller earnings release is server-side
    "RID-FAIL-005",      # return/no-4th works; buyer/seller notifications are server-side
    "BUY-RET-001",       # request/window/status/block done; seller notification is server-side
    "BUY-RET-005",       # rejected reason + Escalate render; seller rejection is server-side
    "BUY-RET-006",       # ordered photos/preview/remove done; seller photo view is server-side
}

for case in cases:
    if case["status"] != "Pass":
        assert case["id"] in groups, case["id"]
assert IMPLEMENTED <= nonpass.keys()
assert PARTIAL <= nonpass.keys()
assert not (IMPLEMENTED & PARTIAL)


def root_cause(case):
    case_id = case["id"]
    workbook = case["workbook"]
    group = groups[case_id]
    if group == "A":
        if case_id.startswith("TC-BUY"):
            return ("Registration/OTP/back-guard validation gap "
                    "(shared validators, Terms, age, wall-clock countdown, dirty guard)")
        if case_id == "BUY-ACCT-002":
            return "Edit Profile did not use the shared name rules"
        if case_id == "BUY-SRCH-012":
            return "Search results had no pull-to-refresh"
        if case_id in ("BUY-PDP-004", "BUY-PDP-007"):
            return "PDP had no quantity cap; Buy Now carried the rest of the cart"
        if case_id == "BUY-CART-005":
            return "Cart had no 20-unit/stock cap"
        if case_id == "BUY-CHK-005":
            return "Checkout note limit did not match the stated 200"
        if case_id == "BUY-CHK-010":
            return "Checkout trusted a carried-over voucher discount"
        if case_id == "RID-DLV-008":
            return "Proof-of-delivery picker offered the gallery"
        if case_id.startswith("RID-FAIL"):
            return "Failed delivery had no required reason/attempt/return rule"
        if case_id == "RID-PICK-001":
            return "Pickup card lacked contact, tracking number and parcel count"
        if case_id == "RID-DLV-010":
            return "Call action was a snackbar; no dialer; buyer number absent"
    if group == "C":
        if workbook == "Rider Account Creation":
            return ("Test plan exercises Logistics' web Add Rider/activation flow; "
                    "the Flutter app has no Rider role")
        if workbook == "Courier Account Creation":
            return ("Test plan exercises Logistics' web Add Courier/activation flow; "
                    "the app has a public courier sign-up instead")
        if workbook == "Courier Modules":
            return ("Test plan assumes a hub-to-hub Courier role; the app's Courier "
                    "role is the pickup/delivery role covered by the Rider workbook")
        if case_id in ("TC-BUY-023", "TC-BUY-028"):
            return ("Test expects the live verified-account gate; mock mode deliberately "
                    "accepts any credentials and skips the OTP email")
        return ("Test expects per-store/zone shipping or a server cart; the app uses "
                "flat shipping and a local cart by design")
    if group.startswith("B"):
        if case_id in ("BUY-PDP-001", "BUY-PDP-002", "BUY-PDP-003"):
            return ("Flat variant labels had no all-groups gate and no per-SKU "
                    "price/stock/image")
        if case_id == "BUY-CART-002":
            return "Cart line had no per-variant price/stock/image"
        prefixes = {
            "BUY-ADDR": "Address book feature absent",
            "BUY-ACCT": "Change password/email/phone feature absent",
            "BUY-CAN": "Order cancellation feature absent",
            "BUY-RET": "Return/refund feature absent",
            "BUY-RATE": "Review media/edit rules absent",
            "BUY-CHAT": "Notification centre/chat attachments absent",
            "BUY-VOU": "Voucher wallet/stacking/eligibility rules incomplete",
            "BUY-PDP": "Variant/sale/purchase-limit model incomplete",
            "BUY-CHK": "Multi-voucher/per-store/weight checkout rules absent",
            "BUY-CART": "Server-synced cart/availability state absent",
            "BUY-TRK": "Buyer receipt/auto-complete flow absent",
            "BUY-WISH": "Store follow feature absent",
            "RID-AVAIL": "Rider presence/online state absent",
            "RID-PROF": "Rider profile/vehicle/document lifecycle incomplete",
            "RID-JOB": "Rider offer/accept/decline state machine absent",
            "RID-PICK": "Pickup scan/waybill/tracking flow absent",
            "RID-DLV": "Delivery OTP/COD/contact/GPS flow incomplete",
            "RID-FAIL": "Failed-delivery notifications/pay incomplete",
            "RID-EARN": "COD balance/remittance feature absent",
        }
        for prefix, cause in prefixes.items():
            if case_id.startswith(prefix):
                return cause
        return "Missing app feature"
    if group == "D":
        if case_id.startswith("BUY-PAY"):
            return "Payment gateway/order-expiry backend absent"
        if case_id.startswith("BUY-CHK") or case_id.startswith("BUY-CART"):
            return "Server must re-check price/stock/ownership"
        if case_id.startswith("BUY-TRK"):
            return "Order ownership/auto-complete server rule"
        if case_id.startswith("BUY-RATE"):
            return "Review aggregates need live data"
        if case_id.startswith("BUY-CHAT"):
            return "Live chat API absent"
        if case_id in ("TC-BUY-010", "TC-BUY-011", "TC-BUY-015",
                       "TC-BUY-016", "TC-BUY-001", "TC-BUY-029",
                       "TC-BUY-030"):
            return "Live registration/OTP/access-control API required"
        if case_id in ("TC-BUY-026", "TC-BUY-027", "TC-BUY-035",
                       "TC-BUY-036"):
            return "Server OTP expiry/resend and real network required"
        return "Live Laravel API/server-side rule required"
    if group == "E":
        if "PUSH" in case["scenario"].upper() or "NOTIFICATION" in case["scenario"].upper():
            return "Push/FCM and OS permission required"
        if case_id.startswith(("TC-BUY", "BUY-SRCH", "BUY-PDP-008", "BUY-WISH")):
            return "Seeded P-xx/ADDR-x fixture or physical device required"
        if "INTERNET" in case["scenario"].upper() or "NETWORK" in case["scenario"].upper():
            return "Real network loss/device condition required"
        return "Physical device/OS permission/GPS/network required"
    return "Unclassified"


projection = {
    "A": "Pass expected after fix",
    "B-M": "Fail until feature",
    "B-L": "Fail until feature",
    "C": "N/A - re-map/re-scope",
    "D": "Blocked until API",
    "E": "Blocked until device/data",
}


def fixed_note(case_id):
    if case_id in ("BUY-PDP-001", "BUY-PDP-002", "BUY-PDP-003"):
        return ("Fixed in this pass: two-dimensional variation matrix with required "
                "selection, per-SKU price/stock/image and disabled out-of-stock "
                "combinations (test/product_variants_test.dart).")
    if case_id == "BUY-CART-002":
        return ("Fixed in this pass: variant lines stay separate and carry their "
                "own unit price/image (test/product_variants_test.dart).")
    if case_id.startswith("BUY-ADDR"):
        return ("Fixed in this pass: address book, default, cascade, limit and "
                "phone validation (test/address_book_test.dart).")
    if case_id.startswith("BUY-SRCH"):
        return "Fixed in this pass: typo suggestion and inclusive price range (test/search_filter_test.dart)."
    if case_id.startswith("BUY-WISH"):
        return "Fixed in this pass: persisted store follow/unfollow (test/store_follow_test.dart)."
    if case_id.startswith("BUY-CAN"):
        return "Fixed in this pass: required reason, pre-shipment gate and double-tap guard (test/order_lifecycle_test.dart)."
    if case_id.startswith("BUY-TRK"):
        return "Fixed in this pass: Order Received only after delivery, one-way transition (test/order_lifecycle_test.dart)."
    if case_id.startswith("BUY-RET"):
        return "Fixed in this pass: delivery/window/evidence rules, local request tracking and escalation (test/returns_test.dart)."
    if case_id.startswith("BUY-RATE"):
        return "Fixed in this pass: 500 characters, five photos and one edit in 30 days (test/review_limits_test.dart)."
    if case_id.startswith("RID-PICK"):
        return "Fixed in this pass: manual tracking-number verification (test/pickup_verification_test.dart)."
    return "Fixed in this pass."


def partial_note(case_id):
    notes = {
        "BUY-ADDR-008": "Partial: the app keeps the original order untouched; seller view/waybill snapshot is server-side.",
        "BUY-CAN-001": "Partial: app cancellation done; stock restore and seller notification remain server-side.",
        "BUY-CAN-003": "Partial: app cancellation done; refund and voucher return remain server-side.",
        "BUY-CAN-006": "Partial: app prevents the second cancellation; stock is not restored at all yet.",
        "BUY-RATE-001": "Partial: photos are implemented; variant and masked buyer name remain missing.",
        "BUY-TRK-003": "Partial: Completed + Rate button done; seller earnings release is server-side.",
        "RID-FAIL-005": "Partial: return/no fourth attempt done; buyer and seller notifications remain server-side.",
        "BUY-RET-001": "Partial: request, Return/Refund status, window and completion block are app-side; seller notification is server-side.",
        "BUY-RET-005": "Partial: Rejected status, exact reason and Escalate are app-side; the seller rejection event is server-side.",
        "BUY-RET-006": "Partial: ordered photos, previews and removal are app-side; the seller-side photo view is server-side.",
    }
    return notes.get(case_id, "Partial: app-side behavior done; server/device check remains.")

rows = []
for case in cases:
    if case["status"] == "Pass":
        continue
    group = groups[case["id"]]
    if case["id"] in IMPLEMENTED:
        projected = "Pass expected after fix (this pass)"
    elif case["id"] in PARTIAL:
        projected = "Partial - app-side done; server/device remains"
    else:
        projected = projection[group]

    remarks = case["remarks"]
    if case["id"] in IMPLEMENTED:
        remarks = (remarks + " " if remarks else "") + fixed_note(case["id"])
    elif case["id"] in PARTIAL:
        remarks = (remarks + " " if remarks else "") + partial_note(case["id"])

    resolution = ""
    if group == "A" or case["id"] in IMPLEMENTED:
        resolution = "Fixed in this pass"
    elif case["id"] in PARTIAL:
        resolution = "Partial"

    rows.append([
        case["workbook"], case["module"], case["id"], case["status"],
        case["scenario"], group,
        {"B-M": "M", "B-L": "L"}.get(group, ""),
        root_cause(case), projected, case["actual"], remarks, resolution,
    ])

with OUT.open("w", newline="", encoding="utf-8-sig") as handle:
    writer = csv.writer(handle)
    writer.writerow([
        "Workbook", "Module", "Case ID", "Original", "Scenario", "Group",
        "Size", "Root cause", "Projected", "Actual", "Remarks", "Resolution",
    ])
    writer.writerows(rows)

print(f"wrote {len(rows)} cases to {OUT}")
for workbook in [
    "Buyer Account Creation", "Buyer Modules", "Rider Account Creation",
    "Courier Account Creation", "Rider Modules", "Courier Modules",
]:
    counts = Counter(
        groups[c["id"]] for c in cases
        if c["workbook"] == workbook and c["status"] != "Pass"
    )
    print(f"{workbook:28} {dict(counts)}")
print("TOTAL", dict(Counter(groups.values())))
