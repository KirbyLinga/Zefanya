# -*- coding: utf-8 -*-
"""Build the Phase 6 workbook results from the verified classification.

The final-status sets live in qa/_classify_tmp.py.  This script adds the
per-case evidence and notes, asserts that every one of the 377 workbook rows
is covered exactly once, and writes both the final CSV and the rebuilt
static-review CSV.
"""

from __future__ import annotations

import collections
import csv
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
QA = ROOT / "qa"

ROWS = json.loads((QA / "workbook_rows.json").read_text(encoding="utf-8"))

# The status sets and classify() are the reviewed artefact from Phase 6.
_ns: dict[str, object] = {}
_source = (QA / "_classify_tmp.py").read_text(encoding="utf-8-sig")
exec(_source.split("for s in [")[0], _ns)
classify = _ns["classify"]

WORKBOOKS = [
    "Buyer - Account Creation",
    "Buyer - Modules",
    "Rider - Account Creation",
    "Courier - Account Creation",
    "Rider - Modules",
    "Courier - Modules",
]
STATUSES = ["Pass", "Needs device/API", "Fail", "N/A"]
EXPECTED_COUNTS = {
    "Buyer - Account Creation": {"Pass": 32, "Needs device/API": 6, "Fail": 2, "N/A": 2},
    "Buyer - Modules": {"Pass": 80, "Needs device/API": 37, "Fail": 11, "N/A": 3},
    "Rider - Account Creation": {"Pass": 3, "Needs device/API": 0, "Fail": 0, "N/A": 44},
    "Courier - Account Creation": {"Pass": 0, "Needs device/API": 0, "Fail": 0, "N/A": 52},
    "Rider - Modules": {"Pass": 25, "Needs device/API": 15, "Fail": 13, "N/A": 0},
    "Courier - Modules": {"Pass": 0, "Needs device/API": 0, "Fail": 0, "N/A": 52},
}

