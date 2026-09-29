// test/product_image_cache_test.dart
//
// ProductImage used to decode a 400x400 source for every slot it appeared in,
// including 56–72px thumbnails — ~640KB of decoded bitmap per mounted tile,
// held in the app's image cache, to paint a square the size of a fingernail.
// The source is now 1000px (a product page wants the detail), which makes the
// gap between what a thumbnail needs and what it would otherwise decode worse,
// not better.
//
// The fix measures the slot instead of asking call sites to declare it, which
// means the *mechanism* is what needs pinning: a ResizeImage carrying the
// slot's pixel size for small slots, and a plain NetworkImage (no resize) once
// the requested size reaches the source, so no second cache entry is created
// for a bitmap that would be identical.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';

Future<Image> _pumpProductImage(
  WidgetTester tester, {
  required double slot,
  double ratio = 3.0,
  int photoIndex = 0,
}) async {
  tester.view.devicePixelRatio = ratio;
  tester.view.physicalSize = const Size(1200, 1200);
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      home: Center(
        child: SizedBox(
          width: slot,
          height: slot,
          child: ProductImage(
            product: mockProducts.first,
            photoIndex: photoIndex,
          ),
        ),
      ),
    ),
  );
  await tester.pump();

  return tester.widget<Image>(find.byType(Image));
}

String _urlOf(Image image) {
  final provider = image.image is ResizeImage
      ? (image.image as ResizeImage).imageProvider as NetworkImage
      : image.image as NetworkImage;
  return provider.url;
}

void main() {
  testWidgets('a 72pt slot at 3x asks for 216px, not the full source',
      (tester) async {
    final image = await _pumpProductImage(tester, slot: 72);

    expect(image.image, isA<ResizeImage>(),
        reason: 'a thumbnail should not decode a 1000px bitmap');
    expect((image.image as ResizeImage).width, 216);
    expect((image.image as ResizeImage).height, 216);
  });

  testWidgets('a 124pt rail tile asks for 372px', (tester) async {
    final image = await _pumpProductImage(tester, slot: 124);
    expect((image.image as ResizeImage).width, 372);
  });

  testWidgets('a full-bleed slot decodes natively instead of upscaling',
      (tester) async {
    // 560pt at 3x is 1680px — more than the source has, so the request clamps
    // to the source and the resize is skipped rather than added as a second
    // cache entry for an identical bitmap.
    final image = await _pumpProductImage(tester, slot: 560);

    expect(image.image, isA<NetworkImage>());
    expect(image.image, isNot(isA<ResizeImage>()));
  });

  testWidgets('the URL still points at the product\'s cover', (tester) async {
    final image = await _pumpProductImage(tester, slot: 72);

    // Cache dimensions are a decode hint; the asset itself must not change.
    expect(_urlOf(image), placeholderPhoto(mockProducts.first.id, 0));
  });

  // The gallery needs one image widget to be able to render *any* photo of a
  // product, not just its cover — that is what photoIndex exists for, and the
  // authority on the URL is Product.photos rather than a template assembled in
  // the widget.
  testWidgets('photoIndex renders a photo other than the cover', (tester) async {
    final product = mockProducts.first;
    expect(product.hasGallery, isTrue,
        reason: 'this test is meaningless on a one-photo product');

    final cover = await _pumpProductImage(tester, slot: 72);
    final second = await _pumpProductImage(tester, slot: 72, photoIndex: 1);

    expect(_urlOf(second), product.photos[1]);
    expect(_urlOf(second), isNot(_urlOf(cover)));
  });

  testWidgets('an out-of-range photoIndex clamps to the cover, not an error',
      (tester) async {
    // Driven by a page index, so out-of-range is a real possibility; an image
    // that repeats is a far better outcome than a thrown exception.
    final image = await _pumpProductImage(tester, slot: 72, photoIndex: 99);
    expect(_urlOf(image), placeholderPhoto(mockProducts.first.id, 0));
  });
}