// lib/data/repositories/courier_repository.dart
//
// Where a courier's assigned jobs come from.
//
// ## Scope, stated precisely, because the boundary is unusual
//
// This is the *narrowest* of the seven repositories, and deliberately so. It
// supplies job data and nothing else; the milestone state machine, the
// completion timestamps and the payout ledger all stay in `CourierState`.
//
// That is not laziness — it is a claim about which side owns what, and it
// matters that the claim is explicit:
//
//   * **Jobs** are server data. A dispatcher at the Logistics/Sorting Center
//     assigns them (per the ERP spec), so the app cannot invent one. This is
//     what the interface below covers.
//   * **Milestones and payouts** are *currently simulated on the device*, and
//     that is a mock-mode behaviour, not a design. The real system records
//     milestones server-side (the buyer's tracker reads them too, through
//     different credentials) and pays out server-side. `docs/api-contract.md`
//     §6 specifies the endpoints that will replace this.
//
// Moving the milestone machine into this repository today would mean rewriting
// a state class whose behaviour is pinned by tests — the partial return fee,
// the legacy-compatible persisted format, the "identity only at dispatch"
// guard — for no gain, because the server side of it does not exist to talk to.
// The seam that matters right now is the one that stops `CourierState`
// importing `mock_data`.

import '../api/api_exception.dart';
import '../mock_data.dart';
import 'live_stub.dart';

abstract class CourierRepository {
  /// Jobs the dispatcher has assigned to the signed-in courier and which are
  /// not finished.
  ///
  /// Includes the pickup leg and the delivery leg; `CourierState` splits them
  /// for the two dashboards, because that split is a UI decision, not a query.
  ///
  /// Empty means "nothing assigned", which is a real and common state —
  /// a courier between shifts. A failure throws, so the dashboard can tell
  /// "no jobs today" apart from "we couldn't ask".
  Future<List<DeliveryJob>> assignedJobs();
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockCourierRepository implements CourierRepository {
  const MockCourierRepository();

  @override
  Future<List<DeliveryJob>> assignedJobs() async => mockDeliveryJobs;
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveCourierRepository extends LiveRepository implements CourierRepository {
  LiveCourierRepository(super.client);

  @override
  Future<List<DeliveryJob>> assignedJobs() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /courier/jobs', section: '§6.1');
  }
}