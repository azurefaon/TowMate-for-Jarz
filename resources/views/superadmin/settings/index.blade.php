@extends('layouts.superadmin')

@section('content')
    <link rel="stylesheet" href="{{ asset('admin/css/system-settings.css') }}">

    <div class="settings-page">

        <div class="settings-header">
            <h1>System Settings</h1>
            <p>Configure system preferences and options</p>
        </div>


        <!-- TABS -->

        <div class="settings-tabs">
            <button class="settings-tab active" data-tab="company">Company</button>
            <button class="settings-tab" data-tab="notifications">Notifications</button>
            <button class="settings-tab" data-tab="security">Security</button>
            <button class="settings-tab" data-tab="dispatch">Dispatch</button>
            <button class="settings-tab" data-tab="financial">Financial</button>
            <button class="settings-tab" data-tab="appearance">Appearance</button>
        </div>



        <form method="POST" action="{{ route('superadmin.settings.update') }}">
            @csrf



            <!-- COMPANY -->

            <div class="settings-content active" id="company">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Company Information</h3>
                        <p>Update your company details and contact information</p>
                    </div>

                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Company Name</label>
                            <input type="text" name="settings[company_name]"
                                value="{{ $settings['company_name'] ?? '' }}">
                        </div>

                        <div class="settings-field">
                            <label>Email Address</label>
                            <input type="email" name="settings[company_email]"
                                value="{{ $settings['company_email'] ?? '' }}">
                        </div>

                        <div class="settings-field">
                            <label>Phone Number</label>
                            <input type="text" name="settings[company_phone]"
                                value="{{ $settings['company_phone'] ?? '' }}">
                        </div>

                        <div class="settings-field">
                            <label>Business Address</label>
                            <textarea name="settings[company_address]">{{ $settings['company_address'] ?? '' }}</textarea>
                        </div>

                    </div>

                </div>

            </div>



            <!-- NOTIFICATIONS -->

            <div class="settings-content" id="notifications">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Notification Preferences</h3>
                        <p>Manage how and when you receive notifications</p>
                    </div>


                    <div class="settings-section">

                        <h4>Notification Channels</h4>

                        <div class="settings-toggle-row">

                            <div class="toggle-info">
                                <strong>Email Notifications</strong>
                                <p>Receive notifications via email</p>
                            </div>

                            <label class="switch">
                                <input type="checkbox" name="settings[email_notifications]">
                                <span class="slider"></span>
                            </label>

                        </div>


                        <div class="settings-toggle-row">

                            <div class="toggle-info">
                                <strong>SMS Notifications</strong>
                                <p>Receive notifications via SMS</p>
                            </div>

                            <label class="switch">
                                <input type="checkbox" name="settings[sms_notifications]">
                                <span class="slider"></span>
                            </label>

                        </div>

                    </div>


                    <hr class="settings-divider">


                    <div class="settings-section">

                        <h4>Alert Types</h4>

                        <div class="settings-toggle-row">
                            <div class="toggle-info">
                                <strong>New Job Alerts</strong>
                                <p>Notify when new jobs are created</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="settings[new_job_alert]">
                                <span class="slider"></span>
                            </label>
                        </div>


                        <div class="settings-toggle-row">
                            <div class="toggle-info">
                                <strong>Status Update Alerts</strong>
                                <p>Notify when job status changes</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="settings[status_update_alert]">
                                <span class="slider"></span>
                            </label>
                        </div>


                        <div class="settings-toggle-row">
                            <div class="toggle-info">
                                <strong>Quotation Alerts</strong>
                                <p>Notify about quotation requests and approvals</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="settings[quotation_alert]">
                                <span class="slider"></span>
                            </label>
                        </div>


                        <div class="settings-toggle-row">
                            <div class="toggle-info">
                                <strong>Payment Alerts</strong>
                                <p>Notify about payment transactions</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="settings[payment_alert]">
                                <span class="slider"></span>
                            </label>
                        </div>

                    </div>

                </div>

            </div>



            <!-- SECURITY -->

            <div class="settings-content" id="security">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Security Settings</h3>
                        <p>Configure security and authentication options</p>
                    </div>


                    <div class="settings-section">

                        <div class="settings-toggle-row">

                            <div class="toggle-info">
                                <strong>Two-Factor Authentication</strong>
                                <p>Require 2FA for all user logins</p>
                            </div>

                            <label class="switch">
                                <input type="checkbox" name="settings[require_2fa]">
                                <span class="slider"></span>
                            </label>

                        </div>


                        <div class="settings-toggle-row">

                            <div class="toggle-info">
                                <strong>Require Strong Passwords</strong>
                                <p>Enforce password complexity requirements</p>
                            </div>

                            <label class="switch">
                                <input type="checkbox" name="settings[strong_password]">
                                <span class="slider"></span>
                            </label>

                        </div>


                        <div class="settings-toggle-row">

                            <div class="toggle-info">
                                <strong>Audit Logging</strong>
                                <p>Track all system activities</p>
                            </div>

                            <label class="switch">
                                <input type="checkbox" name="settings[audit_logging]">
                                <span class="slider"></span>
                            </label>

                        </div>

                    </div>


                    <hr class="settings-divider">


                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Session Timeout (minutes)</label>
                            <input type="number" name="settings[session_timeout]" value="30">
                            <p class="field-help">Auto logout after inactivity period</p>
                        </div>

                    </div>

                </div>

            </div>



            <!-- DISPATCH -->

            <div class="settings-content" id="dispatch">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Dispatch Configuration</h3>
                        <p>Configure dispatch and job assignment settings</p>
                    </div>


                    <div class="settings-toggle-row">

                        <div class="toggle-info">
                            <strong>Automatic Job Assignment</strong>
                            <p>Automatically assign jobs to available drivers</p>
                        </div>

                        <label class="switch">
                            <input type="checkbox" name="settings[auto_dispatch]">
                            <span class="slider"></span>
                        </label>

                    </div>


                    <hr class="settings-divider">


                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Maximum Jobs per Driver</label>
                            <input type="number" name="settings[max_jobs]" value="0">
                        </div>


                        <div class="settings-field">
                            <label>Default Service Radius (miles)</label>
                            <input type="number" name="settings[service_radius]" value="0">
                        </div>

                    </div>

                </div>

            </div>



            <!-- FINANCIAL -->

            <div class="settings-content" id="financial">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Financial Configuration</h3>
                        <p>Configure pricing, taxes, and invoice settings</p>
                    </div>

                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Default Tax Rate (%)</label>
                            <input type="number" name="settings[tax_rate]" value="0">
                        </div>


                        <div class="settings-field">
                            <label>Default Payment Terms</label>
                            <input type="text" name="settings[payment_terms]" value="">
                        </div>


                        <div class="settings-field">
                            <label>Invoice Number Prefix</label>
                            <input type="text" name="settings[invoice_prefix]" value="">
                        </div>


                        <div class="settings-field">
                            <label>Quotation Number Prefix</label>
                            <input type="text" name="settings[quote_prefix]" value="">
                        </div>

                    </div>

                </div>

            </div>



            <!-- APPEARANCE -->

            <div class="settings-content" id="appearance">

                <div class="settings-card">

                    <div class="settings-card-header">
                        <h3>Appearance Settings</h3>
                        <p>Customize the look and feel of your system</p>
                    </div>


                    <div class="settings-grid">

                        <div class="settings-field">
                            <label>Theme</label>
                            <input type="text" name="settings[theme]" value="">
                        </div>


                        <div class="settings-field">
                            <label>Primary Color</label>
                            <input type="text" name="settings[primary_color]" value="">
                        </div>


                        <div class="settings-field">
                            <label>Font Size</label>
                            <input type="text" name="settings[font_size]" value="">
                        </div>


                        <div class="settings-field">
                            <label>Date Format</label>
                            <input type="text" name="settings[date_format]" value="">
                        </div>

                    </div>

                </div>

            </div>



            <!-- SAVE BUTTON -->

            <div class="settings-actions">

                <button type="submit" class="settings-save">
                    Save Changes
                </button>

                <button type="button" class="settings-reset">
                    Reset to Defaults
                </button>

            </div>


        </form>

    </div>



    <script>
        const tabs = document.querySelectorAll(".settings-tab");
        const contents = document.querySelectorAll(".settings-content");

        tabs.forEach(tab => {

            tab.addEventListener("click", () => {

                tabs.forEach(t => t.classList.remove("active"));
                tab.classList.add("active");

                contents.forEach(c => c.classList.remove("active"));

                document.getElementById(tab.dataset.tab).classList.add("active");

            });

        });
    </script>
@endsection
