class Service {
  final String title;
  final String description;
  final String availability;
  final String category;
  final String? priceRange;

  const Service({
    required this.title,
    required this.description,
    required this.availability,
    required this.category,
    this.priceRange,
  });
}

const List<Service> allServices = [
  Service(
    title: 'Emergency Towing',
    description: 'Immediate towing assistance for breakdowns and accidents.',
    availability: '24 / 7 · Fastest dispatch',
    category: 'Emergency',
    priceRange: 'Starting ₱500',
  ),
  Service(
    title: 'Roadside Assistance',
    description: 'On-the-spot help for flat tires, dead batteries, and minor issues.',
    availability: '24 / 7 · On-site in 30 min',
    category: 'Assistance',
    priceRange: 'Starting ₱300',
  ),
  Service(
    title: 'Vehicle Recovery',
    description: 'Safe recovery of vehicles from difficult terrain or accidents.',
    availability: 'Daily · 6 AM – 10 PM',
    category: 'Assistance',
    priceRange: 'Starting ₱800',
  ),
  Service(
    title: 'Long-Distance Towing',
    description: 'Reliable transport of your vehicle to any destination.',
    availability: 'By appointment',
    category: 'Transport',
    priceRange: 'Starting ₱1,200',
  ),
  Service(
    title: 'Motorcycle Towing',
    description: 'Specialized towing for motorcycles with proper securing equipment.',
    availability: 'Daily · 7 AM – 9 PM',
    category: 'Transport',
    priceRange: 'Starting ₱400',
  ),
  Service(
    title: 'Heavy Equipment Towing',
    description: 'Transport of trucks, buses, and heavy machinery.',
    availability: 'By appointment',
    category: 'Heavy',
    priceRange: 'Starting ₱2,500',
  ),
];

List<String> get serviceCategories =>
    allServices.map((s) => s.category).toSet().toList();

List<Service> servicesByCategory(String category) =>
    allServices.where((s) => s.category == category).toList();
