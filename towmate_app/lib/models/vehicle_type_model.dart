class VehicleTypeModel {
  final int id;
  final String name;
  final String category;
  final String? description;
  final String? iconPath;
  final int? requiredTruckTypeId;

  const VehicleTypeModel({
    required this.id,
    required this.name,
    required this.category,
    this.description,
    this.iconPath,
    this.requiredTruckTypeId,
  });

  factory VehicleTypeModel.fromJson(Map<String, dynamic> json) {
    return VehicleTypeModel(
      id: json['id'] as int,
      name: json['name'] as String,
      category: json['category'] as String? ?? '',
      description: json['description'] as String?,
      iconPath: json['icon_path'] as String?,
      requiredTruckTypeId: (json['required_truck_type_id'] as num?)?.toInt(),
    );
  }
}
