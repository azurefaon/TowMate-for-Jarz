class Service {
  final String title;
  final String description;
  final String availability;
  final String category;
  final String? imageUrl;

  const Service({
    required this.title,
    required this.description,
    required this.availability,
    required this.category,
    this.imageUrl,
  });

  factory Service.fromJson(Map<String, dynamic> json) {
    return Service(
      title: (json['title'] as String?) ?? '',
      description: (json['description'] as String?) ?? '',
      availability: (json['availability_note'] as String?) ?? '',
      category: (json['category'] as String?) ?? 'Services',
      imageUrl: json['image_url'] as String?,
    );
  }
}
