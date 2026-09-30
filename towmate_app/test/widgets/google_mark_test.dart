import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/google_mark.dart';

void main() {
  testWidgets('the declared Google logo asset is bundled', (tester) async {
    final svg = await rootBundle.loadString('assets/icons/google_logo.svg');
    expect(svg, contains('viewBox="0 0 18 18"'));
    expect(svg, isNot(contains('<script')));
    expect(svg, isNot(contains('href')));
  });

  for (final size in [18.0, 20.0]) {
    for (final dark in [false, true]) {
      testWidgets('GoogleMark renders at $size in ${dark ? 'dark' : 'light'} mode', (tester) async {
        await tester.pumpWidget(
          MaterialApp(
            theme: dark ? ThemeData.dark() : ThemeData.light(),
            home: Scaffold(body: Center(child: GoogleMark(size: size))),
          ),
        );
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byType(SvgPicture), findsOneWidget);
        expect(tester.getSize(find.byType(GoogleMark)), Size(size, size));
      });
    }
  }
}
