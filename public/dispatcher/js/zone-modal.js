// Zone Modal Handler
(function() {
    const zoneModal = document.getElementById('zoneModalOverlay');
    const showZoneBtn = document.getElementById('showAddZoneFormBtn');
    const closeZoneBtn = document.getElementById('closeZoneModal');
    const cancelZoneBtn = document.getElementById('cancelAddZoneBtn');
    const zoneForm = document.getElementById('addZoneForm');
    const zoneSelect = document.getElementById('zoneSelect');
    const zoneError = document.getElementById('addZoneError');
    const reviewModal = document.getElementById('actionModal');

    if (!zoneModal || !showZoneBtn) return;

    // Show modal
    showZoneBtn.addEventListener('click', function() {
        // Hide review modal temporarily
        if (reviewModal) {
            reviewModal.style.opacity = '0';
            reviewModal.style.pointerEvents = 'none';
        }
        
        zoneModal.classList.add('show');
        setTimeout(() => {
            document.getElementById('zoneNameModal').focus();
        }, 100);
    });

    // Close modal
    function closeModal() {
        zoneModal.classList.remove('show');
        
        // Restore review modal
        if (reviewModal) {
            setTimeout(() => {
                reviewModal.style.opacity = '1';
                reviewModal.style.pointerEvents = 'auto';
            }, 250);
        }
        
        zoneForm.reset();
        zoneError.classList.remove('show');
        zoneError.textContent = '';
    }

    closeZoneBtn.addEventListener('click', closeModal);
    cancelZoneBtn.addEventListener('click', closeModal);

    // Close on overlay click
    zoneModal.addEventListener('click', function(e) {
        if (e.target === zoneModal) closeModal();
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && zoneModal.classList.contains('show')) {
            closeModal();
        }
    });

    // Handle form submission
    zoneForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(zoneForm);
        const submitBtn = zoneForm.querySelector('.zone-btn-submit');
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
        zoneError.classList.remove('show');

        fetch(zoneForm.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.errors) {
                const errorMsg = typeof data.errors === 'string' 
                    ? data.errors 
                    : Object.values(data.errors).flat().join(', ');
                zoneError.textContent = errorMsg;
                zoneError.classList.add('show');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save';
            } else if (data.zone) {
                // Add new zone to select
                const option = document.createElement('option');
                option.value = data.zone.id;
                option.textContent = data.zone.name;
                option.selected = true;
                zoneSelect.appendChild(option);
                
                // Close modal
                closeModal();
                
                // Show success message
                if (window.toast) {
                    window.toast('Zone created successfully!', 'success');
                }
            }
        })
        .catch(error => {
            zoneError.textContent = 'Failed to create zone. Please try again.';
            zoneError.classList.add('show');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save';
        });
    });
})();