# ---------------------------------------------------------------------------
# Pass evidence: a passing test (file:line) or the implementation line that
# proves the workbook behaviour.  Keep these concrete; the notes column carries
# any scope caveat rather than hiding it.
# ---------------------------------------------------------------------------
PASS_EVIDENCE: dict[str, str] = {
    # Buyer account creation -------------------------------------------------
    "TC-BUY-002": "mobile/lib/screens/auth/registration_screen.dart:287-310,691-795",
    "TC-BUY-003": "mobile/lib/screens/auth/registration_screen.dart:287-310,691-795",
    "TC-BUY-004": "mobile/test/validators_test.dart:49",
    "TC-BUY-005": "mobile/test/validators_test.dart:32",
    "TC-BUY-006": "mobile/test/validators_test.dart:24",
    "TC-BUY-007": "mobile/test/validators_test.dart:82",
    "TC-BUY-008": "mobile/lib/screens/auth/registration_screen.dart:247-253",
    "TC-BUY-009": "mobile/lib/screens/auth/registration_screen.dart:767-775",
    "TC-BUY-010": "web/app/Http/Requests/Buyer/StoreBuyerRegistrationRequest.php:28; web/tests/Feature/Api/BuyerRegistrationApiTest.php:207; web/config/database.php:57",
    "TC-BUY-011": "web/app/Http/Requests/Buyer/StoreBuyerRegistrationRequest.php:28; web/config/database.php:57",
    "TC-BUY-012": "mobile/lib/screens/auth/registration_screen.dart:390",
    "TC-BUY-013": "mobile/lib/screens/auth/registration_screen.dart:778-795",
    "TC-BUY-014": "mobile/lib/screens/auth/registration_screen.dart:778-795",
    "TC-BUY-017": "mobile/test/validators_test.dart:61",
    "TC-BUY-018": "mobile/test/validators_test.dart:65",
    "TC-BUY-019": "mobile/test/registration_flow_test.dart:261",
    "TC-BUY-020": "mobile/lib/screens/auth/registration_screen.dart:829-834,861-873",
    "TC-BUY-021": "mobile/lib/screens/auth/registration_screen.dart:159,886-903",
    "TC-BUY-022": "mobile/lib/screens/auth/registration_screen.dart:358,663",
    "TC-BUY-024": "mobile/test/otp_flow_test.dart:564; web/tests/Feature/Api/BuyerOtpApiTest.php:90",
    "TC-BUY-025": "mobile/test/otp_flow_test.dart:552; mobile/lib/screens/auth/otp_screen.dart:360-362",
    "TC-BUY-026": "web/tests/Feature/Api/BuyerOtpApiTest.php:106; mobile/lib/screens/auth/otp_screen.dart:133-136",
    "TC-BUY-030": "mobile/lib/state/auth_state.dart:224,291-310; mobile/lib/main.dart:381-397; web/tests/Feature/Api/CourierLoginApiTest.php:138",
    "TC-BUY-031": "mobile/lib/screens/auth/registration_screen.dart:694,709,762,790,829-834,861-873",
    "TC-BUY-032": "mobile/lib/screens/auth/registration_screen.dart:551-566; mobile/lib/main.dart:290-291",
    "TC-BUY-033": "mobile/lib/screens/auth/registration_screen.dart:40-42,560-566",
    "TC-BUY-034": "mobile/android/app/src/main/AndroidManifest.xml:13; mobile/lib/screens/auth/registration_screen.dart:560-566",
    "TC-BUY-037": "mobile/test/otp_flow_test.dart:552; mobile/lib/screens/auth/otp_screen.dart:361-362",
    "TC-BUY-038": "mobile/lib/screens/auth/otp_screen.dart:57,105-118",
    "TC-BUY-039": "mobile/lib/screens/auth/registration_screen.dart:459-484; mobile/lib/screens/auth/otp_screen.dart:247-252",
    "TC-BUY-041": "mobile/test/auth_flow_test.dart:129",
    "TC-BUY-042": "mobile/test/auth_flow_test.dart:146",
    "TC-RID-029": "mobile/lib/screens/auth/registration_screen.dart:1216-1234",
    "TC-RID-046": "mobile/lib/state/auth_state.dart:291-310; mobile/test/auth_flow_test.dart:93-102,129",
    "TC-RID-047": "mobile/lib/state/auth_state.dart:291-310; mobile/test/auth_flow_test.dart:146; mobile/lib/main.dart:381-397",
    # Buyer modules: Account & Security --------------------------------------
    "BUY-ACCT-002": "mobile/test/workbook_buyer_phase3_test.dart:282; mobile/test/validators_test.dart:11",
    "BUY-ACCT-003": "mobile/test/account_security_test.dart:99",
    "BUY-ACCT-004": "mobile/test/account_security_test.dart:130",
    "BUY-ACCT-005": "mobile/test/account_security_test.dart:158",
    "BUY-ACCT-006": "mobile/test/account_security_test.dart:211",
    "BUY-ACCT-007": "mobile/test/account_security_test.dart:266",
    "BUY-ACCT-008": "mobile/test/account_security_test.dart:343",
    "BUY-ACCT-009": "mobile/test/account_security_test.dart:401",
    "BUY-ACCT-011": "mobile/test/account_security_test.dart:502",
    "BUY-ACCT-012": "mobile/test/auth_flow_test.dart:129,146",
    # Address book -----------------------------------------------------------
    "BUY-ADDR-001": "mobile/test/address_book_test.dart:99",
    "BUY-ADDR-002": "mobile/test/address_book_test.dart:229",
    "BUY-ADDR-003": "mobile/test/address_book_test.dart:258",
    "BUY-ADDR-004": "mobile/test/address_book_test.dart:130",
    "BUY-ADDR-005": "mobile/test/address_book_test.dart:142",
    "BUY-ADDR-006": "mobile/test/address_book_test.dart:120",
    "BUY-ADDR-007": "mobile/test/address_book_test.dart:153",
    "BUY-ADDR-011": "mobile/test/address_book_test.dart:300",
    # Browse, search & filter ------------------------------------------------
    "BUY-SRCH-001": "mobile/test/catalog_state_test.dart:121; mobile/test/catalog_wiring_test.dart:177",
    "BUY-SRCH-002": "mobile/test/catalog_state_test.dart:132; mobile/test/catalog_wiring_test.dart:190",
    "BUY-SRCH-003": "mobile/test/catalog_state_test.dart:140",
    "BUY-SRCH-004": "mobile/test/workbook_buyer_phase3_test.dart:688",
    "BUY-SRCH-006": "mobile/test/catalog_state_test.dart:140; mobile/lib/screens/buyer/search_screen.dart:375-376",
    "BUY-SRCH-007": "mobile/test/workbook_buyer_phase3_test.dart:719",
    "BUY-SRCH-008": "mobile/test/search_filter_test.dart:50",
    "BUY-SRCH-009": "mobile/test/product_sort_test.dart:21-37; mobile/lib/screens/buyer/search_screen.dart:292-301",
    "BUY-SRCH-011": "mobile/test/buyer_bm_quick_wins_test.dart:609",
    "BUY-SRCH-012": "mobile/test/workbook_buyer_phase3_test.dart:781",
    # Product details --------------------------------------------------------
    "BUY-PDP-001": "mobile/test/product_variants_test.dart:111",
    "BUY-PDP-002": "mobile/test/product_variants_test.dart:145",
    "BUY-PDP-003": "mobile/test/product_variants_test.dart:173",
    "BUY-PDP-004": "mobile/test/quantity_limit_test.dart:25",
    "BUY-PDP-005": "mobile/test/buyer_bm_quick_wins_test.dart:294",
    "BUY-PDP-007": "mobile/test/workbook_buyer_phase3_test.dart:460",
    "BUY-PDP-008": "mobile/test/buyer_bm_quick_wins_test.dart:393",
    # Wishlist & followed stores --------------------------------------------
    "BUY-WISH-001": "mobile/lib/state/wishlist_state.dart:107-117; mobile/lib/screens/buyer/saved_screen.dart:52-66",
    "BUY-WISH-002": "mobile/lib/state/wishlist_state.dart:107-117",
    "BUY-WISH-004": "mobile/test/store_follow_test.dart:65",
    "BUY-WISH-005": "mobile/lib/state/auth_state.dart:224; mobile/lib/main.dart:381-397",
    # Cart -------------------------------------------------------------------
    "BUY-CART-001": "mobile/test/quantity_limit_test.dart:45; mobile/lib/state/cart_state.dart:619-646",
    "BUY-CART-002": "mobile/test/product_variants_test.dart:219",
    "BUY-CART-003": "mobile/test/claude_followups_test.dart:29",
    "BUY-CART-004": "mobile/test/claude_followups_test.dart:47; mobile/lib/state/cart_state.dart:200-206,675",
    "BUY-CART-005": "mobile/test/quantity_limit_test.dart:25",
    "BUY-CART-007": "mobile/test/buyer_bm_quick_wins_test.dart:540",
    "BUY-CART-009": "mobile/test/cart_swipe_test.dart:41; mobile/lib/state/cart_state.dart:707",
    "BUY-CART-012": "mobile/test/task5_cart_nav_test.dart:39; mobile/lib/widgets/cart_button.dart:21,47-70; mobile/lib/state/cart_state.dart:197",
    # Vouchers ---------------------------------------------------------------
    "BUY-VOU-001": "mobile/test/buyer_bm_quick_wins_test.dart:155",
    "BUY-VOU-002": "mobile/test/buyer_bm_quick_wins_test.dart:192",
    "BUY-VOU-003": "mobile/test/buyer_bm_quick_wins_test.dart:192",
    "BUY-VOU-004": "mobile/test/workbook_buyer_phase3_test.dart:324",
    "BUY-VOU-005": "mobile/test/buyer_bm_quick_wins_test.dart:229",
    "BUY-VOU-006": "mobile/test/workbook_buyer_phase3_test.dart:358",
    "BUY-VOU-007": "mobile/test/workbook_buyer_phase3_test.dart:376",
    "BUY-VOU-008": "mobile/test/workbook_buyer_phase3_test.dart:403",
    "BUY-VOU-009": "mobile/test/workbook_buyer_phase3_test.dart:436",
    "BUY-VOU-010": "mobile/test/pricing_rules_test.dart:57-72; mobile/lib/config/pricing.dart:191-192",
    "BUY-VOU-011": "mobile/test/buyer_bm_quick_wins_test.dart:258",
    # Checkout & payment -----------------------------------------------------
    "BUY-CHK-001": "mobile/test/workbook_buyer_phase3_test.dart:512",
    "BUY-CHK-005": "mobile/test/workbook_buyer_phase3_test.dart:557",
    "BUY-CHK-006": "mobile/test/workbook_buyer_phase3_test.dart:575",
    "BUY-CHK-007": "mobile/test/workbook_buyer_phase3_test.dart:588",
    "BUY-CHK-009": "mobile/test/workbook_buyer_phase3_test.dart:627",
    "BUY-CHK-010": "mobile/test/workbook_buyer_phase3_test.dart:657",
    "BUY-PAY-001": "mobile/test/workbook_buyer_phase3_test.dart:575; mobile/lib/screens/buyer/checkout_screen.dart:44,226-240",
    # Order tracking & lifecycle --------------------------------------------
    "BUY-TRK-001": "mobile/lib/screens/buyer/orders_status_screen.dart:144-147,350",
    "BUY-TRK-003": "mobile/test/order_lifecycle_test.dart:99",
    "BUY-TRK-004": "mobile/test/order_lifecycle_test.dart:115",
    "BUY-CAN-002": "mobile/test/order_lifecycle_test.dart:44",
    "BUY-CAN-004": "mobile/lib/data/mock_data.dart:564-569",
    "BUY-CAN-005": "mobile/test/order_lifecycle_test.dart:56",
    # Returns ----------------------------------------------------------------
    "BUY-RET-002": "mobile/test/returns_test.dart:192",
    "BUY-RET-003": "mobile/test/returns_test.dart:221",
    "BUY-RET-004": "mobile/test/returns_test.dart:280",
    # Ratings & reviews ------------------------------------------------------
    "BUY-RATE-002": "mobile/test/review_flow_test.dart:286; mobile/lib/screens/buyer/order_detail_screen.dart:493",
    "BUY-RATE-003": "mobile/test/review_flow_test.dart:120",
    "BUY-RATE-004": "mobile/test/review_limits_test.dart:99",
    "BUY-RATE-005": "mobile/test/review_limits_test.dart:189",
    # Chat -------------------------------------------------------------------
    "BUY-CHAT-002": "mobile/test/buyer_bm_quick_wins_test.dart:422",
    "BUY-CHAT-005": "mobile/test/buyer_bm_quick_wins_test.dart:496",
    # Rider modules ----------------------------------------------------------
    "RID-PROF-001": "mobile/test/workbook_rider_phase4_test.dart:140",
    "RID-PROF-002": "mobile/test/workbook_rider_phase4_test.dart:217",
    "RID-PROF-003": "mobile/test/workbook_rider_phase4_test.dart:245",
    "RID-PROF-004": "mobile/test/workbook_rider_phase4_test.dart:270",
    "RID-PROF-005": "mobile/test/workbook_rider_phase4_test.dart:302",
    "RID-PROF-006": "mobile/test/workbook_rider_phase4_test.dart:345",
    "RID-AVAIL-003": "mobile/test/workbook_rider_phase4_test.dart:372",
    "RID-PICK-001": "mobile/test/workbook_rider_phase4_test.dart:391",
    "RID-PICK-003": "mobile/test/pickup_verification_test.dart:119; mobile/test/courier_handover_wiring_test.dart:136",
    "RID-PICK-004": "mobile/test/pickup_verification_test.dart:91",
    "RID-DLV-001": "mobile/lib/screens/courier/delivery_tracker_screen.dart:59-71,973-987",
    "RID-DLV-002": "mobile/lib/screens/courier/delivery_tracker_screen.dart:973-987",
    "RID-DLV-003": "mobile/lib/screens/courier/delivery_tracker_screen.dart:486-488,996,1038",
    "RID-DLV-004": "mobile/test/workbook_rider_phase4_test.dart:424; mobile/test/courier_handover_wiring_test.dart:85",
    "RID-DLV-005": "mobile/test/workbook_rider_phase4_test.dart:461; mobile/test/courier_handover_wiring_test.dart:100",
    "RID-DLV-006": "mobile/test/workbook_rider_phase4_test.dart:479; mobile/test/courier_handover_wiring_test.dart:119",
    "RID-FAIL-001": "mobile/test/courier_failure_flow_test.dart:97; mobile/test/courier_handover_wiring_test.dart:152",
    "RID-FAIL-002": "mobile/test/courier_failure_flow_test.dart:118",
    "RID-FAIL-003": "mobile/test/courier_failure_flow_test.dart:147; mobile/test/courier_handover_wiring_test.dart:168",
    "RID-FAIL-004": "mobile/test/courier_failure_flow_test.dart:172",
    "RID-EARN-001": "mobile/test/workbook_rider_phase4_test.dart:497",
    "RID-EARN-002": "mobile/test/workbook_rider_phase4_test.dart:543",
    "RID-EARN-003": "mobile/test/workbook_rider_phase4_test.dart:559",
    "RID-EARN-004": "mobile/test/workbook_rider_phase4_test.dart:580",
    "RID-EARN-005": "mobile/test/workbook_rider_phase4_test.dart:605",
}

