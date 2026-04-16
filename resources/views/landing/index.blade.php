@extends('landing.layouts.app')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('home_page/css/landing.css') }}">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    @endpush

    <div class="landing-wrapper">

        {{-- {{ dd($landing) }} --}}

        <nav class="landing-nav">
            <h2 class="logo">JARZ Towing Services</h2>

            <div class="nav-links" id="navMenu">
                <span class="nav-indicator"></span>
                <a href="#home">Home</a>
                <a href="#about">About</a>
                <a href="#services">Services</a>
                <a href="#contact">Contact</a>
            </div>

            <div class="nav-right-space"></div>

            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </nav>

        @if (session('success'))
            <div class="success-message" id="successMessage">
                <div class="success-content">
                    <i class='bx bx-check-circle'></i>
                    <div>
                        <h4>Booking Successful!</h4>
                        <p>{{ session('success') }}</p>
                    </div>
                    <button class="close-success" onclick="closeSuccessMessage()">
                        <i class='bx bx-x'></i>
                    </button>
                </div>
            </div>
        @endif

        <!-- HERO -->
        <section class="section dark" id="home">
            <div class="content">
                <div class="text">
                    <h1>Fast, Reliable & Professional Towing Service You Can Trust</h1>
                    <p>
                        Need help right now or planning a later pickup? JARZ gives you a fast Book Now option for urgent
                        roadside service and a cleaner Schedule Later flow for planned towing.
                    </p>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                        <a href="{{ route('landing.book') }}" class="hero-btn">Book Now</a>
                        <a href="{{ route('landing.book') }}" class="hero-btn"
                            style="background:#ffffff;border:1px solid #d1d5db;box-shadow:none;">Schedule Later</a>
                    </div>
                    <p style="margin-top:14px;font-size:14px;color:#666;">Urgent towing and planned dispatch are both
                        available in one simple booking flow.</p>
                </div>

                <div class="image-box"
                    style="background-image: url('{{ $landing && $landing->hero_image ? asset('storage/' . $landing->hero_image) : '' }}')">
                </div>
            </div>
        </section>

        <!-- ABOUT -->
        <section class="section light" id="about">
            <div class="content reverse">
                <div class="image-box"
                    style="background-image: url('{{ $landing && $landing->about_image ? asset('storage/' . $landing->about_image) : '' }}')">
                </div>

                <div class="text">
                    <h2>About JARZ</h2>

                    <p>
                        JARZ is a trusted towing and roadside assistance service built to deliver
                        fast, reliable, and secure help whenever you need it most.
                        Our trained professionals ensure your vehicle is handled with care,
                        whether it's a simple roadside issue or a complex recovery situation.
                    </p>

                    <p>
                        We combine modern technology with real-world experience to provide
                        efficient dispatch, real-time tracking, and dependable service
                        that customers can rely on 24/7.
                    </p>

                    <div class="about-highlights">
                        <div class="highlight-item">
                            <i class='bx bx-time'></i>
                            <div>
                                <h5>Fast Response</h5>
                                <p>Average arrival under 30 minutes</p>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <i class='bx bx-check-circle'></i>
                            <div>
                                <h5>Professional Team</h5>
                                <p>Certified & trained professionals</p>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <i class='bx bx-shield-alt'></i>
                            <div>
                                <h5>24/7 Available</h5>
                                <p>Round-the-clock support always</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SERVICES -->
        <section class="section" id="services">
            <div class="services-wrapper">
                <div class="services-header">
                    <h2>Our Services</h2>
                    <p class="services-sub">
                        Comprehensive roadside solutions designed to get you back on the road quickly and safely.
                    </p>
                </div>

                <div class="services-grid">
                    <div class="service-card">
                        <div class="service-icon-wrapper">
                            <i class='bx bx-car service-icon'></i>
                        </div>
                        <h4>Emergency Towing</h4>
                        <p>
                            Immediate towing response for breakdowns, accidents, or urgent situations.
                            We ensure fast arrival and safe transport of your vehicle.
                        </p>
                        <div class="service-badge">24/7 Available</div>
                    </div>

                    <div class="service-card">
                        <div class="service-icon-wrapper">
                            <i class='bx bx-wrench service-icon'></i>
                        </div>
                        <h4>Towing Service</h4>
                        <p>
                            We provide reliable towing assistance to safely transport your vehicle to your desired location.
                            Whether you're stranded or need vehicle transfer, our team is ready to help.
                        </p>
                        <div class="service-badge">Quick Response</div>
                    </div>

                    <div class="service-card">
                        <div class="service-icon-wrapper">
                            <i class='bx bx-phone-call service-icon'></i>
                        </div>
                        <h4>Live Support</h4>
                        <p>
                            Speak with our experienced team directly. Expert guidance and real-time updates
                            throughout your service call.
                        </p>
                        <div class="service-badge">Always Ready</div>
                    </div>

                    <div class="service-card">
                        <div class="service-icon-wrapper">
                            <i class='bx bx-credit-card service-icon'></i>
                        </div>
                        <h4>Flexible Payment</h4>
                        <p>
                            Multiple payment options for your convenience. Transparent pricing with
                            no hidden charges or surprise fees.
                        </p>
                        <div class="service-badge">Transparent</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PORTFOLIO -->
        <section class="section" id="portfolio">
            <div class="portfolio-wrapper">
                <div class="portfolio-header">
                    <h2>Completed Jobs</h2>
                    <p class="portfolio-sub">
                        Real operations handled by our team. These completed jobs showcase our reliability,
                        professionalism, and commitment to delivering quality service every time.
                    </p>
                </div>

                <div class="portfolio-stats">
                    <div class="stat-item">
                        <div class="stat-number">2,500+</div>
                        <div class="stat-label">Jobs Completed</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">98%</div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">4.9/5</div>
                        <div class="stat-label">Customer Rating</div>
                    </div>
                </div>

                <div class="portfolio-layout">

                    <div class="portfolio-main" onclick="openModal(this)">
                        <div class="portfolio-img large"
                            style="background-image: url('{{ $landing && $landing->portfolio_main ? asset('storage/' . $landing->portfolio_main) : '' }}')">
                        </div>
                        <div class="portfolio-overlay">
                            <div class="portfolio-badge">Featured</div>
                            <h3>Emergency Tow</h3>
                            <span>Quezon City</span>
                            <div class="portfolio-meta">
                                <i class='bx bx-check-circle'></i> Completed Successfully
                            </div>
                        </div>
                    </div>

                    <div class="portfolio-side">

                        <div class="portfolio-card" onclick="openModal(this)">
                            <div class="portfolio-img"
                                style="background-image: url('{{ $landing && $landing->portfolio_1 ? asset('storage/' . $landing->portfolio_1) : '' }}')">
                            </div>
                            <div class="portfolio-overlay">
                                <h4>Roadside Assist</h4>
                                <div class="portfolio-star">
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                </div>
                            </div>
                        </div>

                        <div class="portfolio-card" onclick="openModal(this)">
                            <div class="portfolio-img"
                                style="background-image: url('{{ $landing && $landing->portfolio_2 ? asset('storage/' . $landing->portfolio_2) : '' }}')">
                            </div>
                            <div class="portfolio-overlay">
                                <h4>Vehicle Recovery</h4>
                                <div class="portfolio-star">
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                </div>
                            </div>
                        </div>

                        <div class="portfolio-card" onclick="openModal(this)">
                            <div class="portfolio-img"
                                style="background-image: url('{{ $landing && $landing->portfolio_3 ? asset('storage/' . $landing->portfolio_3) : '' }}')">
                            </div>
                            <div class="portfolio-overlay">
                                <h4>Towing Service</h4>
                                <div class="portfolio-star">
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                    <i class='bx bxs-star'></i>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </section>

        <div class="image-modal" id="imageModal">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-content" id="modalContent"></div>
        </div>

        <!-- CONTACT -->
        <section class="section light" id="contact">
            <div class="contact-wrapper">
                <div class="contact-header">
                    <h2>Get In Touch</h2>
                    <p class="contact-sub">
                        Ready to get back on the road? Contact our team for immediate assistance.
                        We're here to help 24/7 with professional towing and roadside services.
                    </p>
                </div>

                <div class="contact-content">
                    <div class="contact-info">
                        <div class="info-card">
                            <div class="info-icon">
                                <i class='bx bx-phone-call'></i>
                            </div>
                            <div class="info-content">
                                <h4>Call Us Now</h4>
                                <p>{{ $landing->contact_phone ?? '+1 (555) 123-4567' }}</p>
                                <span class="info-note">24/7 Emergency Hotline</span>
                            </div>
                        </div>

                        <div class="info-card">
                            <div class="info-icon">
                                <i class='bx bx-envelope'></i>
                            </div>
                            <div class="info-content">
                                <h4>Email Support</h4>
                                <p>{{ $landing->contact_email ?? 'support@jarez.com' }}</p>
                                <span class="info-note">Response within 2 hours</span>
                            </div>
                        </div>

                        <div class="info-card">
                            <div class="info-icon">
                                <i class='bx bx-map-pin'></i>
                            </div>
                            <div class="info-content">
                                <h4>Service Area</h4>
                                <p>Available anywhere in the Philippines</p>
                                <span class="info-note">As long as it doesn't require crossing the sea</span>
                            </div>
                        </div>

                        <div class="info-card">
                            <div class="info-icon">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="info-content">
                                <h4>Business Hours</h4>
                                <p>24/7 Emergency Service</p>
                                <span class="info-note">Office: Mon-Fri 8AM-6PM</span>
                            </div>
                        </div>
                    </div>

                    <div class="contact-cta">
                        <div class="cta-content">
                            <span class="cta-label">Emergency Support</span>
                            <h3>Need Immediate Help?</h3>
                            <p>Our team is standing by to dispatch a tow truck quickly and safely. Reach out now and get
                                expert roadside assistance.</p>
                            <a href="tel:{{ $landing->contact_phone ?? '+15551234567' }}" class="cta-btn">
                                <i class='bx bx-phone'></i>
                                Call Now: {{ $landing->contact_phone ?? '+1 (555) 123-4567' }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <a href="{{ route('landing.book') }}" class="floating-book-btn" id="floatingBookBtn">Book Now</a>

    </div>

    @push('scripts')
        <script src="{{ asset('home_page/js/landing.js') }}"></script>
    @endpush
@endsection
