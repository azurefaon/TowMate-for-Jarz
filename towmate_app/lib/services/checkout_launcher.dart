import 'package:url_launcher/url_launcher.dart';

/// How a hosted checkout page ended up being shown.
enum CheckoutLaunchResult {
  /// Android Custom Tabs (iOS: Safari View Controller): the user's real browser, in-app, with its own
  /// address bar, TLS indicator, saved sessions and the ability to hand off to the GCash app.
  secureInAppBrowser,

  /// The system browser. Used when the device has no Custom Tabs provider.
  systemBrowser,

  /// The link was refused before anything was opened (not https, or not a PayMongo address).
  rejected,

  /// The OS could not open it.
  failed,
}

/// Opens PayMongo's hosted checkout. Abstract so tests never touch a platform channel.
abstract class CheckoutLauncher {
  Future<CheckoutLaunchResult> open(String checkoutUrl);
}

/// The production launcher.
///
/// Why Custom Tabs / Safari View Controller and not a WebView: the hosted page is PayMongo's, and it hands off to
/// the GCash app. A system browser surface keeps the address bar and certificate indicator visible, shares the
/// browser's security updates, never exposes a JavaScript bridge to the page, and lets the OS route the GCash app
/// link. TowMate never sees, hosts or intercepts what the customer types there (no PIN, OTP or card data).
///
/// Careful: on Android, `LaunchMode.inAppBrowserView` silently FALLS BACK TO AN EMBEDDED WEBVIEW when the Custom Tab
/// cannot start (url_launcher_android's `openUrlInApp`). That fallback is unacceptable for a payment page, so
/// Custom Tabs are used only after `supportsLaunchMode` confirms a provider exists, and the alternative is the
/// plain system browser. `LaunchMode.inAppWebView` is never used.
class UrlLauncherCheckoutLauncher implements CheckoutLauncher {
  const UrlLauncherCheckoutLauncher();

  /// Only these domains (and their subdomains) may ever be opened as a checkout.
  static const trustedDomains = ['paymongo.com'];

  /// https, no embedded credentials, default port, and a trusted PayMongo host.
  static bool isTrustedCheckoutUri(Uri? uri) {
    if (uri == null || uri.scheme != 'https' || uri.userInfo.isNotEmpty) return false;
    if (uri.hasPort && uri.port != 443) return false;

    final host = uri.host.toLowerCase();
    return trustedDomains.any((domain) => host == domain || host.endsWith('.$domain'));
  }

  @override
  Future<CheckoutLaunchResult> open(String checkoutUrl) async {
    final uri = Uri.tryParse(checkoutUrl);

    if (!isTrustedCheckoutUri(uri)) return CheckoutLaunchResult.rejected;

    try {
      if (await supportsLaunchMode(LaunchMode.inAppBrowserView)) {
        final opened = await launchUrl(
          uri!,
          mode: LaunchMode.inAppBrowserView,
          browserConfiguration: const BrowserConfiguration(showTitle: true),
        );

        return opened ? CheckoutLaunchResult.secureInAppBrowser : CheckoutLaunchResult.failed;
      }

      final opened = await launchUrl(uri!, mode: LaunchMode.externalApplication);

      return opened ? CheckoutLaunchResult.systemBrowser : CheckoutLaunchResult.failed;
    } catch (_) {
      return CheckoutLaunchResult.failed;
    }
  }
}
