/// A release build must talk to the production API over HTTPS.
///
/// The compiled-in default for API_BASE_URL is a local dev server over plain HTTP, so a release built without
/// `--dart-define-from-file=env/production.json` would otherwise ship pointing at localhost. Debug and profile
/// builds are not checked, so local development is unchanged.
library;

/// True for an `https://` URL with a real host, i.e. not a loopback or private-network development address.
bool isSecureApiBaseUrl(String url) {
  final uri = Uri.tryParse(url.trim());
  if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) return false;
  return !_isLocalHost(uri.host.toLowerCase());
}

bool _isLocalHost(String host) {
  if (host == 'localhost' || host == '::1' || host.endsWith('.local')) return true;
  if (host.startsWith('127.') || host.startsWith('10.') || host.startsWith('192.168.')) return true;
  // 172.16.0.0 - 172.31.255.255
  final parts = host.split('.');
  if (parts.length == 4 && parts[0] == '172') {
    final second = int.tryParse(parts[1]);
    if (second != null && second >= 16 && second <= 31) return true;
  }
  return false;
}

/// Stops a misconfigured release build at startup instead of letting it run against the wrong server.
void assertSecureApiForRelease({required bool isRelease, required String baseUrl}) {
  if (isRelease && !isSecureApiBaseUrl(baseUrl)) {
    throw StateError(
      'Release builds must use an https:// API_BASE_URL on a public host (got "$baseUrl"). '
      'Build with --dart-define-from-file=env/production.json.',
    );
  }
}
