<div id="bookingModal" class="booking-modal">

    <div class="booking-modal-content">

        <div class="modal-header">

            <button onclick="closeBooking()">✕</button>

        </div>


        <div id="bookingModal" class="booking-modal">

            <div class="booking-modal-content">

                <div class="modal-header">
                    <h2>BK-<span id="m_id"></span></h2>
                    <button onclick="closeBooking()">✕</button>
                </div>

                <div class="modal-grid">

                    <div class="modal-card">

                        <div class="modal-section">
                            <span class="label">Customer</span>
                            <h3 id="m_customer"></h3>
                        </div>

                        <div class="modal-section">
                            <span class="label">Truck Type</span>
                            <h3 id="m_truck"></h3>
                        </div>

                        <div class="modal-section">
                            <span class="label">Assigned Unit</span>
                            <h3 id="m_unit"></h3>
                        </div>

                        <div class="divider"></div>

                        <div class="modal-section">
                            <span class="label">Pickup</span>
                            <p id="m_pickup"></p>
                        </div>

                        <div class="modal-section">
                            <span class="label">Drop-off</span>
                            <p id="m_dropoff"></p>
                        </div>

                        <div class="divider"></div>

                        <div class="modal-inline">
                            <div>
                                <span class="label">Distance</span>
                                <h4 id="m_distance"></h4>
                            </div>
                            <div>
                                <span class="label">Total</span>
                                <h4 id="m_total"></h4>
                            </div>
                        </div>

                        <span id="m_status" class="status"></span>

                    </div>

                    <div class="modal-card receipt">

                        <span class="label">Receipt</span>

                        <h3 id="m_receipt"></h3>

                        <a id="m_download" class="download-btn">
                            Download Receipt
                        </a>

                    </div>

                </div>

                <div class="modal-footer">
                    <button onclick="closeBooking()" class="close-btn">Close</button>
                </div>

            </div>

        </div>

    </div>

</div>
