import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/status_style.dart';
import 'package:towmate_app/core/theme.dart';

double _contrast(Color a, Color b) {
  final la = a.computeLuminance();
  final lb = b.computeLuminance();
  final hi = la > lb ? la : lb;
  final lo = la > lb ? lb : la;
  return (hi + 0.05) / (lo + 0.05);
}

void main() {
  group('text tokens meet WCAG AA on every surface', () {
    const lightSurfaces = [TmColors.white, TmColors.grey100];
    const darkSurfaces = [TmColors.dark900, TmColors.dark800, TmColors.dark700];

    test('light theme', () {
      for (final bg in lightSurfaces) {
        for (final fg in [TmColors.black, TmColors.lightTextSecondary, TmColors.lightTextTertiary]) {
          expect(_contrast(fg, bg), greaterThanOrEqualTo(4.5), reason: '$fg on $bg');
        }
      }
    });

    test('dark theme', () {
      for (final bg in darkSurfaces) {
        for (final fg in [TmColors.darkText, TmColors.darkTextSecondary, TmColors.darkTextTertiary]) {
          expect(_contrast(fg, bg), greaterThanOrEqualTo(4.5), reason: '$fg on $bg');
        }
      }
    });
  });

  group('status borders', () {
    test('every status outline keeps 3:1 against its card', () {
      for (final status in StatusStyle.knownStatuses) {
        final style = StatusStyle.of(status);
        expect(
          _contrast(style.borderFor(Brightness.light), TmColors.white),
          greaterThanOrEqualTo(3.0),
          reason: '$status light',
        );
        expect(
          _contrast(style.borderFor(Brightness.dark), TmColors.dark900),
          greaterThanOrEqualTo(3.0),
          reason: '$status dark',
        );
      }
    });

    test('terminal statuses map to the expected families', () {
      expect(StatusStyle.of('completed'), same(StatusStyle.green));
      expect(StatusStyle.of('cancelled'), same(StatusStyle.red));
      expect(StatusStyle.of('rejected'), same(StatusStyle.red));
    });
  });
}
