class VehicleCategoryModel {
  final String slug;
  final String name;

  const VehicleCategoryModel({required this.slug, required this.name});

  factory VehicleCategoryModel.fromJson(Map<String, dynamic> json) {
    return VehicleCategoryModel(
      slug: json['slug'] as String? ?? '',
      name: json['name'] as String? ?? '',
    );
  }
}
