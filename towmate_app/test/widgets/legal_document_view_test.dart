import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/legal_document_view.dart';

void main() {
  group('parseLegalDocument', () {
    test('splits intro text from the first ## heading onward', () {
      final result = parseLegalDocument('''
Intro paragraph one.
Intro paragraph two.

## 1. First Section
First section body.

## 2. Second Section
Second section body.
''');

      expect(result.intro, contains('Intro paragraph one.'));
      expect(result.intro, contains('Intro paragraph two.'));
      expect(result.intro.contains('##'), isFalse);
      expect(result.sections.length, 2);
      expect(result.sections[0].heading, '1. First Section');
      expect(result.sections[0].body, 'First section body.');
      expect(result.sections[1].heading, '2. Second Section');
      expect(result.sections[1].body, 'Second section body.');
    });

    test('a multi-line section body is preserved with its internal line breaks trimmed at the edges', () {
      final result = parseLegalDocument('''
Intro.

## Heading
Line one.
Line two.

Line three after a blank line.
''');

      expect(result.sections.single.body, 'Line one.\nLine two.\n\nLine three after a blank line.');
    });

    test('content with no ## headings at all becomes pure intro with no sections', () {
      final result = parseLegalDocument('Just a single paragraph with no sections.');

      expect(result.intro, 'Just a single paragraph with no sections.');
      expect(result.sections, isEmpty);
    });

    test('empty content produces an empty intro and no sections, never throws', () {
      final result = parseLegalDocument('');

      expect(result.intro, '');
      expect(result.sections, isEmpty);
    });

    test('a heading with extra leading #s and spacing is normalized to plain text', () {
      final result = parseLegalDocument('Intro.\n\n###   Extra Hashes Heading\nBody.');

      expect(result.sections.single.heading, 'Extra Hashes Heading');
    });

    test('CRLF line endings parse identically to LF', () {
      final result = parseLegalDocument('Intro.\r\n\r\n## Heading\r\nBody text.\r\n');

      expect(result.intro, 'Intro.');
      expect(result.sections.single.heading, 'Heading');
      expect(result.sections.single.body, 'Body text.');
    });
  });
}
