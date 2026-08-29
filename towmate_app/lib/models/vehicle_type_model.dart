class VehicleTypeModel {
  final int id;
  final String name;
  final String category;

  const VehicleTypeModel({
    required this.id,
    required this.name,
    required this.category,
  });

  factory VehicleTypeModel.fromJson(Map<String, dynamic> json) {
    return VehicleTypeModel(
      id: json['id'] as int,
      name: json['name'] as String,
      category: json['category'] as String? ?? '',
    );
  }
}