PASS_CAVEAT = {
    "TC-BUY-032": "Code/layout evidence only; no physical keyboard run on a device. Keyboard-inset behaviour still needs a device check.",
    "TC-BUY-033": "Lifecycle state is kept by the wizard's controllers and _KeepAlive; the OS background/kill case was not run on a device.",
    "TC-BUY-034": "Rotation keeps the activity and the PageView children alive, and the manifest handles orientation changes without recreating the activity; no physical rotation run.",
    "BUY-SRCH-001": "The current mock catalogue does not seed the workbook's P-02 fixture. Category filtering is proven against a foreign-catalogue test fixture, not P-02.",
    "BUY-SRCH-002": "The workbook's CAT-1..CAT-5 fixture is not in the current mock catalogue. Store filtering is proven against a foreign-catalogue fixture.",
    "BUY-SRCH-003": "Search matching is proven against the foreign-catalogue fixture; the workbook's P-02 row is not seeded in the mock catalogue.",
    "BUY-TRK-003": "App-side Order Received is implemented and tested. Seller earnings release is server-side and is not asserted by this app test.",
    "RID-DLV-001": "The app-side step sequence and proof gate pass. Buyer-side display of the proof image is absent and is tracked as RID-DLV-008.",
}

# ---------------------------------------------------------------------------
# Needs device/API: the app-side work exists, but the workbook outcome depends
# on a live server, a real device capability, or both.
# ---------------------------------------------------------------------------
NEEDS_NOTES: dict[str, str] = {
    "TC-BUY-001": "Live registration/API and real OTP email. Mock registration stops at the admin gate and sends no email; app path is auth_state.dart:531-575.",
    "TC-BUY-027": "Resend against the live OTP endpoint and a real mailbox. The resend UI/cooldown is implemented at otp_screen.dart:177-220.",
    "TC-BUY-029": "Login as the newly verified account against the live API; admin approval and token issuance are server-side.",
    "TC-BUY-035": "Requires a real device with networking disabled; mock mode has no network call to fail. ApiClient timeout/error mapping is not exercised.",
    "TC-BUY-036": "Requires a real device and a controllable network drop during submit; exactly-one-account is a server idempotency check.",
    "TC-BUY-040": "Requires a device at the maximum system font size. The app clamps text scale to 1.3 (main.dart:290-291); the workbook asks for a real small-screen run.",
    "BUY-ACCT-001": "Profile edit is implemented locally, but the workbook requires a live profile endpoint, refresh and re-login to prove persistence.",
    "BUY-ACCT-010": "Camera/gallery avatar replacement needs a real device and OS permission handling.",
    "BUY-ADDR-009": "Address ownership is not enforced by an API because the address book is local-only; needs a live address endpoint to test cross-buyer denial.",
    "BUY-ADDR-010": "No geolocation/device-location integration; needs a real device with permission allowed/denied.",
    "BUY-SRCH-005": "Search input handling is client-side; SQL-injection safety and server empty-result behaviour need the live catalogue API.",
    "BUY-SRCH-010": "Inactive/draft/deleted filtering is a server catalogue rule; the current mock catalogue does not carry those states for this fixture.",
    "BUY-SRCH-013": "Requires a real device with networking disabled and a live catalogue to prove the offline message then recovery.",
    "BUY-PDP-009": "Pinch-zoom needs a real touch screen. The pager itself is covered by product_gallery_test.dart:122; zoom sharpness is not a widget-test fact.",
    "BUY-PDP-010": "Requires a real host, Android assetlinks.json/iOS associated domains, and a second phone. The in-app link parser is tested (buyer_bm_quick_wins_test.dart:343), the host handoff is not.",
    "BUY-WISH-003": "Wishlist rendering has no sold-out/unavailable product state; needs a live catalogue response carrying stock 0/deleted.",
    "BUY-CART-006": "A live price change must be revalidated by the server at checkout; the local cart cannot receive that change by itself.",
    "BUY-VOU-012": "Requires cancellation/refund server support to return MCH50 to the wallet; cancellation currently only stores the buyer flag.",
    "BUY-CHK-004": "Serviceability is a server/address-provider rule; no serviceability endpoint is called by the app.",
    "BUY-CHK-008": "A live price change between cart and Place Order must be detected server-side; the app has no live price revalidation in mock mode.",
    "BUY-CHK-011": "Server must ignore/reject a tampered price. This cannot be proven from the client; PlaceOrderApiTest does not cover a tampered total.",
    "BUY-CHK-012": "Requires a real device and a network drop after Place Order, plus server idempotency to prove exactly one order.",
    "BUY-CHK-013": "Requires a real device interruption (background/kill) on checkout; restoration of address, vouchers, payment method and total is not tested.",
    "BUY-PAY-002": "E-wallet/card success needs a real payment gateway and server callback; mock only offers the COD path.",
    "BUY-PAY-003": "Gateway failure handling needs a live payment provider.",
    "BUY-PAY-004": "Buyer-cancel-at-gateway needs a live payment provider and server order state.",
    "BUY-PAY-005": "App-closed-on-gateway needs a live provider callback and server reconciliation.",
    "BUY-PAY-006": "Unpaid auto-cancel, stock restore and voucher return are server jobs; the app has no scheduler/refund worker.",
    "BUY-PAY-007": "The 59/61-minute boundary needs live server time and the auto-cancel job.",
    "BUY-PAY-008": "Switching back from an e-wallet app needs a device and a live gateway; no deep-link return handler is wired.",
    "BUY-PAY-009": "Back during payment processing needs a device plus a live gateway to prove exactly-once charging.",
    "BUY-TRK-005": "Automatic completion and earnings release are server-side timers; the app only displays order status.",
    "BUY-TRK-006": "Cross-buyer order denial is an API ownership check; the mock order list is local.",
    "BUY-TRK-007": "Push notification and lock-screen handling need Firebase/APNs (not in pubspec) and a device.",
    "BUY-RET-001": "Seller notification and the server-side return state transition need the live returns API; the app-side request form is tested.",
    "BUY-RET-005": "Live return status/rejection reason and escalation must come from the server; the app renders a local ReturnState.",
    "BUY-RET-006": "Camera/gallery evidence needs a real device; the app has the picker and preview path (return_screen.dart).",
    "BUY-RATE-006": "Average rating and count are aggregate server values; the app only renders reviews returned by the API.",
    "BUY-RATE-007": "Camera and multi-select photo limits need a real device; the 5-photo limit is unit-tested (review_limits_test.dart:136).",
    "BUY-CHAT-001": "Two-way seller chat needs the live chat API/websocket; the mock has a local thread only.",
    "BUY-CHAT-006": "Background message push needs Firebase/APNs and a device; no push plugin is present in pubspec.",
    "BUY-CHAT-007": "OS notification permission disabled is a device Settings scenario; no push channel exists to observe.",
    "BUY-CHAT-008": "Offline send/retry needs a device network drop and live chat transport.",
    "RID-PROF-007": "Cross-rider profile denial is an API ownership check; the app has no server profile endpoint for Rider 2.",
    "RID-AVAIL-005": "Location permission and the Settings deep link need a real device; the local Online toggle does not request location.",
    "RID-AVAIL-006": "Location-off-while-online needs device location services and a server presence/offer feed.",
    "RID-AVAIL-007": "Force-close/session-timeout presence is server-owned; the app resets local Online on boot but cannot prove the 5-minute server timeout.",
    "RID-JOB-005": "Concurrent accept/manual assign needs the live assignment API and two clients.",
    "RID-JOB-007": "Cross-company job denial is a server authorization check.",
    "RID-JOB-008": "Lock-screen offer push needs Firebase/APNs, a device and a server offer timer.",
    "RID-JOB-009": "Opening the map app at the pickup address needs a real device and a maps/url_launcher handoff.",
    "RID-PICK-005": "Double-scan locking needs a real scanner/device and server idempotency; the app uses manual tracking-number entry, so there is no scan event to double.",
    "RID-PICK-007": "Offline pickup needs a device network drop and the live milestone API; the app does not queue milestones offline.",
    "RID-DLV-007": "Cross-rider milestone denial is a server authorization check.",
    "RID-DLV-009": "Offline Delivered needs a device network drop and retry queue; the app sends the milestone immediately and keeps the photo only in screen state.",
    "RID-DLV-010": "Phone dialer and SMS/chat handoff need a real device and buyer contact data.",
    "RID-DLV-011": "GPS delivery location needs device location permission and a server field; the app does not record coordinates.",
    "RID-EARN-006": "The rider has no confirm button by design; the 403 on a self-confirmation can only be proven against the live remittance API.",
}

