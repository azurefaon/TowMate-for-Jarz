import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/about_screen.dart').readAsStringSync();
  });

  group('about screen polish invariants', () {
    test('About image still reads from the real CMS about.image_url field', () {
      expect(source.contains("_content?['about']?['image_url'] as String?"), isTrue);
      expect(source.contains('_AboutHeader(imageUrl: aboutImageUrl)'), isTrue);
    });

    test('Mission remains sourced from the existing about.text field with its fallback preserved', () {
      expect(source.contains("_content?['about']?['text'] as String?"), isTrue);
      expect(
        source.contains(
          'To provide fast, transparent, and professional towing and roadside assistance to every driver in the Philippines.',
        ),
        isTrue,
      );
    });

    test('Why Choose Us content/source is unchanged', () {
      expect(source.contains("'Professional Team'"), isTrue);
      expect(source.contains("'Modern Fleet'"), isTrue);
      expect(source.contains("'Reliable Assistance'"), isTrue);
      expect(source.contains("'Local Service Coverage'"), isTrue);
    });

    test('How It Works remains CMS-driven and preserves backend order, no client-side sort', () {
      expect(source.contains("(_content?['how_it_works'] as List<dynamic>?) ?? []"), isTrue);
      expect(source.contains('.sort('), isFalse);
      expect(source.contains('.shuffle('), isFalse);
    });

    test('Coverage remains CMS-driven', () {
      expect(source.contains("(_content?['coverage_areas'] as List<dynamic>?) ?? []"), isTrue);
    });

    test('no fabricated coverage locations beyond the existing established fallback are introduced', () {
      final fallbackMatch = RegExp(
        r'static const _fallback = \[\s*(.*?)\s*\];',
        dotAll: true,
      ).firstMatch(source.split('_CoverageSection').last);
      expect(fallbackMatch, isNotNull);
      final fallbackAreas = fallbackMatch!.group(1)!;
      expect(fallbackAreas.contains('Metro Manila'), isTrue);
      expect(fallbackAreas.contains('Rizal & Cavite'), isTrue);
      expect(fallbackAreas.contains('Bulacan & Laguna'), isTrue);

      const neverIntroduced = ['Quezon', 'Pampanga', 'Batangas', 'Cebu', 'Davao'];
      for (final area in neverIntroduced) {
        expect(source.contains(area), isFalse, reason: '"$area" must not be introduced');
      }
    });

    test('the yellow vertical section-heading accent bar is removed', () {
      expect(source.contains('class _SectionLabel'), isFalse);
      expect(source.contains('class _SectionHeading'), isTrue);

      final headingMatch = RegExp(
        r'class _SectionHeading extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(headingMatch, isNotNull);
      final headingSource = headingMatch!.group(0)!;
      expect(headingSource.contains('Container('), isFalse);
      expect(headingSource.contains('width: 4'), isFalse);
      expect(headingSource.contains('TmColors.yellow'), isFalse);
    });

    test('Why Choose Us no longer wraps each item in an oversized filled card', () {
      final match = RegExp(
        r'class _WhyChooseUsSection extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final sectionSource = match!.group(0)!;

      expect(sectionSource.contains('Container('), isFalse);
      expect(sectionSource.contains('BoxDecoration'), isFalse);
      expect(sectionSource.contains('borderRadius'), isFalse);
    });

    test('Why Choose Us keeps a 2-column layout without a fixed-height grid that forces truncation', () {
      final sectionMatch = RegExp(
        r'class _WhyChooseUsSection extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(sectionMatch, isNotNull);
      final sectionSource = sectionMatch!.group(0)!;

      expect(sectionSource.contains('GridView'), isFalse);
      expect(RegExp(r'Expanded\(child: _WhyChooseUsItem').allMatches(sectionSource).length, 4);

      final itemMatch = RegExp(
        r'class _WhyChooseUsItem extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(itemMatch, isNotNull);
      final itemSource = itemMatch!.group(0)!;

      expect(itemSource.contains('maxLines'), isFalse);
      expect(itemSource.contains('TextOverflow.ellipsis'), isFalse);
    });

    test('Why Choose Us rows do not reserve oversized unused vertical space between them', () {
      final sectionMatch = RegExp(
        r'class _WhyChooseUsSection extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(sectionMatch, isNotNull);
      final sectionSource = sectionMatch!.group(0)!;

      expect(sectionSource.contains('childAspectRatio'), isFalse);
      expect(RegExp(r"SizedBox\(height: 1[6-9]\)").hasMatch(sectionSource), isTrue);
    });

    test('Get in Touch reads email and hotline from the existing support CMS fields', () {
      expect(source.contains("(support?['email'] as String?)?.trim()"), isTrue);
      expect(source.contains("(support?['phone'] as String?)?.trim()"), isTrue);
      expect(source.contains("label: 'Email'"), isTrue);
      expect(source.contains("label: 'Hotline'"), isTrue);
    });

    test('the permanent Create an Account CTA is removed from the About body', () {
      expect(source.contains('Create an Account'), isFalse);
      expect(source.contains("import '../../widgets/tm_button.dart'"), isFalse);
    });

    test('no Request Towing / Book Now / Login / Get Started CTA was added as a replacement', () {
      expect(source.contains('Request Towing'), isFalse);
      expect(source.contains('Book Now'), isFalse);
      expect(source.contains("'Login'"), isFalse);
      expect(source.contains('Get Started'), isFalse);
    });

    test('no direct /book-now navigation exists anywhere on this screen', () {
      expect(source.contains("'/book-now'"), isFalse);
    });

    test('the header matches the approved Home/Services compact treatment, no profile or call icon', () {
      final match = RegExp(
        r'class _TopBar extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final topBarSource = match!.group(0)!;

      expect(topBarSource.contains('height: 52'), isTrue);
      expect(topBarSource.contains('Icons.person'), isFalse);
      expect(topBarSource.contains('Icons.call'), isFalse);
      expect(topBarSource.contains('CircleAvatar'), isFalse);
    });

    test('has no persistent bottom navigation', () {
      expect(source.contains('BottomNavigationBar'), isFalse);
      expect(source.contains('NavigationBar('), isFalse);
    });

    test('has no CircularProgressIndicator', () {
      expect(source.contains('CircularProgressIndicator'), isFalse);
    });

    test('renders safely when optional CMS fields are null or empty, via existing null-safe extraction', () {
      expect(source.contains('final hasEmail = email != null && email.isNotEmpty;'), isTrue);
      expect(source.contains('final hasPhone = phone != null && phone.isNotEmpty;'), isTrue);
      expect(source.contains('final hasLocation = location != null && location.isNotEmpty;'), isTrue);
      expect(source.contains('final hasHours = hours != null && hours.isNotEmpty;'), isTrue);
      expect(source.contains('if (names.isEmpty) {\n      return const SizedBox.shrink();\n    }'), isTrue);
    });

    test('no artificial skeleton loading was added; fallback content still renders instantly', () {
      expect(source.contains('SkeletonBox'), isFalse);
      expect(source.contains('_loading'), isFalse);
    });
  });
}
