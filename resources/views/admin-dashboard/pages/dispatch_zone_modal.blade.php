<!-- Zone Modal Overlay -->
<div id="zoneModalOverlay" class="zone-modal-overlay">
    <div class="zone-modal-content">
        <div class="zone-modal-header">
            <h3>🗺️ Create New Zone</h3>
            <button type="button" class="zone-modal-close" id="closeZoneModal">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <form id="addZoneForm" method="POST" action="{{ route('admin.zones.store') }}">
            @csrf
            <div class="zone-form-group">
                <label for="zoneNameModal" class="zone-form-label">Zone Name <span style="color: #dc2626;">*</span></label>
                <input id="zoneNameModal" type="text" name="name" required class="zone-form-input"
                    placeholder="e.g., Downtown, North District, Harbor Area">
            </div>

            <div class="zone-error-msg" id="addZoneError"></div>

            <div class="zone-form-actions">
                <button type="button" class="zone-btn zone-btn-cancel" id="cancelAddZoneBtn">Cancel</button>
                <button type="submit" class="zone-btn zone-btn-submit">Save</button>
            </div>
        </form>
    </div>
</div>
