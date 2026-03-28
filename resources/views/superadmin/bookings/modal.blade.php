<div id="bookingModal" class="booking-modal">

    <div class="booking-modal-content">

        <div class="modal-header">

            <h2>Booking Details</h2>

            <button onclick="closeBooking()">✕</button>

        </div>


        <div class="modal-grid">

            {{-- LEFT CARD --}}

            <div class="modal-card">

                <h3>Booking Information</h3>

                <p><strong>Booking ID:</strong> <span id="m_id"></span></p>
                <p><strong>Customer:</strong> <span id="m_customer"></span></p>
                <p><strong>Truck Type:</strong> <span id="m_truck"></span></p>
                <p><strong>Assigned Unit:</strong> <span id="m_unit"></span></p>

                <hr>

                <p><strong>Pickup:</strong> <span id="m_pickup"></span></p>
                <p><strong>Dropoff:</strong> <span id="m_dropoff"></span></p>

                <hr>

                <p><strong>Distance:</strong> <span id="m_distance"></span></p>
                <p><strong>Base Rate:</strong> <span id="m_base"></span></p>
                <p><strong>Per KM Rate:</strong> <span id="m_km"></span></p>

                <h2 id="m_total"></h2>

                <span id="m_status" class="status"></span>

            </div>


            {{-- RECEIPT CARD --}}

            <div class="modal-card">

                <h3>Receipt Panel</h3>

                <p>Receipt Number</p>
                <strong id="m_receipt"></strong>

                <a id="m_download" class="download-btn">
                    Download Receipt
                </a>

            </div>

        </div>


        <div class="modal-footer">

            <button onclick="closeBooking()" class="close-btn">
                Close
            </button>

        </div>

    </div>

</div>