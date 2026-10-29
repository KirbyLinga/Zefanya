// lib/utils/product_link.dart
//
// Product share links and the parser that turns them back into a product id.
//
// Keep the URL shape aligned with the Laravel buyer route
// (`/buyer/products/{id}`, see web/routes/buyer.php). In mock mode there is no
// host to point at, so `zefanya.webBaseUrl` is a dart-define with a clearly
// non-production fallback; tests assert the path, not the host.

import '../config/dev_flags.dart';
import '../data/mock_data.dart' show Product;

const String _fallbackWebBase = 'https://zefanya.test';

/// Base URL of the web storefront used when the app is not installed.
///
/// Set at build time:
/// `--dart-define=zefanya.webBaseUrl=https://your-store.example`
const String kWebBaseUrl = String.fromEnvironment('zefanya.webBaseUrl');

String get productWebBaseUrl {
  if (kWebBaseUrl.trim().isNotEmpty) {
    return kWebBaseUrl.trim().replaceFirst(RegExp(r'/+$'), '');
  }
  // If a live API base was supplied, the web host is usually the same origin.
  // Strip the API suffix rather than hard-coding a second domain.
  final api = kApiBaseUrl.trim();
  if (api.isNotEmpty) {
    return api
        .replaceFirst(RegExp(r'/api/?$'), '')
        .replaceFirst(RegExp(r'/+$'), '');
  }
  return _fallbackWebBase;
}

String productShareUrl(Product product) =>
    '$productWebBaseUrl/buyer/products/${Uri.encodeComponent(product.id)}';

String productShareMessage(Product product, {String? price}) {
  final priceLine = price == null || price.isEmpty ? '' : ' — $price';
  return '${product.name}$priceLine\n'
      'Sold by ${product.sellerName} on Zefanya.\n'
      '${productShareUrl(product)}';
}

/// Extracts the product id from an inbound link, or null when [uri] is not a
/// product link.
///
/// Accepted shapes:
///   https://host/buyer/products/p2
///   https://host/products/p2
///   zefanya://product/p2
///   zefanya://buyer/products/p2
String? productIdFromLink(Uri uri) {
  final segments = [
    if (uri.host.isNotEmpty && uri.scheme == 'zefanya') uri.host,
    ...uri.pathSegments,
  ].where((segment) => segment.isNotEmpty).toList();

  final productsIndex = segments.lastIndexOf('products');
  if (productsIndex >= 0 && productsIndex + 1 < segments.length) {
    final id = segments[productsIndex + 1];
    return id.isEmpty ? null : Uri.decodeComponent(id);
  }

  // `zefanya://product/p2` puts "product" in the host, so the id is next.
  if (uri.scheme == 'zefanya' &&
      uri.host == 'product' &&
      uri.pathSegments.isNotEmpty) {
    return Uri.decodeComponent(uri.pathSegments.first);
  }
  return null;
}
