// test/repository_wiring_test.dart
//
// The claim this whole pass rests on: a state class cannot tell whether it is
// talking to a mock or a live data source, because it only ever talks to the
// repository it was handed.
//
// The gap these cover: before the move, `ChatState`, `ReviewState`,
// `CourierState` and `CartState` *were* the store — a second copy of the same
// data, which meant "the reviews I wrote" and "the reviews the data layer has"
// could disagree, and a live build could not be wired at all. Each test here
// hands a state a repository the mock could never produce and asserts the state
// reports *that* answer, which fails the moment a state goes back to reading its
// own storage or `mock_data`.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart'
    show DeliveryJob, Order, OrderStatus, Voucher;
import 'package:zefanya/data/repositories/cart_repository.dart';
import 'package:zefanya/data/repositories/chat_repository.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/data/repositories/review_repository.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/review_state.dart';

/// A courier repository returning one job no mock seeds.
class _OneJobCourierRepository implements CourierRepository {
  _OneJobCourierRepository(this.job);

  final DeliveryJob job;

  @override
  Future<List<DeliveryJob>> assignedJobs() async => [job];

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
  }) async {}
}

/// A voucher catalogue of one, so a screen reading `mockVouchers` would fail.
class _OneVoucherCartRepository implements CartRepository {
  _OneVoucherCartRepository(this.offer);

  final List<Voucher> offer;

  @override
  Future<List<Voucher>> vouchers() async => offer;
}

/// Reviews that live only in the repository.
class _GivenReviewRepository implements ReviewRepository {
  _GivenReviewRepository(this.reviews);

  final List<ProductReview> reviews;

  @override
  Future<void> load() async {}

  @override
  Future<List<ProductReview>> all() async => List.unmodifiable(reviews);

  @override
  Future<void> submit(ProductReview review) async => reviews.insert(0, review);
}

/// Threads that live only in the repository.
class _GivenChatRepository implements ChatRepository {
  _GivenChatRepository(this.stored);

  final Map<String, List<ChatMessage>> stored;

  @override
  Future<void> load() async {}

  @override
  Future<List<ChatMessage>> messagesFor(String contactKey) async =>
      List.unmodifiable(stored[contactKey] ?? const []);

  @override
  Future<Map<String, List<ChatMessage>>> threads() async => stored;

  @override
  Future<void> append(String contactKey, ChatMessage message) async =>
      stored.putIfAbsent(contactKey, () => []).add(message);

  @override
  Future<void> seedIfEmpty(String contactKey, ChatMessage greeting) async {
    if (stored.containsKey(contactKey)) return;
    stored[contactKey] = [greeting];
  }
}

final _job = DeliveryJob(
  id: 'REQ-9001',
  shopName: 'Repository Only Atelier',
  pickupAddress: '1 Wired St.',
  buyerName: 'Ana',
  deliveryArea: 'Makati City',
  packageSize: 'Small parcel',
  fee: 99,
);

final _order = Order(
  id: 'ORD-10250',
  shopName: 'Repository Only Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('the courier queue is the repository\'s, not the seeded samples',
      () async {
    final courier = CourierState(repository: _OneJobCourierRepository(_job));
    await courier.load();

    expect(courier.allJobs.map((j) => j.id), contains('REQ-9001'));
    // The four sample jobs mock mode seeds must NOT be here: this state was
    // handed a different dispatcher, and reading `mockDeliveryJobs` anyway is
    // exactly the duplicate source of truth this pass removes.
    expect(courier.allJobs.map((j) => j.id), isNot(contains('REQ-5521')));
    expect(courier.jobsForPickup.single.job.id, 'REQ-9001');
  });

  test('a dispatched job takes the pickup address from the repository\'s jobs '
      'for that shop', () async {
    final courier = CourierState(repository: _OneJobCourierRepository(_job));
    await courier.load();

    // The shop matches the repository's job, so there is a real address to show
    // rather than the "shared once the seller confirms" fallback — which is
    // correct, but only necessary when nothing knows the seller's address.
    courier.dispatchForOrder(
      order: _order,
      buyerName: 'Ana',
      deliveryArea: 'Makati City',
      fee: 99,
    );

    expect(courier.jobForOrder('ORD-10250')!.pickupAddress, '1 Wired St.');
  });

  test('the voucher catalogue is the repository\'s, not the constant list',
      () async {
    final cart = CartState(
      vouchers: _OneVoucherCartRepository([
        const Voucher(
          code: 'WIRED10',
          title: 'Wired only',
          discountLabel: '10% off',
          minSpend: 0,
          expiryLabel: 'Forever',
        ),
      ]),
    );
    await cart.load();

    expect(cart.vouchers.map((v) => v.code), ['WIRED10']);
  });

  test('written reviews are the repository\'s, not this state\'s own store',
      () async {
    final reviews = ReviewState(
      repository: _GivenReviewRepository([
        ProductReview(
          productId: 'p1',
          orderId: 'ORD-10200',
          stars: 5,
          comment: 'From the repository.',
          createdAt: DateTime(2026, 1, 2, 10, 4, 2),
        ),
      ]),
    );
    await reviews.load();

    expect(reviews.all.single.comment, 'From the repository.');
    expect(reviews.forProduct('p1').single.orderId, 'ORD-10200');
    expect(reviews.hasReviewedOrder('ORD-10200'), isTrue);
  });

  test('threads come back from the repository before any is opened', () async {
    final chat = ChatState(
      repository: _GivenChatRepository({
        'Repository Only Atelier': const [
          ChatMessage(text: 'Salamat!', isMe: false, time: '10:02 AM'),
        ],
      }),
    );
    await chat.load();

    // The point of warming the cache: a thread that has never been opened in
    // *this* session still reads as what was said, not as no history.
    expect(chat.messagesFor('Repository Only Atelier').single.text, 'Salamat!');
    expect(chat.messagesFor('Nobody At All'), isEmpty);
  });

  test('a live bundle fails loudly instead of quietly using mock data',
      () async {
    final repositories = Repositories.forMode(
      useLive: true,
      baseUrl: 'https://api.example.test',
    );
    // Asserted through the catalogue rather than the order history, and that is not
    // incidental: the catalogue is a *public* read, so it reaches the network without
    // reading the token store — which in a unit test has no binding and no secure
    // storage behind it. Pointing this rule at an authenticated route would test the
    // platform channel rather than the rule.
    //
    // The rule is the same on either: a live build that cannot reach its API must
    // say so and stay **empty**, never fall back to the mock and look like it worked.
    final catalog = CatalogState(repository: repositories.catalog);

    await catalog.load();

    expect(catalog.products, isEmpty, reason: 'a live build must never fall back to mock data');

    // *Which* failure is the environment's business — an unreachable test host, or a
    // build with no base URL at all. What matters is that the failure is named rather
    // than the live answer being quietly replaced by the mock's.
    expect(catalog.loadFailure, isA<ApiException>());
  });

  test('a live bundle with no base URL says that, not "network error"',
      () async {
    final repositories = Repositories.forMode(useLive: true, baseUrl: '');

    // A configuration failure is a different problem with a different owner
    // (a developer, not the person holding the phone), so it gets its own type
    // rather than reading as "couldn't reach the server".
    await expectLater(
      OrderState(repository: repositories.order).load(),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });
}