# ---------------------------------------------------------------------------
# Fail: implemented in part, but the workbook's required outcome is absent.
# ---------------------------------------------------------------------------
FAIL_NOTES: dict[str, str] = {
    "TC-BUY-015": "No duplicate-phone rule. StoreBuyerRegistrationRequest validates only the 09XXXXXXXXX shape (line 28), and the app has no account store to check.",
    "TC-BUY-016": "No duplicate-phone rule and no +63-to-09 normalization on registration. The form accepts only 09; the server compares no unique contact value.",
    "BUY-ADDR-008": "Orders have no address snapshot (mock_data.dart:482-505). Editing/deleting a local address cannot preserve what the seller/waybill show.",
    "BUY-PDP-006": "Product supports compareAtPrice only; there is no flash-sale countdown, units-left field or voucher Claim action on the product screen (mock_data.dart:58-85).",
    "BUY-CART-008": "The cart is a phone-local SharedPreferences list (cart_state.dart:171); it is not tied to the account or synced to another device.",
    "BUY-CHK-003": "Shipping is flat by seller group (pricing.dart:37-39). There is no weight input or 1 kg/1.2 kg tariff.",
    "BUY-TRK-002": "The buyer timeline is built from the app's local DeliveryMilestone steps (courier_tracker_screen.dart:46-50), not Logistics hub events; hub names/times are absent.",
    "BUY-CAN-001": "Cancellation stores a buyer flag only (order_state.dart:278-298). It does not restore stock or notify the seller.",
    "BUY-CAN-003": "Cancellation stores a buyer flag only (order_state.dart:278-298). It starts no refund and does not return MCH50 to the wallet.",
    "BUY-CAN-006": "The second tap is suppressed locally, but there is no stock restore at all, so restoring stock exactly once cannot be demonstrated.",
    "BUY-RATE-001": "ProductReview stores stars, text and photos, but no variant and no reviewer name/masking field (review_state.dart:49-80).",
    "BUY-CHAT-003": "No order-status notification delivery. The app has an in-app notification list only; no push/messaging plugin is present.",
    "BUY-CHAT-004": "No flash-sale reminder scheduler or notification delivery path.",
    "RID-PROF-008": "Document upload offers camera/gallery only (image_picker_helper.dart:27-45); there is no Files-app picker and no Logistics re-verification flag.",
    "RID-AVAIL-001": "Online is a local bool (courier_state.dart:516-527). No server presence call exists, so Logistics cannot see the rider go Online and no offer feed follows.",
    "RID-AVAIL-002": "Offline is a local bool only (courier_state.dart:532-535); there is no server presence/offer routing to remove the rider from assignment.",
    "RID-AVAIL-004": "No session-timeout/presence mechanism on the server; the local toggle cannot make Logistics show the rider Offline.",
    "RID-JOB-001": "Jobs are dispatcher-assigned, not timed offers (courier_state.dart:3-8,309-379). There is no COD-on-offer or Accept/Decline action.",
    "RID-JOB-002": "No offer/decline flow; jobs are already assigned to the rider.",
    "RID-JOB-003": "No 60-second offer timer or expiry rejection.",
    "RID-JOB-004": "No decline counter or Unassigned queue.",
    "RID-JOB-006": "No concurrent-job limit or fourth-offer block.",
    "RID-PICK-002": "Pickup uses manual tracking-number entry, not a waybill scan; there is no scan event/time or camera path to prove.",
    "RID-PICK-006": "No camera scan exists, so there is no camera-denied state or Settings link.",
    "RID-DLV-008": "The courier picker is now camera-only (delivery_tracker_screen.dart:802-804), but the buyer's tracker does not display the proof photo or delivery time, which the workbook requires.",
    "RID-FAIL-005": "The 3-attempt return is implemented (courier_state.dart:1065-1068; courier_failure_flow_test.dart:213), but buyer and seller notifications are absent.",
}

