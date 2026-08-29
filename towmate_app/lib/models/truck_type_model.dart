import 'vehicle_type_model.dart';

class TruckTypeModel {
  final int id;
  final String name;
  final String truckClass;
  final double baseRate;
  final double perKmRate;
  final String? description;
  final List<VehicleTypeModel> vehicleTypes;

  const TruckTypeModel({
    required this.id,
    required this.name,
    required this.truckClass,
    required this.baseRate,
    required this.perKmRate,
    this.description,
    required this.vehicleTypes,
  });

  factory TruckTypeModel.fromJson(Map<String, dynamic> json) {
    return TruckTypeModel(
      id: json['id'] as int,
      name: json['name'] as String,
      truckClass: json['class'] as String? ?? '',
      baseRate: (json['base_rate'] as num?)?.toDouble() ?? 0.0,
      perKmRate: (json['per_km_rate'] as num?)?.toDouble() ?? 0.0,
      description: json['description'] as String?,
      vehicleTypes: (json['vehicle_types'] as List<dynamic>? ?? [])
          .map((v) => VehicleTypeModel.fromJson(v as Map<String, dynamic>))
          .toList(),
    );
  }
}
