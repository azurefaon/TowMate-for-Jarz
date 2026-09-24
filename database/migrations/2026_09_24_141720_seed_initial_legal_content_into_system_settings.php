<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (blank(SystemSetting::getValue('terms_of_use_content'))) {
            SystemSetting::setValue('terms_of_use_version', '1.0');
            SystemSetting::setValue('terms_of_use_content', $this->termsOfUseContent());
        }

        if (blank(SystemSetting::getValue('privacy_policy_content'))) {
            SystemSetting::setValue('privacy_policy_version', '1.0');
            SystemSetting::setValue('privacy_policy_content', $this->privacyPolicyContent());
        }
    }

    public function down(): void
    {
        //
    }

    private function termsOfUseContent(): string
    {
        return <<<'TEXT'
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
TEXT;
    }

    private function privacyPolicyContent(): string
    {
        return <<<'TEXT'
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
TEXT;
    }
};