# ---------------------------------------------------------------------------
# N/A: recorded scope decisions, not untested features.
# ---------------------------------------------------------------------------
NA_NOTES = {
    "Buyer - Account Creation": "Decision 2: the app requires admin approval after OTP; the workbook's auto-active assumption is out of scope.",
    "Courier - Account Creation": "Decision 1/5: courier accounts are created by Logistics web; mobile account creation is out of scope.",
    "Courier - Modules": "Decision 5: Courier Modules are hub-to-hub line-haul work; the mobile app has no hub-to-hub role. App-side courier work is evidenced under Rider Modules.",
    "Rider - Account Creation": "Decision 1: rider accounts are created/activated by Logistics web; the mobile app has no public rider registration.",
}
NA_OVERRIDES = {
    "BUY-CHK-002": "Decision 3: shipping stays flat per seller group (P60, free from P1,000); per-store/zone shipping is out of scope.",
    "BUY-CART-010": "Decision 4: the cart stays local on the phone; there is no server-side cart.",
    "BUY-CART-011": "Decision 4: the cart stays local on the phone; there is no server-side cart.",
}


def default_na(cid: str, workbook: str) -> str:
    if cid in NA_OVERRIDES:
        return NA_OVERRIDES[cid]
    return NA_NOTES[workbook]


