import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/status_style.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/core/tl_task_flow.dart';

double _lin(double v) => v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
double _lum(Color c) => 0.2126 * _lin(c.r) + 0.7152 * _lin(c.g) + 0.0722 * _lin(c.b);

/// WCAG contrast ratio.
double contrast(Color a, Color b) {
  final x = _lum(a), y = _lum(b);
  return (math.max(x, y) + 0.05) / (math.min(x, y) + 0.05);
}

/// Every backend status the Flutter app can render, and the semantic family
/// each must always use (whichever screen shows it).
final _expectedFamily = <String, StatusStyle>{
  // Team Leader lifecycle
  'assigned': StatusStyle.amber,
  'accepted': StatusStyle.blue,
  'on_the_way': StatusStyle.blue,
  'arrived_pickup': StatusStyle.violet,
  'in_progress': StatusStyle.violet,
  'loading_vehicle': StatusStyle.violet,
  'on_job': StatusStyle.orange,
  'arrived_dropoff': StatusStyle.teal,
  'waiting_verification': StatusStyle.brown,
  'completed': StatusStyle.green,
  'returned': StatusStyle.red,
  // Customer statuses
  'requested': StatusStyle.slate,
  'reviewed': StatusStyle.slate,
  'quoted': StatusStyle.slate,
  'quotation_sent': StatusStyle.slate,
  'scheduled': StatusStyle.indigo,
  'scheduled_confirmed': StatusStyle.indigo,
  'confirmed': StatusStyle.indigo,
  'delayed': StatusStyle.brown,
  'cancelled': StatusStyle.red,
  'rejected': StatusStyle.red,
  'not_responding': StatusStyle.red,
  // Scheduling-bucket banners
  'overdue': StatusStyle.red,
  'ready': StatusStyle.amber,
};

/// The dark theme's surfaces a chip can sit on.
const _darkSurfaces = {
  'bg': TmColors.dark900,
  'card': TmColors.dark800,
  'surface': TmColors.dark700,
};

