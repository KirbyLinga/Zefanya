// test/remote_status_mapping_test.dart
//
// The 13 server statuses -> the app's two enums.
//
// Three properties matter, and they are different kinds of assertion:
//
//  1. **Coverage.** Every value the contract lists parses to a real enum
//     member — not to `unknown`. A typo in the wire table would otherwise make
//     a status silently vanish into the fail-safe branch, which looks like
//     working code.
//  2. **Every mapping is defined.** Each of the 13 (and `unknown`) has a
//     deliberate buyer state and courier milestone-or-null. This is what stops
//     "we'll decide that one later" from shipping.
//  3. **Failure is safe.** Unknown, malformed, missing and mistyped input must
//     produce a value, never an exception. `values.byName` — the obvious
//     implementation — throws, and the server list is allowed to grow.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/dto/remote_status.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';

void main() {
  group('parse covers the contract', () {
    test('there are exactly 13 wire statuses', () {
      expect(RemoteOrderStatus.allWireValues, hasLength(13));
    });

    test('every wire value parses to a real status, not to unknown', () {
      for (final wire in RemoteOrderStatus.allWireValues) {
        final parsed = RemoteOrderStatus.parse(wire);
        expect(parsed, isNot(RemoteOrderStatus.unknown),
            reason: '$wire should be recognised');
        expect(parsed.wire, wire, reason: '$wire should round-trip');
      }
    });

    test('every enum member except unknown has a wire value in the set', () {
      for (final status in RemoteOrderStatus.values) {
        if (status == RemoteOrderStatus.unknown) continue;
        expect(RemoteOrderStatus.allWireValues, contains(status.wire),
            reason: '${status.name} is missing from allWireValues');
      }
    });

    test('allWireValues contains no duplicates and none is "UNKNOWN"', () {
      expect(RemoteOrderStatus.allWireValues.toSet(), hasLength(13));
      expect(RemoteOrderStatus.allWireValues, isNot(contains('UNKNOWN')));
    });
  });

  group('parse tolerates the shapes a server actually sends', () {
    test('accepts the camelCase case name', () {
      expect(RemoteOrderStatus.parse('deliveryFailed'),
          RemoteOrderStatus.deliveryFailed);
      expect(RemoteOrderStatus.parse('atSortingCenter'),
          RemoteOrderStatus.atSortingCenter);
    });

    test('accepts stray whitespace and hyphens', () {
      expect(RemoteOrderStatus.parse('  DELIVERED  '), RemoteOrderStatus.delivered);
      expect(RemoteOrderStatus.parse('delivery-failed'),
          RemoteOrderStatus.deliveryFailed);
      expect(RemoteOrderStatus.parse('out-for-delivery'),
          RemoteOrderStatus.outForDelivery);
    });
  });

  group('parse fails safe rather than throwing', () {
    test('an unrecognised string becomes unknown', () {
      expect(RemoteOrderStatus.parse('AWAITING_PICKUP'),
          RemoteOrderStatus.unknown,
          reason: 'a status added server-side must not crash this build');
    });

    test('non-string input becomes unknown', () {
      expect(RemoteOrderStatus.parse(null), RemoteOrderStatus.unknown);
      expect(RemoteOrderStatus.parse(42), RemoteOrderStatus.unknown);
      expect(RemoteOrderStatus.parse(true), RemoteOrderStatus.unknown);
      expect(RemoteOrderStatus.parse(<String, Object?>{}), RemoteOrderStatus.unknown);
    });

    test('empty and near-empty input becomes unknown', () {
      expect(RemoteOrderStatus.parse(''), RemoteOrderStatus.unknown);
      expect(RemoteOrderStatus.parse('   '), RemoteOrderStatus.unknown);
      expect(RemoteOrderStatus.parse('_'), RemoteOrderStatus.unknown);
    });

    test('nothing in the parse path throws for any of the above', () {
      // Belt and braces on the word "never" above: this is the assertion that
      // would fail if someone replaced the loop with `values.byName`.
      for (final bad in <Object?>[null, '', 'nope', 0, 1.5, [], {}]) {
        expect(() => RemoteOrderStatus.parse(bad), returnsNormally);
      }
    });
  });

  group('buyer mapping', () {
    test('every status maps to a buyer state — no nulls, no gaps', () {
      for (final status in RemoteOrderStatus.values) {
        expect(() => buyerStatusFor(status), returnsNormally,
            reason: '${status.name} has no buyer status');
      }
    });

    test('seller-side stages are all "to ship"', () {
      for (final status in [
        RemoteOrderStatus.placed,
        RemoteOrderStatus.confirmed,
        RemoteOrderStatus.preparing,
        RemoteOrderStatus.readyForPickup,
      ]) {
        expect(buyerStatusFor(status), OrderStatus.toShip, reason: status.name);
      }
    });

    test('movement through the network is "in transit"', () {
      for (final status in [
        RemoteOrderStatus.pickedUp,
        RemoteOrderStatus.atSortingCenter,
        RemoteOrderStatus.sorted,
      ]) {
        expect(buyerStatusFor(status), OrderStatus.inTransit, reason: status.name);
      }
    });

    test('the last leg is "out for delivery"', () {
      for (final status in [
        RemoteOrderStatus.assignedToRider,
        RemoteOrderStatus.outForDelivery,
      ]) {
        expect(buyerStatusFor(status), OrderStatus.outForDelivery,
            reason: status.name);
      }
    });

    test('a failed attempt stays "out for delivery" — a retry is coming', () {
      expect(buyerStatusFor(RemoteOrderStatus.deliveryFailed),
          OrderStatus.outForDelivery);
    });

    test('delivered and completed both become the reviewable state', () {
      expect(buyerStatusFor(RemoteOrderStatus.delivered), OrderStatus.toRate);
      expect(buyerStatusFor(RemoteOrderStatus.completed), OrderStatus.toRate);
    });

    test('a return is "in transit" — moving, but not towards the buyer', () {
      // Deliberately not outForDelivery: that would read as good news.
      expect(buyerStatusFor(RemoteOrderStatus.returned), OrderStatus.inTransit);
    });

    test('unknown under-promises rather than raising alarm', () {
      expect(buyerStatusFor(RemoteOrderStatus.unknown), OrderStatus.toShip);
    });
  });

  group('courier mapping', () {
    test('pre-rider stages map to no milestone at all', () {
      for (final status in [
        RemoteOrderStatus.placed,
        RemoteOrderStatus.confirmed,
        RemoteOrderStatus.preparing,
      ]) {
        expect(courierMilestoneFor(status), isNull,
            reason: '${status.name} is the seller\'s stage, not the courier\'s');
      }
    });

    test('every courier-leg stage maps to the matching milestone', () {
      expect(courierMilestoneFor(RemoteOrderStatus.readyForPickup),
          DeliveryMilestone.accepted);
      expect(courierMilestoneFor(RemoteOrderStatus.pickedUp),
          DeliveryMilestone.pickedUp);
      expect(courierMilestoneFor(RemoteOrderStatus.atSortingCenter),
          DeliveryMilestone.atSortingCenter);
      expect(courierMilestoneFor(RemoteOrderStatus.sorted),
          DeliveryMilestone.sorted);
      expect(courierMilestoneFor(RemoteOrderStatus.assignedToRider),
          DeliveryMilestone.assignedToRider);
      expect(courierMilestoneFor(RemoteOrderStatus.outForDelivery),
          DeliveryMilestone.inTransit);
    });

    test('terminal and failure stages map to their milestones', () {
      expect(courierMilestoneFor(RemoteOrderStatus.delivered),
          DeliveryMilestone.delivered);
      expect(courierMilestoneFor(RemoteOrderStatus.completed),
          DeliveryMilestone.delivered);
      expect(courierMilestoneFor(RemoteOrderStatus.deliveryFailed),
          DeliveryMilestone.failedDelivery);
      expect(courierMilestoneFor(RemoteOrderStatus.returned),
          DeliveryMilestone.returnToSender);
    });

    test('documented gap: arrivedAtSeller is unreachable from the wire', () {
      // Not a bug being asserted as correct — a gap being *pinned* so it can't
      // be forgotten. The server's 13 statuses have no "rider is at the shop"
      // state, so no input produces this milestone. If the Laravel side adds
      // one, this test fails and points whoever added it at the mapping.
      final reachable = <DeliveryMilestone?>{
        for (final s in RemoteOrderStatus.values) courierMilestoneFor(s),
      };
      expect(reachable, isNot(contains(DeliveryMilestone.arrivedAtSeller)),
          reason: 'if this now passes, map the new server status and delete '
              'this test');
    });

    test('unknown produces no milestone rather than a guess', () {
      expect(courierMilestoneFor(RemoteOrderStatus.unknown), isNull,
          reason: 'a guessed milestone would put a job on a courier dashboard '
              'in a state nobody requested');
    });
  });

  group('wire convenience wrappers', () {
    test('buyerStatusForWire maps a raw JSON value', () {
      expect(buyerStatusForWire('PICKED_UP'), OrderStatus.inTransit);
      expect(buyerStatusForWire(null), OrderStatus.toShip);
    });

    test('courierMilestoneForWire maps a raw JSON value', () {
      expect(courierMilestoneForWire('OUT_FOR_DELIVERY'),
          DeliveryMilestone.inTransit);
      expect(courierMilestoneForWire('nonsense'), isNull);
    });
  });

  group('eta copy', () {
    test('every status has a line', () {
      for (final status in RemoteOrderStatus.values) {
        expect(buyerEtaFor(status), isNotEmpty, reason: status.name);
      }
    });

    test('the failure outcomes are described differently from each other', () {
      expect(buyerEtaFor(RemoteOrderStatus.deliveryFailed),
          isNot(buyerEtaFor(RemoteOrderStatus.returned)));
    });
  });

  group('status predicates', () {
    test('isFailure covers both bad outcomes and nothing else', () {
      final failing = [
        for (final s in RemoteOrderStatus.values)
          if (s.isFailure) s,
      ];
      expect(failing, [
        RemoteOrderStatus.deliveryFailed,
        RemoteOrderStatus.returned,
      ]);
    });

    test('isTerminal covers the three finished states', () {
      final terminal = [
        for (final s in RemoteOrderStatus.values)
          if (s.isTerminal) s,
      ];
      expect(terminal, [
        RemoteOrderStatus.delivered,
        RemoteOrderStatus.completed,
        RemoteOrderStatus.returned,
      ]);
    });
  });

  group('registration status mapping', () {
    test('the four contract values all round-trip', () {
      expect(RemoteRegistrationStatus.allWireValues, hasLength(4));
      for (final wire in RemoteRegistrationStatus.allWireValues) {
        final parsed = RemoteRegistrationStatus.parse(wire);
        expect(parsed, isNot(RemoteRegistrationStatus.unknown),
            reason: '$wire should be recognised');
        expect(parsed.wire, wire);
      }
    });

    test('both pending gates route to the pending screen', () {
      expect(accountStatusFor(RemoteRegistrationStatus.pendingVerification),
          AccountStatus.pendingApproval);
      expect(accountStatusFor(RemoteRegistrationStatus.pendingApproval),
          AccountStatus.pendingApproval);
    });

    test('only the email gate asks the person to act', () {
      expect(awaitsEmailVerification(RemoteRegistrationStatus.pendingVerification),
          isTrue);
      expect(awaitsEmailVerification(RemoteRegistrationStatus.pendingApproval),
          isFalse,
          reason: 'an admin review is not something the person can hurry along');
    });

    test('approved becomes active, rejected falls back to login', () {
      expect(accountStatusFor(RemoteRegistrationStatus.approved),
          AccountStatus.active);
      expect(accountStatusFor(RemoteRegistrationStatus.rejected),
          AccountStatus.loggedOut);
    });

    test('an unknown registration status never grants access', () {
      // The one direction that cannot be walked back. An unrecognised value
      // means approval is not established, so the fail-safe is "keep waiting",
      // never "let them in".
      expect(RemoteRegistrationStatus.parse('quarantined'),
          RemoteRegistrationStatus.unknown);
      expect(accountStatusFor(RemoteRegistrationStatus.unknown),
          AccountStatus.pendingApproval);
      expect(accountStatusFor(RemoteRegistrationStatus.unknown),
          isNot(AccountStatus.active));
    });

    test('parse does not throw on anything', () {
      for (final bad in <Object?>[null, '', 'NOPE', 1, 2.5, [], {}]) {
        expect(() => RemoteRegistrationStatus.parse(bad), returnsNormally);
      }
    });
  });
}