// lib/data/repositories/cart_repository.dart
//
// The cart's server-side surface: the voucher catalogue.
//
// ## What is *not* here, and why that is the honest answer
//
// There is no `quote()` / `checkout()` method in this pass, even though a real
// cart is where totals get computed. That is a deliberate omission and it ties
// directly to the contract's OPEN QUESTIONS §0.1 and §0.2:
//
//   * **Shipping.** This app charges ₱60 (free at ₱1,000). Laravel charges
//     nothing, and `total = subtotal - discount` with no shipping term at all.
//     A `quote()` implemented now would have to pick a winner between two
//     systems that currently disagree, and whichever it picked would then be
//     wrong for the other. Guessing here means writing the same number in two
//     places and letting them drift.
//   * **Vouchers.** This app supports category-scoped, max-discount-capped and
//     shipping-rebate vouchers (`config/pricing.dart`). Laravel supports none
//     of those three. A `quote()` would silently downgrade FEESHIP and
//     NEWHOME15 to plain fixed/percent behaviour, which is a *money* bug that
//     looks like working code.
//
// So the voucher *catalogue* is exposed here (it is unambiguous: a list of
// vouchers with a code, a type, a value and a minimum spend), and the maths
// stays where it already is — `config/pricing.dart`, which is the one place
// that computes a total today, and which the checkout screen and the promo
// carousel both already read. `docs/api-contract.md` §4 specifies
// `POST /checkout/quote` as the endpoint that takes this over once §0.1 and
// §0.2 are answered.
//
// ## Local cart lines
//
// The line items themselves stay device state (`CartState` + shared_preferences)
// in this pass. They are not in this interface for the same reason as above:
// where a cart lives — device or server — is a decision that depends on the
// answer to guest-checkout, which nobody has asked yet. Moving them now would
// be a data migration for a question that is still open.

import '../api/api_exception.dart';
import '../mock_data.dart';
import 'live_stub.dart';

abstract class CartRepository {
  /// Vouchers the buyer can currently choose from.
  ///
  /// Empty means none are running. The mock's list is a fixed set of three; a
  /// live implementation would filter by `is_active` and `expires_at`, so a
  /// caller must not assume the list is short or static.
  Future<List<Voucher>> vouchers();
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockCartRepository implements CartRepository {
  const MockCartRepository();

  @override
  Future<List<Voucher>> vouchers() async => mockVouchers;
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveCartRepository extends LiveRepository implements CartRepository {
  LiveCartRepository(super.client);

  @override
  Future<List<Voucher>> vouchers() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /vouchers', section: '§4.1');
  }
}