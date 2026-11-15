// lib/data/api/backend_scope.dart
//
// Keeps one backend's saved state out of another's.
//
// The bearer token (and the rider's unconfirmed-delivery record) used to live
// under one fixed key, so an installation switched between the demo fork and
// WebZefanya, or pointed at a different host, would hand the previous
// backend's credential to the new one. These helpers namespace the storage key
// by backend profile **and** canonical API origin + base path.
//
// An old, unscoped token is deliberately never imported: it cannot be shown to
// belong to the host now configured, so the person signs in again.

import '../../config/backend_profile.dart';
import 'token_store.dart';

/// `profile|scheme://host:port/path`, lower-cased where case does not matter,
/// with the default port made explicit and no trailing slash.
String backendScope(BackendProfile profile, String baseUrl) {
  final uri = Uri.tryParse(baseUrl.trim());
  if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
    return '${profile.wireName}|unconfigured';
  }
  final scheme = uri.scheme.toLowerCase();
  final port = uri.hasPort ? uri.port : (scheme == 'https' ? 443 : 80);
  var path = uri.path;
  while (path.length > 1 && path.endsWith('/')) {
    path = path.substring(0, path.length - 1);
  }
  if (path == '/') path = '';
  return '${profile.wireName}|$scheme://${uri.host.toLowerCase()}:$port$path';
}

/// [base] namespaced for [profile] and [baseUrl].
String scopedStorageKey(String base, BackendProfile profile, String baseUrl) =>
    '$base.${Uri.encodeComponent(backendScope(profile, baseUrl))}';

/// The default bearer-token store for a backend: its own key, never shared.
TokenStore scopedTokenStore(BackendProfile profile, String baseUrl) =>
    SecureTokenStore(
      storageKey: scopedStorageKey('zefanya.bearer_token', profile, baseUrl),
    );

/// The rider's persisted delivery-completion record, scoped the same way.
TokenStore scopedRiderCompletionStore(BackendProfile profile, String baseUrl) =>
    SecureTokenStore(
      storageKey: scopedStorageKey(
        'zefanya.rider_delivery_completion',
        profile,
        baseUrl,
      ),
    );