def main() -> int:
    rows_by_id = {r["Test Case ID"]: r for r in ROWS}
    if len(rows_by_id) != 377:
        raise SystemExit(f"workbook_rows.json has {len(rows_by_id)} unique ids, expected 377")

    status_by_id = {cid: classify(cid) for cid in rows_by_id}
    passes = {cid for cid, s in status_by_id.items() if s == "Pass"}
    needs = {cid for cid, s in status_by_id.items() if s == "Needs device/API"}
    fails = {cid for cid, s in status_by_id.items() if s == "Fail"}
    nas = {cid for cid, s in status_by_id.items() if s == "N/A"}

    for name, supplied, expected in [
        ("PASS_EVIDENCE", set(PASS_EVIDENCE), passes),
        ("NEEDS_NOTES", set(NEEDS_NOTES), needs),
        ("FAIL_NOTES", set(FAIL_NOTES), fails),
    ]:
        missing = expected - supplied
        extra = supplied - expected
        if missing or extra:
            raise SystemExit(f"{name} mismatch; missing={sorted(missing)} extra={sorted(extra)}")
    if set(PASS_CAVEAT) - passes:
        raise SystemExit(f"PASS_CAVEAT has non-pass ids: {sorted(set(PASS_CAVEAT) - passes)}")

    counts = collections.Counter((r["Workbook"], status_by_id[r["Test Case ID"]]) for r in ROWS)
    for workbook in WORKBOOKS:
        for status in STATUSES:
            got = counts[(workbook, status)]
            want = EXPECTED_COUNTS[workbook][status]
            if got != want:
                raise SystemExit(f"{workbook} {status}: got {got}, expected {want}")
    total = collections.Counter(status_by_id.values())
    if total != {"Pass": 140, "Needs device/API": 58, "Fail": 26, "N/A": 153}:
        raise SystemExit(f"total mismatch: {dict(total)}")

    manual_path = QA / "MANUAL_TESTS.md"
    if not manual_path.exists():
        raise SystemExit("qa/MANUAL_TESTS.md is missing")
    manual = manual_path.read_text(encoding="utf-8")
    manual_missing = sorted(cid for cid in needs if cid not in manual)
    if manual_missing:
        raise SystemExit(f"manual script missing Needs ids: {manual_missing}")

    # A file:line citation is only evidence if the file and line exist.
    citation = re.compile(r"([A-Za-z0-9_./-]+\.(?:dart|php|xml)):([0-9][0-9,\-]*)")
    bad_citations = []
    for cid in sorted(passes):
        for match in citation.finditer(PASS_EVIDENCE[cid]):
            rel, line_spec = match.group(1), match.group(2)
            path = ROOT / rel
            if not path.exists():
                bad_citations.append(f"{cid}: missing {rel}")
                continue
            line_count = len(path.read_text(encoding="utf-8", errors="replace").splitlines())
            cited = [int(n) for n in re.findall(r"\d+", line_spec)]
            if cited and max(cited) > line_count:
                bad_citations.append(f"{cid}: {rel}:{line_spec} > {line_count} lines")
    if bad_citations:
        raise SystemExit("bad evidence citations:\n  " + "\n  ".join(bad_citations))

    final_rows = []
    static_rows = []
    for row in ROWS:
        cid = row["Test Case ID"]
        status = status_by_id[cid]
        if status == "Pass":
            evidence = PASS_EVIDENCE[cid]
            note = PASS_CAVEAT.get(cid, "Passing test/source evidence listed in the Evidence column.")
        elif status == "Needs device/API":
            evidence = "Needs device/API; see Notes"
            note = NEEDS_NOTES[cid]
        elif status == "Fail":
            evidence = "Missing required behaviour; see Notes"
            note = FAIL_NOTES[cid]
        else:
            decision = {
                "Buyer - Account Creation": "2",
                "Buyer - Modules": "3" if cid == "BUY-CHK-002" else "4",
                "Rider - Account Creation": "1",
                "Courier - Account Creation": "1/5",
                "Courier - Modules": "5",
            }[row["Workbook"]]
            evidence = f"Decision {decision}; see qa/DECISIONS.md"
            note = default_na(cid, row["Workbook"])
        final_rows.append(
            {
                "Workbook": row["Workbook"],
                "Sheet": row["Sheet"],
                "Test Case ID": cid,
                "Test Scenario": row["Test Scenario"],
                "Original Status": row["Status"],
                "Final Status": status,
                "Evidence": evidence,
                "Notes": note,
            }
        )
        static_rows.append(
            {
                "Test Case ID": cid,
                "Old Status": row["Status"],
                "New Status": status,
                "Evidence (file:line)": evidence,
                "Notes": note,
            }
        )

    with (QA / "final_results.csv").open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(final_rows[0].keys()))
        writer.writeheader()
        writer.writerows(final_rows)

    with (QA / "static_review.csv").open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(static_rows[0].keys()))
        writer.writeheader()
        writer.writerows(static_rows)

    lines = [
        "# Phase 6 workbook counts",
        "",
        "Source: `qa/Mobile_App_Combined_Test_Results.xlsx` (377 cases).",
        "Generated from `qa/final_results.csv` by `qa/build_final_results.py`.",
        "",
        "| Workbook | Pass | Needs device/API | Fail | N/A | Total |",
        "|---|---:|---:|---:|---:|---:|",
    ]
    for workbook in WORKBOOKS:
        row = [counts[(workbook, s)] for s in STATUSES]
        lines.append(f"| {workbook} | {row[0]} | {row[1]} | {row[2]} | {row[3]} | {sum(row)} |")
    lines.append(
        f"| **Total** | **{total['Pass']}** | **{total['Needs device/API']}** | "
        f"**{total['Fail']}** | **{total['N/A']}** | **{sum(total.values())}** |"
    )
    lines += [
        "",
        "Passes require a passing test or a source line showing the behaviour; the evidence is in `qa/final_results.csv`.",
        "`Needs device/API` rows are scripted in `qa/MANUAL_TESTS.md`.",
        "`Fail` rows record a missing behaviour, not an unrun test.",
    ]
    (QA / "PHASE6_COUNTS.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    (QA / "phase6_counts.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines))
    print(f"\nWrote {len(final_rows)} rows to qa/final_results.csv")
    print(f"Wrote {len(static_rows)} rows to qa/static_review.csv")
    return 0


if __name__ == "__main__":
    sys.exit(main())