void main() {
  group('semantic families', () {
    for (final e in _expectedFamily.entries) {
      test('${e.key} -> its expected colour family', () {
        expect(identical(StatusStyle.of(e.key), e.value), isTrue);
      });
    }

    test('the mapping table covers exactly the statuses listed here', () {
      expect(StatusStyle.knownStatuses.toSet(), _expectedFamily.keys.toSet());
    });

    test('every Team Leader lifecycle status is mapped', () {
      for (final s in [
        'assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle',
        'on_job', 'arrived_dropoff', 'waiting_verification', 'completed', 'returned',
      ]) {
        expect(StatusStyle.isKnown(s), isTrue, reason: s);
      }
      // ...and every status the TL flow treats as a step is among them.
      for (final s in ['accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle',
        'on_job', 'arrived_dropoff', 'waiting_verification', 'completed']) {
        expect(TlTaskFlow.stepFor(s), isNotNull);
        expect(StatusStyle.isKnown(s), isTrue, reason: s);
      }
    });

    test('every status the customer app labels (humanStatusLabel) is mapped', () {
      for (final s in [
        'requested', 'reviewed', 'quoted', 'quotation_sent', 'scheduled', 'scheduled_confirmed',
        'confirmed', 'accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress',
        'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification', 'delayed',
        'completed', 'cancelled', 'rejected', 'not_responding', 'returned',
      ]) {
        expect(StatusStyle.isKnown(s), isTrue, reason: s);
      }
    });

    test('statuses that mean the same thing share one colour', () {
      expect(StatusStyle.of('on_the_way').background, StatusStyle.of('accepted').background);
      expect(StatusStyle.of('in_progress').background, StatusStyle.of('loading_vehicle').background);
      expect(StatusStyle.of('cancelled').background, StatusStyle.of('returned').background);
    });

    test('different lifecycle stages are visually distinct in both themes', () {
      final families = {
        StatusStyle.amber, StatusStyle.blue, StatusStyle.violet, StatusStyle.orange, StatusStyle.teal,
        StatusStyle.brown, StatusStyle.green, StatusStyle.red, StatusStyle.slate, StatusStyle.indigo,
        StatusStyle.neutral,
      };
      expect(families.map((f) => f.background).toSet().length, families.length);
      expect(families.map((f) => f.darkBackground).toSet().length, families.length);
    });
  });

  group('solid + readable', () {
    final all = <String, StatusStyle>{
      for (final s in StatusStyle.knownStatuses) s: StatusStyle.of(s),
      'unknown': StatusStyle.of('definitely_not_a_status'),
    };

    for (final e in all.entries) {
      for (final b in Brightness.values) {
        test('${e.key} (${b.name}) is opaque with >= 4.5:1 label contrast', () {
          final bg = e.value.backgroundFor(b);
          final fg = e.value.foregroundFor(b);
          expect(bg.a, 1.0, reason: 'background must be solid, not translucent');
          expect(fg.a, 1.0);
          expect(contrast(bg, fg), greaterThanOrEqualTo(4.5), reason: '${e.key} ${b.name}');
        });
      }
    }

    test('light mode: white text on every deep fill; near-black only on amber', () {
      for (final e in all.entries) {
        final fg = e.value.foreground;
        if (identical(e.value, StatusStyle.amber)) {
          expect(fg.toARGB32(), 0xFF171717);
        } else {
          expect(fg.toARGB32(), 0xFFFFFFFF, reason: e.key);
        }
      }
    });

    test('dark mode fills are solid and distinguishable from every dark surface', () {
      for (final e in all.entries) {
        final bg = e.value.darkBackground;
        for (final s in _darkSurfaces.entries) {
          expect(contrast(bg, s.value), greaterThanOrEqualTo(2.5),
              reason: '${e.key} vs dark ${s.key}');
        }
      }
    });

    test('dark mode does not fall back to pale/light fills (only amber is bright)', () {
      for (final e in all.entries) {
        if (identical(e.value, StatusStyle.amber)) continue;
        expect(_lum(e.value.darkBackground), lessThan(0.2), reason: e.key);
      }
    });

    test('light mode fills are distinct from a white card (deep families)', () {
      for (final e in all.entries) {
        if (identical(e.value, StatusStyle.amber)) {
          expect(e.value.background, isNot(TmColors.white));
          continue;
        }
        expect(contrast(e.value.background, TmColors.white), greaterThanOrEqualTo(4.5), reason: e.key);
      }
    });
  });

  group('completed / returned / assigned / waiting_verification', () {
    test('completed is a solid green with white text', () {
      final s = StatusStyle.of('completed');
      expect(s.background.toARGB32(), 0xFF2E7D32);
      expect(s.foreground.toARGB32(), 0xFFFFFFFF);
      expect(s.background.g, greaterThan(s.background.r));
      expect(s.background.g, greaterThan(s.background.b));
    });

    test('returned is a solid red with white text', () {
      final s = StatusStyle.of('returned');
      expect(s.background.toARGB32(), 0xFFB91C1C);
      expect(s.background.r, greaterThan(s.background.g * 3));
    });

    test('assigned stays a distinct, readable amber', () {
      final s = StatusStyle.of('assigned');
      expect(s.background.toARGB32(), 0xFFFACC15);
      expect(contrast(s.background, s.foreground), greaterThan(10));
      for (final other in ['accepted', 'on_job', 'completed', 'returned', 'waiting_verification']) {
        expect(StatusStyle.of(other).background, isNot(s.background));
      }
    });

    test('waiting_verification is readable and not confusable with assigned', () {
      final s = StatusStyle.of('waiting_verification');
      expect(contrast(s.background, s.foreground), greaterThanOrEqualTo(4.5));
      expect(s.background, isNot(StatusStyle.of('assigned').background));
    });
  });

  group('unknown statuses', () {
    test('fall back to a readable neutral that is no lifecycle family', () {
      final s = StatusStyle.of('brand_new_backend_status');
      expect(identical(s, StatusStyle.neutral), isTrue);
      expect(StatusStyle.isKnown('brand_new_backend_status'), isFalse);
      for (final known in StatusStyle.knownStatuses) {
        expect(StatusStyle.of(known).background, isNot(s.background), reason: known);
        expect(StatusStyle.of(known).darkBackground, isNot(s.darkBackground), reason: known);
      }
    });

    test('the empty status is neutral too', () {
      expect(identical(StatusStyle.of(''), StatusStyle.neutral), isTrue);
    });

    test('fallbackLabel makes a readable label', () {
      expect(StatusStyle.fallbackLabel('brand_new_status'), 'Brand New Status');
      expect(StatusStyle.fallbackLabel('completed'), 'Completed');
      expect(StatusStyle.fallbackLabel(''), '');
    });
  });
}
