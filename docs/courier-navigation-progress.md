# Courier hub directions

Implemented the saved hub addresses and destination navigation part of C3, keeping C1's real-document rehearsal open for the user's actual documents/device.

Courier trip cards now show the origin and destination addresses saved by Logistics. Active cards offer **Directions to destination** when a usable destination address exists. The action opens external driving directions using only that hub name/address. Missing addresses get a clear Logistics-contact message. Returning to the app preserves the trip, parcels and seal; launch failure leaves the action available to retry. Completed history shows the addresses without an active navigation action.

The existing authenticated trip API/resource is extended additively; no route or schema change. Addresses use the native hub formatter, not invented coordinates. A fixed HTTPS Maps URL encodes address text, enforces the documented URL size limit and includes no Courier identity, bearer token, buyer address or current coordinates. External Maps decides its own location permission and route. This is general driving directions, not truck routing, a verified destination pin, geofence arrival, live ETA or background tracking.

Verification:

- PHP: 39 tests / 994 assertions passed, covering Courier modules and new assigned-driver address privacy/empty fallback.
- Mobile: final `00:13 +26: All tests passed!`; seven new tests include 360x640/1.3 text, encoded URI, legacy/malformed fields, opening/returning, false/exception recovery, missing address and completed history.
- Analysis: same existing eight issues; no new issues. Full previous-stage mobile 901 tests remains the last full-suite result; not rerun for this Courier-only change.
- Two deliberate URL security faults detected/restored; temporary branch deleted. Pint waived/unrun. No web frontend change/build needed.
- Real own-Courier API GET returned saved demo hub addresses in Pila and Santa Cruz; verification token logged out. No trip/duty/profile or other business record was changed.
- Updated browser preview uses port 5176; prior port 5175 stays as-is because its input session is closed. API remains port 8000 with the prior process-only 5M/20M upload settings.

COU-TRIP-008 has automated implementation coverage; opening the map app and returning on the user's actual phone is still OPEN. COU-TRIP-001 remains partial: weight, scheduled departure and pay are not implemented/advertised by this change. Decline/reassignment, push, geofencing/background location and pay remain open. Do not mark these manual workbook cases passed from automated tests.

Next implementation gap in C3: reason-required trip decline with safe unassignment and retained history, while preserving accepted/loaded/dispatched trip ownership. Define the additive assignment history before changing reassignment rules. C1 rehearsal still requires finishing the demo Courier's active trip, going Off Duty and using actual documents for owning-Logistics review.
