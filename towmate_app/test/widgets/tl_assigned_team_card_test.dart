import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/task_model.dart';
import 'package:towmate_app/widgets/tl_assigned_team_card.dart';

Future<void> _pump(WidgetTester tester, AssignedTeam? team) {
  return tester.pumpWidget(
    MaterialApp(home: Scaffold(body: TlAssignedTeamCard(team: team))),
  );
}

void main() {
  testWidgets('renders every row for a complete assignment', (tester) async {
    await _pump(
      tester,
      const AssignedTeam(
        teamLeaderName: 'Juan Leader',
        driverName: 'Pedro Santos',
        crewNames: ['Crew A', 'Crew B'],
        unitName: 'Unit 09',
        plateNumber: 'ABC 1234',
        truckTypeName: 'Isuzu NPR',
        truckClass: 'light',
      ),
    );

    expect(find.text('ASSIGNED TEAM'), findsOneWidget);
    for (final t in [
      'Team Leader', 'Juan Leader', 'Driver', 'Pedro Santos', 'Crew', 'Crew A, Crew B', 'Truck',
      'Unit 09', 'Plate Number', 'ABC 1234', 'Truck Type', 'Isuzu NPR',

    ]) {
      expect(find.text(t), findsOneWidget, reason: t);
    }
  });

  for (final cls in ['light', 'medium', 'heavy', null]) {
    testWidgets('never renders a Classification row (class: $cls)', (tester) async {
      await _pump(tester, AssignedTeam(unitName: 'U', truckTypeName: 'Isuzu', truckClass: cls));
      expect(find.text('Classification'), findsNothing);
      expect(find.text('Truck Type'), findsOneWidget);
      expect(find.text('Isuzu'), findsOneWidget);
    });
  }

  testWidgets('omits unavailable rows and shows truck type when class is null', (tester) async {
    await _pump(
      tester,
      const AssignedTeam(unitName: 'Unit 1', truckTypeName: 'Rollback'),
    );

    expect(find.text('Truck'), findsOneWidget);
    expect(find.text('Truck Type'), findsOneWidget);
    expect(find.text('Rollback'), findsOneWidget);
    expect(find.text('Classification'), findsNothing);
    expect(find.text('Driver'), findsNothing);
    expect(find.text('Crew'), findsNothing);
    expect(find.text('Plate Number'), findsNothing);
    expect(find.textContaining('null'), findsNothing);
    expect(find.textContaining('N/A'), findsNothing);
  });

  testWidgets('renders no card when there is no assignment', (tester) async {
    await _pump(tester, null);
    expect(find.byKey(const Key('tl_assigned_team_card')), findsNothing);
    expect(find.text('ASSIGNED TEAM'), findsNothing);
  });

  testWidgets('renders no card when every field is empty', (tester) async {
    await _pump(tester, const AssignedTeam());
    expect(find.byKey(const Key('tl_assigned_team_card')), findsNothing);
  });
}
