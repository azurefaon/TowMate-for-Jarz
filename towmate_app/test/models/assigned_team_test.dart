import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/task_model.dart';

Map<String, dynamic> _base([Map<String, dynamic>? extra]) => {
      'id': 1,
      'booking_code': 'TM-1',
      'status': 'assigned',
      ...?extra,
    };

void main() {
  test('parses a complete assigned_team', () {
    final task = TaskModel.fromJson(_base({
      'assigned_team': {
        'team_leader_name': 'Leader',
        'driver_name': 'Driver',
        'crew_names': ['A', 'B'],
        'unit_name': 'Unit 1',
        'plate_number': 'ABC 123',
        'truck_type_name': 'Rollback',
        'truck_class': 'medium',
      },
    }));

    final team = task.assignedTeam!;
    expect(team.teamLeaderName, 'Leader');
    expect(team.driverName, 'Driver');
    expect(team.crewNames, ['A', 'B']);
    expect(team.unitName, 'Unit 1');
    expect(team.plateNumber, 'ABC 123');
    expect(team.truckTypeName, 'Rollback');
    expect(team.classLabel, 'Medium Duty');
  });

  test('null or missing assigned_team yields no assignedTeam', () {
    expect(TaskModel.fromJson(_base({'assigned_team': null})).assignedTeam, isNull);
    expect(TaskModel.fromJson(_base()).assignedTeam, isNull);
  });

  test('partial data is safe: empty crew, blanks and null class', () {
    final team = TaskModel.fromJson(_base({
      'assigned_team': {
        'driver_name': '  ',
        'crew_names': ['', 'Solo'],
        'truck_class': null,
      },
    })).assignedTeam!;

    expect(team.driverName, isNull);
    expect(team.crewNames, ['Solo']);
    expect(team.teamLeaderName, isNull);
    expect(team.classLabel, isNull);
    expect(AssignedTeam.fromJson({}).crewNames, isEmpty);
  });

  test('maps canonical classes and ignores unknown values', () {
    String? label(String? c) => AssignedTeam(truckClass: c).classLabel;
    expect(label('light'), 'Light Duty');
    expect(label('medium'), 'Medium Duty');
    expect(label('heavy'), 'Heavy Duty');
    expect(label(null), isNull);
    expect(label('super'), isNull);
  });

  test('copyWith preserves assignedTeam', () {
    final task = TaskModel.fromJson(_base({
      'assigned_team': {'unit_name': 'U'},
    }));
    expect(task.copyWith(status: 'accepted').assignedTeam?.unitName, 'U');
  });
}
