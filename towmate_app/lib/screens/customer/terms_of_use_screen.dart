import 'package:flutter/material.dart';
import '../../core/legal_versions.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/legal_document_view.dart';

class TermsOfUseScreen extends StatefulWidget {
  const TermsOfUseScreen({super.key});

  @override
  State<TermsOfUseScreen> createState() => _TermsOfUseScreenState();
}

class _TermsOfUseScreenState extends State<TermsOfUseScreen> {
  bool _loading = true;
  String _versionLabel = 'Version ${LegalVersions.termsVersion}';
  ParsedLegalDocument _document = parseLegalDocument(_fallbackContent);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final content = await ApiService.fetchCustomerContent();
    if (!mounted) return;

    final termsOfUse = content?['terms_of_use'] as Map<String, dynamic>?;
    final body = termsOfUse?['content'] as String?;
    final version = termsOfUse?['version'] as String?;

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
          title: const Text('Terms of Use'),
        ),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return LegalDocumentScreen(
      title: 'Terms of Use',
      versionLabel: _versionLabel,
      intro: _document.intro,
      sections: _document.sections,
    );
  }
}

const _fallbackContent = '''
These Terms of Use ("Terms") govern your access to and use of the TowMate customer mobile app, operated by JARZ Towing Services ("TowMate", "we", "us"). By creating an account or using the app, you agree to these Terms.

## 1. Account Registration
To request towing or roadside assistance through TowMate, you must create an account with your name, a valid email address, a Philippine mobile number, and a password, or by signing in with your Google account. You must provide accurate, current, and complete information when registering and when updating your profile.

## 2. Account Security
You are responsible for keeping your password confidential and for all activity that happens under your account. Tell us immediately if you believe your account has been accessed without your permission. TowMate stores your password in a securely hashed form and never displays it back to you.

## 3. Booking Accuracy
When you request a tow, you agree to provide accurate pickup and drop-off locations, vehicle type, and any photos we ask for so that a Team Leader can be dispatched correctly. Inaccurate information may delay your service or affect the quotation you receive.

## 4. Towing Service Requests
You can request a tow for immediate dispatch ("Book Now") or schedule one for a later date and time, subject to unit availability. TowMate does not guarantee a specific arrival time; estimates shown in the app are best-effort only.

## 5. Quotations
Before a booking is confirmed, TowMate provides a price quotation based on distance, vehicle type, and the rates configured for the requested service. Reviewing and accepting a quotation is part of confirming your booking through the app.

## 6. Cancellations and Changes
You may cancel an active booking from the app before it is completed. Repeated or abusive cancellation behavior may affect your ability to use TowMate. Specific cancellation terms, fees, or timing windows, if any, will be communicated to you at the time of booking and are not fixed by these Terms.

## 7. Customer Responsibilities
You agree to be present or reachable at the pickup location you provide, to cooperate reasonably with the assigned Team Leader, and to use TowMate only for legitimate towing and roadside assistance requests.

## 8. Service Availability
Towing services depend on the availability of units and personnel in your area at the time of your request. TowMate may be temporarily unable to accept new "Book Now" requests and will indicate this in the app when it applies.

## 9. Prohibited Use
You agree not to misuse the app, including submitting false booking requests, attempting to access another customer's account or data, interfering with the normal operation of the service, or using the app for any unlawful purpose.

## 10. Suspension and Termination
We may suspend or deactivate an account that violates these Terms, is used fraudulently, or is inactive for an extended period consistent with our account-security practices. You may stop using TowMate at any time.

## 11. Changes to These Terms
We may update these Terms from time to time. When we do, we will update the version shown at the top of this page, and you may be asked to review and accept the updated Terms the next time you sign in.

## 12. Contact
If you have questions about these Terms, you can reach TowMate support through the contact options available in the app.
''';
