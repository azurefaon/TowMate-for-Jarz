import 'package:flutter/material.dart';
import '../../core/legal_versions.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/legal_document_view.dart';

class PrivacyPolicyScreen extends StatefulWidget {
  const PrivacyPolicyScreen({super.key});

  @override
  State<PrivacyPolicyScreen> createState() => _PrivacyPolicyScreenState();
}

class _PrivacyPolicyScreenState extends State<PrivacyPolicyScreen> {
  bool _loading = true;
  String _versionLabel = 'Version ${LegalVersions.privacyVersion}';
  ParsedLegalDocument _document = parseLegalDocument(_fallbackContent);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final content = await ApiService.fetchCustomerContent();
    if (!mounted) return;

    final privacyPolicy = content?['privacy_policy'] as Map<String, dynamic>?;
    final body = privacyPolicy?['content'] as String?;
    final version = privacyPolicy?['version'] as String?;

    setState(() {
      if (body != null && body.trim().isNotEmpty) {
        _document = parseLegalDocument(body);
      }
      if (version != null && version.trim().isNotEmpty) {
        _versionLabel = 'Version $version';
      }
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return Scaffold(
        backgroundColor: context.bg,
        appBar: AppBar(
          backgroundColor: context.bg,
          elevation: 0,
          foregroundColor: context.textPrimary,
          title: const Text('Privacy Policy'),
        ),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return LegalDocumentScreen(
      title: 'Privacy Policy',
      versionLabel: _versionLabel,
      intro: _document.intro,
      sections: _document.sections,
    );
  }
}

const _fallbackContent = '''
This Privacy Policy explains what information TowMate collects from customers using the mobile app, how we use it, and who it may be shared with in order to provide towing and roadside assistance services.

## 1. Information You Provide
When you create an account, we collect your name, email address, and Philippine mobile number. If you register with a password, it is stored in a securely hashed form that TowMate cannot read back. If you sign up or sign in with Google, we receive your name, email address, and a Google account identifier from Google — we never see or store your Google password.

## 2. Booking and Vehicle Information
When you request a tow, we collect the pickup and drop-off addresses and coordinates you select, your chosen vehicle type, any notes you add for the towing team, and photos you upload of the vehicle being towed. This information is used to dispatch a Team Leader and calculate your quotation.

## 3. Location Information
If you use the "use current location" option when setting a pickup point, the app requests your device's current location with your permission, only to prefill that field. TowMate does not continuously track a customer's location in the background.

## 4. Booking History
We keep a record of your past and current bookings, including status, quotation, and completion details, so you can view them in the app and so we can provide support if needed.

## 5. Payment Information
If you choose to pay for a booking online, payment is processed through a third-party payment processor. TowMate does not store your full card or payment credentials — the payment processor handles that directly.

## 6. Security and Log Information
For account security, we keep records of certain account events (such as sign-in and registration activity), together with technical details like IP address and device/browser information, to help detect and prevent unauthorized access.

## 7. How We Use Your Information
We use the information above to create and secure your account, process your bookings, calculate quotations, communicate with you about your bookings (including via email, such as verification codes), and improve the reliability of the service.

## 8. Who We Share Information With
Booking and vehicle information relevant to your request (such as your name, phone number, and pickup/drop-off details) is shared with the Team Leader assigned to your booking so they can complete the service. We also work with service providers that help us operate the app, such as Google (for sign-in), a payment processor (for online payments), and an email delivery provider (for verification and account emails). We do not sell your personal information.

## 9. Data Retention
We keep your account and booking information for as long as your account is active, or as needed to provide the service, maintain accurate booking records, and meet our legal and business obligations.

## 10. Your Choices
You can review and update your profile information from within the app. If you would like your account removed or have questions about the information we hold about you, you can contact TowMate support through the app.

## 11. Changes to This Policy
We may update this Privacy Policy from time to time. When we do, we will update the version shown at the top of this page, and you may be asked to review and accept the updated Policy the next time you sign in.

## 12. Contact
If you have questions about this Privacy Policy or how your information is handled, you can reach TowMate support through the contact options available in the app.
''';
