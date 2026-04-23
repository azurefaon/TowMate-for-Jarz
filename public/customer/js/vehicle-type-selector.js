// Vehicle Type Selection Modal Handler
(function() {
    'use strict';

    const vehicleTypeModal = document.getElementById('vehicleTypeModal');
    const openVehicleTypeModalBtn = document.getElementById('openVehicleTypeModal');
    const closeVehicleTypeModalBtn = document.getElementById('closeVehicleTypeModal');
    const vehicleCategoryGrid = document.getElementById('vehicleCategoryGrid');
    const selectedVehicleText = document.getElementById('selectedVehicleText');
    const vehicleTypeIdInput = document.getElementById('vehicleTypeId');
    const customerVehicleTypeInput = document.getElementById('customerVehicleType');
    const customerVehicleCategoryInput = document.getElementById('customerVehicleCategory');
    const truckTypeRow = document.getElementById('truckTypeRow');
    const truckTypeSelect = document.getElementById('vehicleType');
    const bookingModeRow = document.getElementById('bookingModeRow');

    // Open modal
    if (openVehicleTypeModalBtn) {
        openVehicleTypeModalBtn.addEventListener('click', function() {
            vehicleTypeModal.classList.remove('hidden');
            loadVehicleTypes();
        });
    }

    // Close modal
    if (closeVehicleTypeModalBtn) {
        closeVehicleTypeModalBtn.addEventListener('click', function() {
            vehicleTypeModal.classList.add('hidden');
        });
    }

    // Close modal on backdrop click
    if (vehicleTypeModal) {
        vehicleTypeModal.addEventListener('click', function(e) {
            if (e.target === vehicleTypeModal) {
                vehicleTypeModal.classList.add('hidden');
            }
        });
    }

    // Load all vehicle types
    function loadVehicleTypes() {
        vehicleCategoryGrid.innerHTML = '<div class="category-loading">Loading vehicle types...</div>';

        // Fetch all vehicle types grouped by category
        const categories = ['2_wheeler', '4_wheeler', 'heavy_vehicle'];
        let allVehicleTypes = [];

        Promise.all(categories.map(category => 
            fetch(`/api/vehicle-types/by-category/${category}`)
                .then(response => response.json())
                .then(data => ({
                    category: category,
                    types: data.vehicleTypes || []
                }))
        ))
        .then(results => {
            allVehicleTypes = results.flatMap(result => 
                result.types.map(type => ({
                    ...type,
                    category: result.category
                }))
            );

            if (allVehicleTypes.length === 0) {
                vehicleCategoryGrid.innerHTML = '<div class="category-loading">No vehicle types available</div>';
                return;
            }

            renderVehicleTypes(allVehicleTypes);
        })
        .catch(error => {
            console.error('Error loading vehicle types:', error);
            vehicleCategoryGrid.innerHTML = '<div class="category-loading">Error loading vehicle types</div>';
        });
    }

    // Render vehicle types as cards
    function renderVehicleTypes(vehicleTypes) {
        vehicleCategoryGrid.innerHTML = '';

        vehicleTypes.forEach(type => {
            const card = document.createElement('div');
            card.className = 'vehicle-type-card';
            card.dataset.vehicleTypeId = type.id;
            card.dataset.vehicleName = type.name;
            card.dataset.category = type.category;

            const icon = getVehicleIcon(type.name, type.category);
            const categoryLabel = getCategoryLabel(type.category);

            card.innerHTML = `
                <div class="vehicle-type-icon">${icon}</div>
                <h4>${type.name}</h4>
                <span class="vehicle-type-badge badge-${type.category}">${categoryLabel}</span>
                ${type.description ? `<p>${type.description}</p>` : ''}
            `;

            card.addEventListener('click', function() {
                selectVehicleType(type);
            });

            vehicleCategoryGrid.appendChild(card);
        });
    }

    // Select vehicle type and load compatible truck types
    function selectVehicleType(vehicleType) {
        // Update hidden inputs
        vehicleTypeIdInput.value = vehicleType.id;
        customerVehicleTypeInput.value = vehicleType.name;
        customerVehicleCategoryInput.value = vehicleType.category;

        // Update button text
        selectedVehicleText.textContent = vehicleType.name;

        // Close modal
        vehicleTypeModal.classList.add('hidden');

        // Load compatible truck types
        loadTruckTypes(vehicleType.id);

        // Show truck type row
        truckTypeRow.style.display = 'block';
    }

    // Load compatible truck types for selected vehicle
    function loadTruckTypes(vehicleTypeId) {
        truckTypeSelect.innerHTML = '<option value="">Loading tow trucks...</option>';
        truckTypeSelect.disabled = true;

        fetch(`/api/vehicle-types/${vehicleTypeId}/truck-types`)
            .then(response => response.json())
            .then(data => {
                truckTypeSelect.innerHTML = '<option value="">Choose tow truck...</option>';

                if (data.truckTypes && data.truckTypes.length > 0) {
                    data.truckTypes.forEach(truck => {
                        const option = document.createElement('option');
                        option.value = truck.id;
                        option.textContent = `${truck.name} - ₱${parseFloat(truck.base_rate).toLocaleString()} base`;
                        option.dataset.base = truck.base_rate;
                        option.dataset.perkm = truck.per_km_rate;
                        truckTypeSelect.appendChild(option);
                    });
                    truckTypeSelect.disabled = false;

                    // Show booking mode row after truck type is loaded
                    bookingModeRow.style.display = 'flex';
                } else {
                    truckTypeSelect.innerHTML = '<option value="">No compatible tow trucks available</option>';
                }
            })
            .catch(error => {
                console.error('Error loading truck types:', error);
                truckTypeSelect.innerHTML = '<option value="">Error loading tow trucks</option>';
            });
    }

    // Get vehicle icon based on name/category
    function getVehicleIcon(name, category) {
        const nameLower = name.toLowerCase();
        
        if (nameLower.includes('motorcycle') || nameLower.includes('motor')) return '🏍️';
        if (nameLower.includes('scooter')) return '🛵';
        if (nameLower.includes('tricycle')) return '🛺';
        if (nameLower.includes('sedan')) return '🚗';
        if (nameLower.includes('suv')) return '🚙';
        if (nameLower.includes('pickup')) return '🛻';
        if (nameLower.includes('van')) return '🚐';
        if (nameLower.includes('truck') || nameLower.includes('wheeler')) return '🚚';
        
        // Default icons by category
        if (category === '2_wheeler') return '🏍️';
        if (category === '4_wheeler') return '🚗';
        if (category === 'heavy_vehicle') return '🚛';
        
        return '🚗';
    }

    // Get category label
    function getCategoryLabel(category) {
        const labels = {
            '2_wheeler': '2-Wheeler',
            '4_wheeler': '4-Wheeler',
            'heavy_vehicle': 'Heavy Vehicle'
        };
        return labels[category] || category;
    }

})();
