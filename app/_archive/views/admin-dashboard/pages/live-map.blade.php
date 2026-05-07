@extends('admin-dashboard.layouts.app')

@section('title', 'Live Map')

@section('content')
    <div class="page-header">
        <h1 class="page-title">Live Map</h1>
    </div>

    <div class="card">
        <div id="map" style="height: 600px; width: 100%;"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <script>
        var map = L.map('map').setView([40.7128, -74.0060], 10); // NYC default

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Mock active jobs/drivers locations
        const locations = [{
                lat: 40.7589,
                lng: -73.9851,
                title: 'Job #123 - On Job',
                icon: 'truck'
            },
            {
                lat: 40.6892,
                lng: -74.0445,
                title: 'Driver John - Available',
                icon: 'user'
            },
            {
                lat: 40.7505,
                lng: -73.9934,
                title: 'Job #124 - Assigned',
                icon: 'truck'
            }
        ];

        locations.forEach(loc => {
            var marker = L.marker([loc.lat, loc.lng]).addTo(map)
                .bindPopup(loc.title);
        });
    </script>
@endsection
