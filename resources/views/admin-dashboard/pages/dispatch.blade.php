@extends('admin-dashboard.layouts.app')

@section('title', 'Dispatch Queue')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Public+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .tracking-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .tracking-panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            cursor: pointer;
            border-bottom: 1px solid #e5e7eb;
            font-size: .85rem;
            color: #374151;
            user-select: none;
        }

        .tracking-meta {
            color: #9ca3af;
            font-size: .8rem;
            flex: 1;
        }

        .tracking-toggle-label {
            color: #9ca3af;
            font-size: .78rem;
        }

        .tracking-body {
            display: flex;
            height: 320px;
        }

        .tracking-body.is-collapsed {
            display: none;
        }

        .tracking-map-wrap {
            flex: 0 0 62%;
            border-right: 1px solid #e5e7eb;
        }

        #dispatchLiveMap {
            width: 100%;
            height: 100%;
        }

        .tracking-roster-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .tracking-roster-header {
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        #rosterSortLabel {
            font-size: .8rem;
            color: #111;
        }

        .tracking-roster-hint {
            font-size: .72rem;
            color: #9ca3af;
        }

        .zone-filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
        }

        .zone-chip {
            padding: 4px 11px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
            background: #475569;
            color: #fff;
            border: none;
            cursor: pointer;
        }

        .zone-chip.is-active {
            background: #0f172a;
        }

        .tracking-roster {
            flex: 1;
            overflow-y: auto;
        }

        .unit-roster-card {
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background .1s;
        }

        .unit-roster-card:hover {
            background: #f9fafb;
        }

        .urc-name {
            font-size: .83rem;
            color: #111;
        }

        .urc-tl {
            font-size: .76rem;
            color: #6b7280;
            margin-top: 2px;
        }

        .urc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
        }

        .urc-status {
            font-size: .73rem;
        }

        .urc-status--available {
            color: #16a34a;
        }

        .urc-status--on_job {
            color: #d97706;
        }

        .urc-status--other {
            color: #9ca3af;
        }

        .urc-gps {
            font-size: .72rem;
        }

        .urc-gps--live {
            color: #111;
        }

        .urc-gps--recent {
            color: #9ca3af;
        }

        .urc-gps--old {
            color: #d1d5db;
        }

        .urc-distance {
            font-size: .76rem;
            color: #374151;
            margin-top: 3px;
        }

        .quotation-review-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(240px, 0.75fr);
            gap: 12px;
            margin: 12px 0;
            align-items: start;
        }

        #actionModal {
            overflow-y: auto;
            padding: 10px;
            transition: opacity 0.25s ease;
        }

        #actionModal .modal-card {
            width: min(1100px, 96vw);
            max-width: 1100px;
            max-height: calc(100vh - 40px);
            overflow-x: hidden;
            overflow-y: auto;
            padding: 20px;
            border: 2px solid #000 !important;
        }

        #actionModal h3 {
            font-size: 1.1rem;
            font-weight: 800;
            color: #000;
            margin-bottom: 4px;
        }

        #actionModal p {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }

        .review-surface {
            background: #fff;
            border: 2px solid #000;
            padding: 0;
        }

        .review-form-horizontal {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 12px;
            align-items: start;
            padding: 14px;
        }

        .review-form-horizontal .full-span {
            grid-column: 1 / -1;
        }

        .review-surface h4 {
            margin: 0;
            padding: 9px 14px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: #0f172a;
            color: #fff;
        }

        #modalTitle {
            margin-bottom: 4px;
        }

        #modalText {
            margin-bottom: 8px;
            /* font-size: 0.9rem; */
        }

        #actionModal .modal-icon {
            display: none !important;
        }

        .review-summary-list {
            display: grid;
            gap: 8px;
            padding: 14px;
        }

        .review-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.89rem;
            color: #334155;
        }

        .review-summary-row strong {
            color: #0f172a;
            text-align: right;
        }

        .review-summary-row.total {
            margin-top: 8px;
            padding-top: 10px;
            border-top: 2px solid #000;
            font-size: 1rem;
            font-weight: 800;
        }

        .computed-total {
            padding: 11px 14px;
            background: #facc15;
            border: 2px solid #000;
            font-size: 1.1rem;
            font-weight: 800;
            color: #000;
        }

        .computed-total.compact {
            font-size: 1rem;
            padding: 10px 12px;
        }

        .review-input-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .review-field-input,
        .review-field-select {
            width: 100%;
            min-height: 40px;
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            background: #fff;
            color: #0f172a;
            font-size: 0.92rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .review-field-select {
            appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #64748b 50%), linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            padding-right: 36px;
        }

        .review-field-input:focus,
        .review-field-select:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
        }

        .review-field-input[readonly],
        .review-field-input[disabled] {
            background: #f8fafc;
            color: #475569;
            cursor: not-allowed;
        }

        .review-field-input.is-locked {
            background: #f8fafc;
            border-style: dashed;
            border-color: #cbd5e1;
            color: #64748b;
        }

        .unit-select-shell {
            border: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #fffdf5, #f8fafc);
            padding: 8px;
        }

        .unit-select-shell .review-field-select {
            border-color: #d1d5db;
            background-color: transparent;
        }

        .review-field-input.is-invalid,
        .review-field-select.is-invalid {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14);
        }

        .inline-field-error {
            display: none;
            margin-top: 6px;
            font-size: 0.82rem;
            color: #b91c1c;
        }

        .inline-field-error.show {
            display: block;
        }

        .quote-validation-summary {
            display: none !important;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .section-header p {
            margin: 6px 0 0;
            color: #64748b;
        }

        .queue-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 16px 0 14px;
        }

        .queue-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #dbe2ea;
            background: #fff;
            color: #0f172a;
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .queue-tab-count {
            min-width: 20px;
            height: 20px;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
        }

        .queue-tab-count.has-count {
            display: inline-flex;
        }

        .queue-filter-btn.is-active {
            background: #facc15;
            color: #070504;
        }

        .incoming-card.is-hidden {
            display: none;
        }

        .incoming-panel {
            display: none;
        }

        .status-badge.scheduled_confirmed {
            color: #111;
        }

        .btn-accept[disabled] {
            opacity: 0.72;
            cursor: not-allowed;
            filter: saturate(0.85);
        }

        .btn-create-quote {
            background: #111827;
            color: #fff;
            border: 1px solid transparent;
            padding: 10px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .btn-create-quote:hover {
            background: #000;
        }

        .queue-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 12px;
        }

        .queue-chip.book-now {
            background: #dcfce7;
            color: #166534;
        }

        .queue-chip.scheduled {
            background: #0f172a;
            color: #facc15;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .queue-chip.delayed {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.due-now {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.negotiation {
            background: #fef3c7;
            color: #92400e;
        }

        .queue-chip.returned {
            background: #fee2e2;
            color: #991b1b;
        }

        .queue-chip.not_responding {
            background: #e5e7eb;
            color: #374151;
        }

        .queue-chip.active {
            background: #dcfce7;
            color: #166534;
        }

        .queue-chip.ready_completion {
            /* background: #f0fdf4; */
            color: #000000;
            /* border: 1px solid #bbf7d0; */
        }

        .status-badge.payment-pending {
            /* background: #fef3c7; */
            color: #000000;
            /* border-color: #f5d565; */
        }

        .status-badge.confirmed {
            /* background: #e0f2fe; */
            color: #075985;
            /* border-color: #7dd3fc; */
        }

        .status-badge.returned {
            /* background: #fee2e2; */
            color: #991b1b;
            /* border-color: #fca5a5; */
        }

        .status-badge.not_responding {
            color: #374151;
        }

        .group-booking-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 7px 14px;
            border-bottom: none;
            font-size: 0.78rem;
            color: #000000;
        }

        .group-vehicle-indicator {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #475569;
            padding: 3px 0 8px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 8px;
        }

        .incoming-card--group-child {
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }

        .incoming-card--group-child+.incoming-card--group-child {
            margin-top: 2px;
        }

        /* Return Reason Styles */
        .rr-panel {
            margin-top: 12px;
            padding: 12px;
            background: linear-gradient(135deg, #fef3c7, #fef9e7);
            border: 1px solid #fbbf24;
        }

        .rr-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .rr-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .rr-badge--critical {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .rr-badge--high {
            background: #fed7aa;
            color: #9a3412;
            border: 1px solid #fb923c;
        }

        .rr-badge--medium {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fbbf24;
        }

        .rr-label {
            font-size: 13px;
            color: #92400e;
        }

        .rr-note {
            font-size: 13px;
            color: #78350f;
            margin: 6px 0;
            line-height: 1.5;
        }

        .rr-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .rr-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border: 1px solid #d97706;
            background: #fff;
            color: #92400e;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
        }

        /* Service Fee Modal */
        .sf-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 1300;
        }

        .sf-modal-backdrop.show {
            display: flex;
        }

        .sf-modal-card {
            width: min(480px, 100%);
            background: #fff;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }

        .sf-modal-card h3 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            color: #0f172a;
        }

        .sf-modal-card p {
            margin: 0 0 16px;
            color: #475569;
            font-size: 0.9rem;
        }

        .sf-modal-card label {
            display: block;
            margin: 12px 0 6px;
            font-size: 0.88rem;
            color: #334155;
        }

        .sf-modal-card input,
        .sf-modal-card textarea,
        .sf-modal-card select {
            width: 100%;
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            font: inherit;
        }

        .sf-modal-card textarea {
            min-height: 80px;
            resize: vertical;
        }

        .sf-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .sf-btn {
            padding: 10px 20px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.15s;
        }

        .sf-btn--cancel {
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #475569;
        }

        .sf-btn--cancel:hover {
            background: #f8fafc;
        }

        .sf-btn--primary {
            border: none;
            background: #f59e0b;
            color: #fff;
        }

        .sf-btn--primary:hover {
            background: #d97706;
        }

        .sf-btn--primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* ── Wait time badge ─────────────────────────────────────── */
        .wait-badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 4px;
            vertical-align: middle;
        }

        .wait-ok {
            background: #f5f5f5;
            color: #737373;
        }

        .wait-warn {
            background: #fff7ed;
            color: #f97316;
        }

        .wait-urgent {
            background: #fef2f2;
            color: #dc2626;
        }

        /* ── Return task alert banner ────────────────────────────── */
        .return-alert-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-left: 4px solid #dc2626;
            padding: 10px 16px;
            border-radius: 0;
            margin-bottom: 12px;
            font-size: 13px;
            color: #dc2626;
        }

        .return-alert-icon {
            font-size: 15px;
            flex-shrink: 0;
        }

        .return-alert-text {
            flex: 1;
        }

        .return-alert-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 5px 13px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .return-alert-btn:hover {
            background: #b91c1c;
        }

        .return-alert-dismiss {
            background: none;
            border: none;
            color: #dc2626;
            cursor: pointer;
            font-size: 17px;
            padding: 0 2px;
            flex-shrink: 0;
        }

        /* ── GPS dot (TL status table) ───────────────────────────── */
        .gps-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }

        .gps-live {
            background: #16a34a;
        }

        .gps-recent {
            background: #f97316;
        }

        .gps-offline {
            background: #d4d4d4;
        }

        .gps-label-live {
            color: #16a34a;
        }

        .gps-label-recent {
            color: #f97316;
        }

        .gps-label-offline {
            color: #9ca3af;
        }

        /* ── Task chip in TL table ───────────────────────────────── */
        .tl-task-chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
        }

        .tl-task-chip--active {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .tl-task-chip--verify {
            background: #f0fdf4;
            color: #15803d;
        }

        .tl-task-chip--other {
            background: #f8fafc;
            color: #64748b;
        }

        /* ===================================================================
           Booking drawer ("View & Quote") — Book Now queue only.
           Namespaced under .rb- (Receiving Bookings) to avoid clashing with
           the existing .incoming-*/.modal-*/.dp-*/.urc-* conventions above.
        =================================================================== */
        .rb-drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 12, 15, .45);
            display: none;
            z-index: 2099;
        }
        .rb-drawer-overlay.is-open { display: block; }

        .rb-drawer {
            position: fixed;
            top: 0;
            right: -520px;
            width: 100%;
            max-width: 520px;
            height: 100%;
            background: #fff;
            box-shadow: -16px 0 40px rgba(20, 23, 28, .18);
            z-index: 2100;
            display: flex;
            flex-direction: column;
            transition: right .22s cubic-bezier(.2,.8,.3,1);
            font-family: 'Public Sans', system-ui, sans-serif;
            color: #111111;
        }
        .rb-drawer.is-open { right: 0; }
        .rb-drawer h1, .rb-drawer h2, .rb-drawer h3, .rb-drawer h4 {
            font-family: 'Sora', system-ui, sans-serif;
            margin: 0;
        }
        .rb-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; }

        .rb-drawer-head {
            padding: 20px 22px 16px;
            border-bottom: 1px solid #E3E6EB;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex: none;
        }
        .rb-drawer-head .rb-who { display: flex; gap: 12px; align-items: center; }
        .rb-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: #FACC15; color: #111111;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 15px; flex: none;
        }
        .rb-drawer-head h3 { font-size: 16.5px; }
        .rb-drawer-head .rb-sub { font-size: 12px; color: #5B6472; margin-top: 2px; display: flex; flex-direction: column; gap: 1px; }
        .rb-drawer-close {
            border: none; background: #F6F7F9; width: 28px; height: 28px; border-radius: 8px;
            color: #5B6472; font-size: 15px; flex: none; cursor: pointer;
        }

        .rb-drawer-body { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 22px; }
        .rb-section { display: flex; flex-direction: column; gap: 12px; }
        .rb-section h4 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #111111; }

        .rb-grid { display: grid; grid-template-columns: repeat(2, 1fr); column-gap: 32px; row-gap: 14px; font-size: 12.5px; }
        .rb-grid-row { display: grid; grid-template-columns: repeat(2, 1fr); column-gap: 32px; row-gap: 14px; }
        .rb-grid-divider { grid-column: 1/-1; height: 1px; background: #E3E6EB; }
        .rb-grid dt, .rb-grid-row dt { color: #8A93A3; font-size: 11px; margin-bottom: 4px; }
        .rb-grid dd, .rb-grid-row dd { margin: 0; font-weight: 600; }

        .rb-photo-stack-wrap { position: relative; padding-top: 10px; padding-right: 10px; }
        .rb-photo-stack-back { position: absolute; border-radius: 14px; background: #fff; border: 1px solid #E3E6EB; }
        .rb-photo-stack-back-1 { top: 0; right: 0; left: 10px; bottom: 10px; }
        .rb-photo-stack-back-2 { top: 5px; right: 5px; left: 5px; bottom: 5px; background: #F6F7F9; }
        .rb-photo-box {
            position: relative; aspect-ratio: 16/7; border-radius: 14px;
            background: #F6F7F9; border: 1px solid #E3E6EB;
            display: flex; align-items: center; justify-content: center;
            color: #8A93A3; cursor: pointer; z-index: 1; overflow: hidden;
        }
        .rb-photo-box img { width: 100%; height: 100%; object-fit: cover; }
        .rb-photo-stack-badge {
            position: absolute; bottom: 8px; right: 8px; background: rgba(20,23,28,.72); color: #fff;
            font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 99px;
            display: flex; align-items: center; gap: 4px;
        }

        .rb-lightbox-backdrop {
            position: fixed; inset: 0; background: rgba(8,9,11,.85);
            display: none; align-items: center; justify-content: center; z-index: 2200; padding: 24px;
        }
        .rb-lightbox-backdrop.is-open { display: flex; }
        .rb-lightbox { display: flex; align-items: center; gap: 16px; max-width: 640px; width: 100%; }
        .rb-lightbox-stage { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .rb-lightbox-image { width: 100%; aspect-ratio: 4/3; background: #20242c; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #8A93A3; overflow: hidden; }
        .rb-lightbox-image img { width: 100%; height: 100%; object-fit: contain; }
        .rb-lightbox-caption { color: #D5D9E0; font-size: 12.5px; font-weight: 600; }
        .rb-lightbox-nav { flex: none; width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.1); border: none; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .rb-lightbox-nav:hover { background: rgba(255,255,255,.2); }
        .rb-lightbox-close { position: absolute; top: 20px; right: 24px; width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,.12); border: none; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }

        .rb-route { display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; color: #111111; background: #F6F7F9; border: 1px solid #E3E6EB; border-radius: 10px; padding: 10px 12px; }
        .rb-route-row { display: flex; gap: 8px; align-items: flex-start; }
        .rb-route-dot { width: 7px; height: 7px; border-radius: 50%; margin-top: 5px; flex: none; }
        .rb-route-dot.rb-pick { background: #12804A; }
        .rb-route-dot.rb-drop { background: #D8402C; }
        .rb-route-addr { flex: 1; line-height: 1.4; }
        .rb-route-meta { display: flex; justify-content: space-between; font-size: 11.5px; color: #8A93A3; padding-top: 2px; border-top: 1px dashed #CFD4DC; margin-top: 2px; }

        .rb-note-box { background: #F6F7F9; border: 1px solid #E3E6EB; border-radius: 9px; padding: 10px 12px; font-size: 12.5px; line-height: 1.55; color: #111111; }

        .rb-cq-ref { background: #F6F7F9; border: 1px solid #E3E6EB; border-radius: 10px; padding: 11px 12px; display: flex; flex-direction: column; gap: 6px; }
        .rb-cq-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #111111; }
        .rb-cq-row { display: flex; justify-content: space-between; font-size: 12.5px; color: #111111; }
        .rb-cq-row.rb-cq-total { font-weight: 700; padding-top: 6px; border-top: 1px dashed #CFD4DC; }

        .rb-breakdown { display: flex; flex-direction: column; gap: 5px; background: #F6F7F9; border: 1px solid #E3E6EB; border-radius: 9px; padding: 10px 12px; }
        .rb-breakdown .rb-b-row { display: flex; justify-content: space-between; font-size: 12.5px; color: #5B6472; }
        .rb-breakdown .rb-b-row.rb-b-final { color: #111111; font-weight: 700; font-size: 14px; padding-top: 6px; border-top: 1px dashed #CFD4DC; }
        .rb-breakdown .rb-b-row.rb-b-final.rb-b-solo { padding-top: 0; border-top: none; }
        .rb-breakdown .rb-b-row.rb-b-adj .rb-mono.rb-is-add { color: #12804A; }
        .rb-breakdown .rb-b-row.rb-b-adj .rb-mono.rb-is-deduct { color: #D8402C; }

        .rb-sub-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #8A93A3; }
        .rb-history-toggle-btn, .rb-adj-toggle-btn { width: 100%; justify-content: center; }
        .rb-adj-form { display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1px solid #E3E6EB; border-radius: 12px; padding: 14px; }
        .rb-adj-form input, .rb-adj-form select, .rb-adj-form textarea { border: 1px solid #CFD4DC; background: #fff; color: #111111; border-radius: 8px; padding: 9px 10px; font-size: 12.5px; font-family: inherit; width: 100%; }
        .rb-adj-form textarea { min-height: 70px; resize: vertical; }
        .rb-adj-form-row { display: flex; gap: 8px; }
        .rb-adj-form-row select { flex: none; width: 110px; }
        .rb-currency-input-wrap { position: relative; flex: 1; }
        .rb-currency-ic { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #8A93A3; font-weight: 600; font-size: 12.5px; }
        .rb-currency-input-wrap input { padding-left: 26px; }
        .rb-adj-reason-label { display: flex; align-items: baseline; gap: 8px; font-weight: 700; font-size: 12.5px; color: #111111; }
        .rb-adj-error { color: #D8402C; font-size: 11.5px; font-weight: 600; }
        .rb-adj-form-actions { display: flex; gap: 8px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #E3E6EB; }
        .rb-adj-form-actions .rb-btn { flex: none; min-width: 110px; }
        .rb-adj-hint { font-size: 11px; color: #8A93A3; text-align: right; }
        .rb-history { display: flex; flex-direction: column; gap: 6px; }
        .rb-adj-row { display: flex; align-items: baseline; gap: 8px; font-size: 12px; background: #F6F7F9; border: 1px solid #E3E6EB; border-radius: 8px; padding: 7px 10px; }
        .rb-adj-row .rb-adj-sign { font-family: 'JetBrains Mono', monospace; font-weight: 700; flex: none; }
        .rb-adj-row .rb-adj-sign.rb-is-add { color: #12804A; }
        .rb-adj-row .rb-adj-sign.rb-is-deduct { color: #D8402C; }
        .rb-adj-row .rb-adj-reason { flex: 1; color: #111111; }
        .rb-adj-row .rb-adj-time { color: #8A93A3; font-size: 11px; flex: none; }

        .rb-expired-chip { position: relative; display: flex; align-items: center; gap: 6px; color: #D8402C; padding: 2px 0; font-size: 12px; font-weight: 600; }

        .rb-search-wrap { position: relative; }
        .rb-search-wrap input { width: 100%; border: 1px solid #CFD4DC; background: #fff; color: #111111; border-radius: 10px; padding: 11px 12px 11px 38px; font-size: 13px; font-family: inherit; }
        .rb-search-wrap .rb-search-ic { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #8A93A3; display: flex; }
        .rb-unit-card { border: 1px solid #E3E6EB; border-radius: 12px; padding: 12px; background: #fff; }
        .rb-unit-card.rb-is-selected { border-color: #111111; }
        .rb-unit-card-top { display: flex; align-items: flex-start; gap: 12px; }
        .rb-unit-avatar { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; color: #5B6472; flex: none; }
        .rb-unit-card-info { flex: 1; min-width: 0; }
        .rb-unit-card-name { font-weight: 700; font-size: 14px; color: #111111; }
        .rb-unit-card-row { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #5B6472; margin-top: 5px; }
        .rb-unit-card-side { display: flex; flex-direction: column; align-items: flex-end; gap: 9px; flex: none; }
        .rb-unit-star { color: #8A93A3; display: flex; }
        .rb-unit-star.rb-is-rec { color: #111111; }
        .rb-unit-expand-toggle { display: flex; align-items: center; gap: 5px; background: none; border: none; color: #8A93A3; font-size: 11.5px; font-weight: 600; padding: 9px 0 0; cursor: pointer; }
        .rb-unit-expand-body { display: none; flex-direction: column; gap: 4px; padding: 8px 0 0 52px; font-size: 12px; }
        .rb-unit-expand-body.rb-is-open { display: flex; }
        .rb-unit-expand-row { display: flex; justify-content: space-between; gap: 10px; color: #5B6472; }
        .rb-unit-expand-row b { color: #111111; font-weight: 600; }
        .rb-unit-empty-note { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 18px; color: #8A93A3; font-size: 12px; border: 1px solid #E3E6EB; border-radius: 12px; }

        .rb-timeline { display: flex; flex-direction: column; gap: 0; }
        .rb-t-item { display: flex; gap: 10px; position: relative; padding-bottom: 16px; }
        .rb-t-item:last-child { padding-bottom: 0; }
        .rb-t-item::before { content: ""; position: absolute; left: 13px; top: 28px; bottom: 0; width: 1px; background: #CFD4DC; }
        .rb-t-item:last-child::before { display: none; }
        .rb-t-icon { width: 28px; height: 28px; border-radius: 50%; background: #F6F7F9; border: 1px solid #E3E6EB; display: flex; align-items: center; justify-content: center; color: #5B6472; flex: none; z-index: 1; }
        .rb-t-item.rb-is-accept .rb-t-icon { background: #E4F6EC; color: #12804A; border-color: transparent; }
        .rb-t-text { font-size: 12.5px; padding-top: 3px; }
        .rb-t-time { font-size: 11px; color: #8A93A3; }

        .rb-drawer-foot { padding: 14px 22px 18px; border-top: 1px solid #E3E6EB; display: flex; flex-direction: column; gap: 8px; flex: none; }
        .rb-drawer-foot-main { display: flex; gap: 8px; flex-wrap: wrap; }
        .rb-drawer-foot-main .rb-btn { flex: 1; justify-content: center; min-width: 140px; }
        .rb-drawer-foot-reject { display: flex; justify-content: center; padding-top: 2px; }
        .rb-link-btn { background: none; border: none; color: #5B6472; font-size: 11.5px; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; padding: 0; cursor: pointer; }

        .rb-btn { border-radius: 9px; padding: 9px 14px; font-size: 12.5px; font-weight: 700; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: inherit; transition: filter .12s; }
        .rb-btn:hover { filter: brightness(.97); }
        .rb-btn-primary { background: #FACC15; color: #111111; }
        .rb-btn-secondary { background: #fff; color: #111111; border-color: #CFD4DC; }
        .rb-view-quote-btn { width: 100%; justify-content: center; margin-top: auto; }

        .rb-view-all-modal-backdrop { position: fixed; inset: 0; background: rgba(10,12,15,.5); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 2199; padding: 20px; }
        .rb-view-all-modal-backdrop.is-open { display: flex; }
        .rb-view-all-modal { width: 100%; max-width: 460px; background: #fff; border-radius: 16px; border: 1px solid #E3E6EB; box-shadow: 0 20px 60px rgba(20,23,28,.18); max-height: 88vh; overflow-y: auto; }
        .rb-view-all-modal-head { padding: 18px 20px 14px; border-bottom: 1px solid #E3E6EB; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .rb-view-all-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }

        @media (max-width: 640px) {
            .rb-drawer { max-width: 100%; right: -100%; }
            .rb-grid, .rb-grid-row { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')

    <div class="dashboard-container">

        @include('admin-dashboard.pages._quotations-card')

        @include('admin-dashboard.pages._quotation-modal')

        <div class="dp-stats-bar">
            <div class="dp-stat dp-stat--warn">
                <span class="dp-stat-n">{{ $queueCounts['returned'] ?? 0 }}</span>
                <span class="dp-stat-l">Returned</span>
            </div>
            <div class="dp-stat dp-stat--warn">
                <span class="dp-stat-n">{{ $queueCounts['not_responding'] ?? 0 }}</span>
                <span class="dp-stat-l">Not Responding</span>
            </div>
            <div class="dp-stat dp-stat--green">
                <span class="dp-stat-n">{{ $queueCounts['ready_completion'] ?? 0 }}</span>
                <span class="dp-stat-l">Ready</span>
            </div>
            <div class="dp-stat">
                <span class="dp-stat-n">{{ $queueCounts['book-now'] ?? 0 }}</span>
                <span class="dp-stat-l">Book Now</span>
            </div>
            <div class="dp-stat">
                <span class="dp-stat-n">{{ $queueCounts['scheduled'] ?? 0 }}</span>
                <span class="dp-stat-l">Scheduled</span>
            </div>
        </div>

        <div class="dp-dispatch-layout">
            <div class="dp-queue-col">

                <div class="incoming-section">

                    <div class="section-header">
                        <div>
                            <h3>Booking Queue</h3>
                        </div>
                        <div class="view-controls">
                            <span class="count"
                                id="requestCount">{{ $queueCounts['book-now'] ?? $incomingRequests->count() }}</span>
                        </div>
                    </div>

                    {{-- Return task alert banner --}}
                    <div id="returnAlertBanner" class="return-alert-banner" style="display:none;">
                        <span class="return-alert-icon">&#9888;</span>
                        <span class="return-alert-text" id="returnAlertText"></span>
                        <button onclick="window.showReturnedQueue()" class="return-alert-btn">View Returns</button>
                        <button onclick="document.getElementById('returnAlertBanner').style.display='none'"
                            class="return-alert-dismiss">&#10005;</button>
                    </div>

                    <div class="queue-tabs" id="dispatchQueueTabs">

                        <button type="button" class="queue-filter-btn is-active" data-filter="book-now">
                            <span>Book Now</span>
                            <span class="queue-tab-count {{ ($queueCounts['book-now'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="book-now">
                                {{ $queueCounts['book-now'] ?? 0 }}
                            </span>
                        </button>

                        <button type="button" class="queue-filter-btn" data-filter="scheduled">
                            <span>Scheduled</span>
                            <span class="queue-tab-count {{ ($queueCounts['scheduled'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="scheduled">
                                {{ $queueCounts['scheduled'] ?? 0 }}
                            </span>
                        </button>

                        <button type="button" class="queue-filter-btn" data-filter="returned">
                            <span>Returned</span>
                            <span class="queue-tab-count {{ ($queueCounts['returned'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="returned">
                                {{ $queueCounts['returned'] ?? 0 }}
                            </span>
                        </button>

                        <button type="button" class="queue-filter-btn" data-filter="ready_completion">
                            <span>Ready</span>
                            <span
                                class="queue-tab-count {{ ($queueCounts['ready_completion'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="ready_completion">
                                {{ $queueCounts['ready_completion'] ?? 0 }}
                            </span>
                        </button>

                        <button type="button" class="queue-filter-btn" data-filter="not_responding">
                            <span>Not Responding</span>
                            <span
                                class="queue-tab-count {{ ($queueCounts['not_responding'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="not_responding">
                                {{ $queueCounts['not_responding'] ?? 0 }}
                            </span>
                        </button>

                        <button type="button" class="queue-filter-btn" data-filter="all">
                            <span>All</span>
                            <span class="queue-tab-count {{ ($queueCounts['all'] ?? 0) > 0 ? 'has-count' : '' }}"
                                data-count-for="all">
                                {{ $queueCounts['all'] ?? 0 }}
                            </span>
                        </button>
                    </div>

                    <div class="incoming-list" id="incomingList" data-default-filter="book-now"
                        data-assign-url-template="{{ url('/admin-dashboard/booking/__BOOKING__/assign') }}">

                        @forelse($groupedIncoming as $groupCode => $groupBookings)
                            @php $isMultiGroup = $groupBookings->count() > 1; @endphp
                            @if ($isMultiGroup)
                                <div class="group-booking-header">
                                    <strong>Multi vehicle booking {{ $groupBookings->count() }} vehicles</strong>
                                    <span>{{ $groupBookings->first()->customer->full_name ?? 'Guest' }} &middot; Ref:
                                        {{ $groupCode }}</span>
                                </div>
                            @endif
                            @foreach ($groupBookings as $vIdx => $booking)
                                @php
                                    $isReadyCompletion = in_array($booking->status, [
                                        'waiting_verification',
                                        'payment_pending',
                                        'payment_submitted',
                                    ]);
                                    $isReturned = $booking->needs_reassignment;
                                    $isNotResponding = $booking->status === 'not_responding';
                                    $queueBucket = $isNotResponding
                                        ? 'not_responding'
                                        : ($isReadyCompletion
                                            ? 'ready_completion'
                                            : ($isReturned
                                                ? 'returned'
                                                : 'active'));
                                    $timingLabel = $isNotResponding
                                        ? 'Customer Did Not Respond'
                                        : ($isReadyCompletion
                                            ? 'Ready for Completion'
                                            : ($isReturned
                                                ? 'Returned'
                                                : 'Active Booking'));
                                    $statusBadgeClass = $isNotResponding
                                        ? 'not_responding'
                                        : ($isReadyCompletion
                                            ? 'payment-pending'
                                            : ($isReturned
                                                ? 'returned'
                                                : 'confirmed'));
                                    $statusBadgeLabel = $isNotResponding
                                        ? 'Not Responding'
                                        : ($isReadyCompletion
                                            ? match ($booking->status) {
                                                'waiting_verification' => 'Awaiting Verification',
                                                'payment_pending' => 'Payment Pending',
                                                'payment_submitted' => 'Payment Submitted',
                                                default => ucfirst($booking->status),
                                            }
                                            : ($isReturned
                                                ? 'Needs Reassignment'
                                                : ucfirst($booking->status)));

                                    // Extra data for Complete Job modal
                                    $incomingSiblings = ($booking->group_siblings ?? collect())
                                        ->map(
                                            fn($s) => [
                                                'booking_code' => $s->booking_code,
                                                'status' => $s->status,
                                                'truck_type' => $s->truckType?->name ?? '',
                                                'service_type' => $s->service_type ?? 'schedule',
                                                'final_total' => (float) ($s->final_total ?? 0),
                                                'scheduled_date' =>
                                                    optional($s->scheduled_date)->format('Y-m-d') ?? $s->scheduled_date,
                                                'scheduled_time' => $s->scheduled_time ?? null,
                                            ],
                                        )
                                        ->values()
                                        ->toArray();

                                    $cj_vehicleImgUrl = '';
                                    $cj_vehicleImgExtraCount = 0;
                                    if ($booking->vehicle_image_path) {
                                        $cj_paths = json_decode($booking->vehicle_image_path, true);
                                        if (is_array($cj_paths) && !empty($cj_paths)) {
                                            $cj_vehicleImgUrl = \Illuminate\Support\Facades\Storage::disk(
                                                'public',
                                            )->url($cj_paths[0]);
                                            $cj_vehicleImgExtraCount = count($cj_paths) - 1;
                                        }
                                    }
                                    $cj_paymongoRef =
                                        $booking->paymongo_intent_id ?? ($booking->paymongo_link_id ?? '');
                                    $cj_paymentStatusLabel = match ($booking->status) {
                                        'payment_pending' => 'Pending',
                                        'payment_submitted' => 'Proof Submitted',
                                        'waiting_verification' => 'Awaiting Verification',
                                        default => ucfirst(str_replace('_', ' ', $booking->status)),
                                    };
                                    $cj_paymentMethodLabel = match ($booking->payment_method ?? '') {
                                        'gcash' => 'GCash',
                                        'bank' => 'Bank Transfer',
                                        'cash' => 'Cash',
                                        'cheque' => 'Cheque',
                                        default => 'Cash',
                                    };
                                @endphp
                                <div class="incoming-card {{ $booking->is_scheduled && !$booking->is_dispatch_delayed ? 'incoming-card--scheduled' : '' }} {{ $isMultiGroup ? 'incoming-card--group-child' : '' }}"
                                    data-id="{{ $booking->job_code }}" data-status="{{ $booking->status }}"
                                    data-queue="{{ $queueBucket }}" data-group-code="{{ $groupCode }}"
                                    data-group-siblings="{{ json_encode($incomingSiblings) }}"
                                    data-customer-name="{{ e($booking->customer->full_name ?? 'Guest') }}"
                                    data-customer-phone="{{ e($booking->customer->phone ?? 'N/A') }}"
                                    data-truck="{{ e($booking->truckType->name ?? 'Unknown') }}"
                                    data-final-total="{{ $booking->final_total ?? 0 }}"
                                    data-ref="{{ $booking->job_code }}" data-service-mode="{{ $booking->service_mode }}"
                                    data-scheduled-for="{{ optional($booking->scheduled_for)->toIso8601String() }}"
                                    data-current-price="{{ $booking->final_total }}"
                                    data-current-additional="{{ $booking->additional_fee }}"
                                    data-base-rate="{{ $booking->base_rate }}"
                                    data-distance-fee="{{ $booking->distance_fee_amount }}"
                                    data-distance-km="{{ $booking->distance_km }}"
                                    data-per-km-rate="{{ $booking->per_km_rate }}"
                                    data-customer-type="{{ ucfirst($booking->customer_type ?? (optional($booking->customer)->customer_type ?? 'regular')) }}"
                                    data-truck-type="{{ e($booking->truckType->name ?? 'Unknown') }}"
                                    data-dispatch-zone="{{ e($booking->dispatch_zone_label ?? 'General Dispatch Zone') }}"
                                    data-recommended-unit="{{ $booking->recommended_unit_id }}"
                                    data-recommended-summary="{{ e($booking->recommended_unit_summary ?? '') }}"
                                    data-assigned-unit="{{ $booking->assigned_unit_id }}"
                                    data-customer-note="{{ e($booking->customer_response_note ?? '') }}"
                                    data-counter-offer="{{ $booking->counter_offer_amount }}"
                                    data-dispatcher-note="{{ e($booking->remarks ?? ($booking->dispatcher_note ?? '')) }}"
                                    data-return-reason="{{ e($booking->return_reason ?? '') }}"
                                    data-returned-by="{{ e($booking->returnedByTeamLeader->full_name ?? ($booking->returnedByTeamLeader->name ?? '')) }}"
                                    data-returned-at="{{ optional($booking->returned_at)->toIso8601String() }}"
                                    data-created-at="{{ $booking->created_at->toISOString() }}"
                                    data-customer-name="{{ e($booking->customer->full_name ?? 'Guest') }}"
                                    data-customer-phone="{{ e($booking->customer->phone ?? 'N/A') }}"
                                    data-customer-email="{{ $booking->customer->email ?? '—' }}"
                                    data-pickup="{{ $booking->pickup_address ?? '' }}"
                                    data-dropoff="{{ $booking->dropoff_address ?? '' }}"
                                    data-unit-name="{{ $booking->unit->name ?? '—' }}"
                                    data-unit-plate="{{ $booking->unit->plate_number ?? '—' }}"
                                    data-tl-name="{{ $booking->unit->teamLeader->full_name ?? ($booking->unit->teamLeader->name ?? '—') }}"
                                    data-tl-phone="{{ $booking->unit->teamLeader->phone ?? '—' }}"
                                    data-driver-name="{{ $booking->unit->driver->full_name ?? ($booking->unit->driver->name ?? ($booking->unit->driver_name ?? '—')) }}"
                                    data-final-total="{{ $booking->final_total ?? 0 }}"
                                    data-job-code="{{ $booking->job_code ?? '—' }}"
                                    data-payment-method="{{ $booking->payment_method ?? '' }}"
                                    data-cash-received="{{ $booking->cash_received ?? '' }}"
                                    data-payment-method-label="{{ $cj_paymentMethodLabel }}"
                                    data-payment-status-label="{{ $cj_paymentStatusLabel }}"
                                    data-payment-proof-url="{{ json_encode($booking->payment_proof_path ? array_values(array_map(fn($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p), (array) $booking->payment_proof_path)) : []) }}"
                                    data-paymongo-ref="{{ $cj_paymongoRef }}"
                                    data-vat-amount="{{ $booking->vat_amount ?? 0 }}"
                                    data-discount-percentage="{{ $booking->discount_percentage ?? 0 }}"
                                    data-discount-reason="{{ $booking->discount_reason ?? '' }}"
                                    data-computed-total="{{ $booking->computed_total ?? 0 }}"
                                    data-distance-fee-amount="{{ $booking->distance_fee_amount ?? 0 }}"
                                    data-vehicle-image-url="{{ $cj_vehicleImgUrl }}"
                                    data-truck-type-base-rate="{{ $booking->unit->truckType->base_rate ?? ($booking->base_rate ?? 0) }}"
                                    data-pickup-lat="{{ $booking->pickup_lat ?? '' }}"
                                    data-pickup-lng="{{ $booking->pickup_lng ?? '' }}">

                                    <div class="incoming-left">

                                        @if ($cj_vehicleImgUrl)
                                            <div class="incoming-vehicle-thumb-wrap">
                                                <img class="incoming-vehicle-thumb" src="{{ $cj_vehicleImgUrl }}" alt="Vehicle photo">
                                                @if ($cj_vehicleImgExtraCount > 0)
                                                    <span class="incoming-vehicle-thumb-count">+{{ $cj_vehicleImgExtraCount }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($isMultiGroup)
                                            <div class="group-vehicle-indicator">Vehicle {{ $vIdx + 1 }} of
                                                {{ $groupBookings->count() }} &mdash;
                                                {{ $booking->truckType->name ?? 'Tow Truck' }}</div>
                                        @endif

                                        <div class="incoming-route">
                                            <strong>{{ $booking->pickup_address ?? 'Unknown Pickup' }}</strong>
                                            <span class="arrow">→</span>
                                            <span>{{ $booking->dropoff_address ?? 'Unknown Dropoff' }}</span>
                                        </div>

                                        {{-- <div class="incoming-details">
                                    <span><strong>ETA:</strong>
                                        @if ($booking->eta_minutes)
                                            {{ round($booking->eta_minutes) }} min
                                        @elseif ($booking->quotation && $booking->quotation->eta_minutes)
                                            {{ round($booking->quotation->eta_minutes) }} min
                                        @else
                                            <span style="color:#b91c1c">No ETA</span>
                                        @endif
                                    </span>
                                </div> --}}

                                        <div class="incoming-details">
                                            <span><strong>Customer:</strong>
                                                {{ $booking->customer->full_name ?? 'Guest' }}</span>
                                            <span><strong>Phone:</strong> {{ $booking->customer->phone ?? 'N/A' }}</span>
                                            <span><strong>Vehicle:</strong>
                                                {{ $booking->truckType->name ?? 'Unknown' }}</span>
                                            <span><strong>Reference:</strong> {{ $booking->job_code }}</span>
                                        </div>

                                        <div class="incoming-meta">
                                            <span class="time">
                                                {{ $booking->created_at->diffForHumans() }}
                                            </span>
                                            <span class="queue-chip {{ $queueBucket }}">
                                                {{ $timingLabel }}
                                            </span>
                                            <span class="status-badge {{ $statusBadgeClass }}">
                                                {{ $statusBadgeLabel }}
                                            </span>
                                            <span class="wait-badge" data-wait></span>
                                        </div>

                                        <div class="incoming-details" style="margin-top: 10px;">
                                            <span><strong>Dispatch Zone:</strong>
                                                {{ $booking->dispatch_zone_label ?? 'General Dispatch Zone' }}</span>
                                            <span><strong>Recommended unit:</strong>
                                                {{ $booking->recommended_unit_label ?? 'Dispatcher will choose the best ready unit.' }}</span>
                                        </div>

                                        @if ($booking->needs_reassignment)
                                            <div class="incoming-details" style="margin-top: 10px;">
                                                <span><strong>Returned by:</strong>
                                                    {{ $booking->returnedByTeamLeader->full_name ?? ($booking->returnedByTeamLeader->name ?? 'Team Leader') }}</span>
                                                <span><strong>Reason:</strong>
                                                    {{ $booking->return_reason ?? 'Needs reassignment.' }}</span>
                                            </div>

                                            @if (isset($booking->return_reason_parsed))
                                                @php
                                                    $rrp = $booking->return_reason_parsed;
                                                    $isUnreachable = ($rrp['code'] ?? null) === 'customer_unreachable';
                                                @endphp
                                                @if (!$isUnreachable)
                                                    <div class="rr-panel">
                                                        <div class="rr-header">
                                                            <span
                                                                class="rr-badge {{ $rrp['badge_class'] ?? 'rr-badge--medium' }}">
                                                                {{ strtoupper($rrp['priority'] ?? 'medium') }} PRIORITY
                                                            </span>
                                                            <span
                                                                class="rr-label">{{ $rrp['label'] ?? 'Returned' }}</span>
                                                        </div>
                                                        @if (filled($rrp['note'] ?? null))
                                                            <div class="rr-note">{{ $rrp['note'] }}</div>
                                                        @endif
                                                        @if (!empty($rrp['actions']))
                                                            <div class="rr-actions">
                                                                @foreach ($rrp['actions'] as $action)
                                                                    <button type="button" class="rr-action-btn"
                                                                        data-action="{{ $action }}"
                                                                        data-booking-id="{{ $booking->job_code }}"
                                                                        data-customer-id="{{ $booking->customer_id }}">
                                                                        <span>{{ $returnReasonHandler->getActionIcon($action) }}</span>
                                                                        <span>{{ $returnReasonHandler->getActionLabel($action) }}</span>
                                                                    </button>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            @endif
                                        @endif

                                    </div>

                                    @if ($booking->status === 'reviewed')
                                        <div class="incoming-details" style="margin-top: 10px;">
                                            <span><strong>Counter-offer:</strong>
                                                {{ $booking->counter_offer_amount ? '₱' . number_format((float) $booking->counter_offer_amount, 2) : 'Not provided' }}</span>
                                            <span><strong>Customer note:</strong>
                                                {{ $booking->customer_response_note ?? 'Customer requested a quotation adjustment.' }}</span>
                                        </div>
                                    @endif

                                    @php
                                        $rrp = $booking->return_reason_parsed ?? null;
                                        $isCustomerUnreachable =
                                            isset($rrp) && ($rrp['code'] ?? null) === 'customer_unreachable';
                                    @endphp

                                    <div class="incoming-actions">
                                        @if ($isReadyCompletion)
                                            <button type="button" class="btn-complete-job"
                                                data-booking-code="{{ $booking->job_code }}"
                                                data-customer="{{ e($booking->customer->full_name ?? 'Customer') }}"
                                                data-ref="{{ $booking->job_code }}"
                                                data-amount="₱{{ number_format((float) ($booking->final_total ?? 0), 2) }}"
                                                data-status="{{ $booking->status }}"
                                                data-confirm-url="{{ route('admin.jobs.confirm-payment', $booking) }}">
                                                Complete Job
                                            </button>
                                        @elseif (
                                            $booking->needs_reassignment ||
                                                $booking->needs_assignment ||
                                                ($booking->status === 'confirmed' && !is_null($booking->assigned_unit_id)))
                                            <button type="button" class="btn-accept"
                                                data-id="{{ $booking->job_code }}" data-action="accept">
                                                {{ $booking->needs_reassignment ? 'Reassign Task' : 'Start Job' }}
                                            </button>
                                            <button type="button" class="btn-reject"
                                                data-id="{{ $booking->job_code }}" data-action="reject">
                                                Cancel Booking
                                            </button>
                                        @else
                                            <span style="font-size: 0.85rem; color: #64748b;">
                                                Booking is active and assigned to team leader
                                            </span>
                                        @endif
                                        @if (!empty($incomingSiblings))
                                            <button type="button"
                                                onclick="event.stopPropagation(); openCustomerBookingPanel(this.closest('[data-group-siblings]'))"
                                                style="font-size:0.72rem; color:#15803d; background:none; border:none; cursor:pointer; padding:2px 4px; text-decoration:underline; margin-left:8px;">
                                                View Full Booking
                                            </button>
                                        @endif
                                    </div>

                                </div>
                            @endforeach
                        @empty
                            <div class="empty-state" id="emptyState">
                                <p>No bookings in this queue right now.</p>
                            </div>
                        @endforelse


                    </div>

                </div>

                {{-- ── Book Now Queue Panel ──────────────────────────────────────────── --}}
                <div id="bookNowPanel" class="incoming-panel" style="display:none;">
                    @if ($groupedBookNow->isEmpty())
                        <div class="empty-state">
                            <p>No book-now requests in queue.</p>
                        </div>
                    @else
                        @foreach ($groupedBookNow as $bnGroupCode => $bnGroupBookings)
                            @php
                                $bnPrimary = $bnGroupBookings->first();
                                $bnCount = $bnGroupBookings->count();
                                $bnTotal = (float) ($bnPrimary->final_total ?? 0); // primary holds the full multi-vehicle total

                                $bnVehicleImgUrl = '';
                                $bnVehicleImgExtraCount = 0;
                                if ($bnPrimary->vehicle_image_path) {
                                    $bnPaths = json_decode($bnPrimary->vehicle_image_path, true);
                                    if (is_array($bnPaths) && !empty($bnPaths)) {
                                        $bnVehicleImgUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($bnPaths[0]);
                                        $bnVehicleImgExtraCount = count($bnPaths) - 1;
                                    }
                                }
                            @endphp
                            @php
                                $bnPhotoUrls = collect($bnPrimary->vehicle_image_paths ?? [])
                                    ->map(fn($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))
                                    ->values();
                            @endphp
                            <div class="incoming-card" data-queue="book-now"
                                data-id="{{ $bnPrimary->job_code ?? $bnPrimary->id }}"
                                data-booking-code="{{ $bnPrimary->booking_code }}"
                                data-status="{{ $bnPrimary->status }}"
                                data-created-at="{{ $bnPrimary->created_at->toIso8601String() }}"
                                data-pickup-lat="{{ $bnPrimary->pickup_lat ?? '' }}"
                                data-pickup-lng="{{ $bnPrimary->pickup_lng ?? '' }}"
                                data-customer-name="{{ e($bnPrimary->customer->full_name ?? 'Guest') }}"
                                data-customer-phone="{{ e($bnPrimary->customer->phone ?? 'N/A') }}"
                                data-customer-email="{{ e($bnPrimary->customer->email ?? '') }}"
                                data-pickup="{{ $bnPrimary->pickup_address ?? '' }}"
                                data-pickup-notes="{{ e($bnPrimary->pickup_notes ?? '') }}"
                                data-dropoff="{{ $bnPrimary->dropoff_address ?? '' }}"
                                data-distance-km="{{ $bnPrimary->distance_km ?? '' }}"
                                data-customer-note="{{ e($bnPrimary->notes ?? '') }}"
                                data-current-price="{{ $bnTotal }}"
                                data-base-rate="{{ $bnPrimary->base_rate ?? 0 }}"
                                data-vat-exclusive-total="{{ $bnPrimary->vat_exclusive_total ?? $bnPrimary->computed_total ?? 0 }}"
                                data-vat-amount="{{ $bnPrimary->vat_amount ?? 0 }}"
                                data-truck-type="{{ e($bnPrimary->truckType->name ?? 'Unknown') }}"
                                data-truck-type-id="{{ $bnPrimary->truck_type_id }}"
                                data-vehicle-category="{{ e($bnPrimary->vehicleType->name ?? '') }}"
                                data-photos="{{ $bnPhotoUrls->toJson() }}"
                                data-assigned-unit="{{ $bnPrimary->assigned_unit_id }}"
                                data-recommended-unit="{{ $bnPrimary->recommended_unit_id }}"
                                data-recommended-summary="{{ e($bnPrimary->recommended_unit_summary ?? '') }}"
                                data-dispatch-zone="{{ e($bnPrimary->dispatch_zone_label ?? 'General Dispatch Zone') }}"
                                data-quotation-id="{{ $bnPrimary->active_quotation_id ?? '' }}"
                                data-quotation-status="{{ $bnPrimary->active_quotation_status ?? '' }}">
                                <div class="incoming-left">
                                    @if ($bnVehicleImgUrl)
                                        <div class="incoming-vehicle-thumb-wrap">
                                            <img class="incoming-vehicle-thumb" src="{{ $bnVehicleImgUrl }}" alt="Vehicle photo">
                                            @if ($bnVehicleImgExtraCount > 0)
                                                <span class="incoming-vehicle-thumb-count">+{{ $bnVehicleImgExtraCount }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="incoming-header">
                                        <span class="queue-chip book-now">Book
                                            Now{{ $bnCount > 1 ? ' · ' . $bnCount . ' vehicles' : '' }}</span>
                                        <span
                                            class="status-badge {{ $bnPrimary->status }}">{{ ucfirst(str_replace('_', ' ', $bnPrimary->status)) }}</span>
                                        <span
                                            style="font-size:0.78rem;color:#64748b;">{{ $bnPrimary->created_at->diffForHumans() }}</span>
                                        <span class="wait-badge" data-wait></span>
                                    </div>
                                    <div class="incoming-route">
                                        <strong>{{ $bnPrimary->pickup_address }}</strong>
                                        <span class="arrow">→</span>
                                        <span>{{ $bnPrimary->dropoff_address }}</span>
                                    </div>
                                    <div class="incoming-details">
                                        <span><strong>Customer:</strong>
                                            {{ $bnPrimary->customer->full_name ?? 'Guest' }}</span>
                                        <span><strong>Phone:</strong> {{ $bnPrimary->customer->phone ?? 'N/A' }}</span>
                                        <span><strong>Ref:</strong> {{ $bnGroupCode }}</span>
                                    </div>
                                    @if ($bnCount > 1)
                                        <div class="incoming-details"
                                            style="margin-top:6px;border-left:3px solid #dcfce7;padding-left:8px;">
                                            @foreach ($bnGroupBookings as $bnIdx => $bnVehicle)
                                                <span>Vehicle {{ $bnIdx + 1 }}:
                                                    {{ $bnVehicle->truckType->name ?? 'Tow Truck' }}
                                                    @if ($bnIdx === 0)
                                                        &middot; <strong>Total:
                                                            &#8369;{{ number_format((float) ($bnVehicle->final_total ?? 0), 2) }}</strong>
                                                    @else
                                                        &middot;
                                                        &#8369;{{ number_format((float) ($bnVehicle->final_total ?? 0), 2) }}
                                                        (incl. in total)
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="incoming-details">
                                            <span><strong>Truck:</strong> {{ $bnPrimary->truckType->name ?? '—' }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="incoming-right"
                                    style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                                    <div class="incoming-price">&#8369;{{ number_format((float) $bnTotal, 2) }}</div>
                                    <div class="incoming-actions">
                                        <button type="button" class="rb-btn rb-btn-primary rb-view-quote-btn"
                                            onclick="event.stopPropagation(); window.openBookingDrawer(this.closest('.incoming-card'));">
                                            View &amp; Quote
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- ── Scheduled Queue Panel ────────────────────────────────────────── --}}
                <div id="scheduledPanel" class="incoming-panel" style="display:none;">
                    @if ($groupedScheduled->isEmpty())
                        <div class="empty-state">
                            <p>No scheduled bookings in queue.</p>
                        </div>
                    @else
                        @foreach ($groupedScheduled as $schGroupCode => $schGroupBookings)
                            @php
                                $schCount = $schGroupBookings->count();
                                $isMultiSch = $schCount > 1;
                                $schFirst = $schGroupBookings->first();
                            @endphp
                            @if ($isMultiSch)
                                <div class="group-booking-header">
                                    <strong>Multi-vehicle booking · {{ $schCount }} vehicles</strong>
                                    <span>{{ $schFirst->customer->full_name ?? 'Guest' }} &middot; Ref:
                                        {{ $schGroupCode }}</span>
                                </div>
                            @endif
                            @foreach ($schGroupBookings as $schVIdx => $schVehicle)
                                @php
                                    $schVIsConfirmed = $schVehicle->status === 'scheduled_confirmed';
                                    $schVScheduledFor = $schVehicle->scheduled_for;
                                    $schVExpiresLabel = $schVehicle->scheduled_expires_at
                                        ? $schVehicle->scheduled_expires_at->diffForHumans()
                                        : '—';
                                    $schVSiblings = ($schVehicle->group_siblings ?? collect())
                                        ->map(
                                            fn($s) => [
                                                'booking_code' => $s->booking_code ?? ($s['booking_code'] ?? ''),
                                                'status' => $s->status ?? ($s['status'] ?? ''),
                                                'truck_type' => $s->truckType?->name ?? ($s['truck_type'] ?? ''),
                                                'service_type' =>
                                                    $s->service_type ?? ($s['service_type'] ?? 'book_now'),
                                                'final_total' => (float) ($s->final_total ?? ($s['final_total'] ?? 0)),
                                                'scheduled_date' =>
                                                    $s->scheduled_date ?? ($s['scheduled_date'] ?? null),
                                                'scheduled_time' =>
                                                    $s->scheduled_time ?? ($s['scheduled_time'] ?? null),
                                            ],
                                        )
                                        ->values()
                                        ->toArray();
                                    // If a sibling (Vehicle 1) is already completed, this vehicle is ready for immediate dispatch
                                    $schVSiblingCompleted = collect($schVSiblings)->contains(
                                        fn($s) => ($s['status'] ?? '') === 'completed',
                                    );
                                @endphp
                                <div class="incoming-card {{ $schVIsConfirmed ? 'incoming-card--scheduled-confirmed' : 'incoming-card--scheduled' }} {{ $isMultiSch ? 'incoming-card--group-child' : '' }}"
                                    data-queue="scheduled" data-id="{{ $schVehicle->job_code ?? $schVehicle->id }}"
                                    data-status="{{ $schVehicle->status }}" data-booking-id="{{ $schVehicle->id }}"
                                    data-created-at="{{ $schVehicle->created_at->toIso8601String() }}"
                                    data-pickup-lat="{{ $schVehicle->pickup_lat ?? '' }}"
                                    data-pickup-lng="{{ $schVehicle->pickup_lng ?? '' }}"
                                    data-customer="{{ e($schVehicle->customer->full_name ?? 'Guest') }}"
                                    data-phone="{{ e($schVehicle->customer->phone ?? 'N/A') }}"
                                    data-pickup="{{ e($schVehicle->pickup_address ?? '') }}"
                                    data-dropoff="{{ e($schVehicle->dropoff_address ?? '') }}"
                                    data-distance="{{ $schVehicle->distance_km ?? 0 }}"
                                    data-truck="{{ e($schVehicle->truckType->name ?? '—') }}"
                                    data-base-rate="{{ $schVehicle->base_rate ?? 0 }}"
                                    data-distance-fee="{{ $schVehicle->distance_fee_amount }}"
                                    data-ref="{{ e($schVehicle->job_code ?? '') }}"
                                    data-final-total="{{ $schVehicle->final_total ?? 0 }}"
                                    data-group-code="{{ $schVehicle->group_code ?? '' }}"
                                    data-group-siblings="{{ json_encode($schVSiblings) }}"
                                    data-customer-name="{{ e($schVehicle->customer->full_name ?? 'Guest') }}"
                                    data-customer-phone="{{ e($schVehicle->customer->phone ?? 'N/A') }}"
                                    data-customer-email="{{ e($schVehicle->customer->email ?? '') }}"
                                    data-vehicle-images="{{ json_encode($schVehicle->vehicle_image_paths) }}"
                                    data-distance-km="{{ $schVehicle->distance_km ?? 0 }}"
                                    data-current-price="{{ $schVehicle->final_total ?? 0 }}"
                                    data-truck-type="{{ e($schVehicle->truckType->name ?? '—') }}">
                                    <div class="incoming-left">
                                        @if ($isMultiSch)
                                            <div class="group-vehicle-indicator">
                                                Vehicle {{ $schVIdx + 1 }} of {{ $schCount }} &mdash;
                                                {{ $schVehicle->truckType->name ?? 'Tow Truck' }}
                                            </div>
                                        @endif
                                        <div class="incoming-header">
                                            <span class="queue-chip scheduled">
                                                {{ $schVIsConfirmed ? 'Confirmed Schedule' : 'Scheduled' }}
                                            </span>
                                            @php $schQStatus = $schVehicle->active_quotation_status ?? null; @endphp
                                            @if ($schQStatus === 'draft')
                                                <span
                                                    style="background:#f59e0b;color:#0f172a;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">Draft</span>
                                            @elseif ($schQStatus === 'sent')
                                                <span
                                                    style="background:#3b82f6;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">Sent</span>
                                            @elseif ($schQStatus === 'negotiating')
                                                <span
                                                    style="background:#f97316;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">Negotiating</span>
                                            @endif
                                            <span class="wait-badge" data-wait></span>
                                        </div>
                                        <div class="incoming-route">
                                            <strong>{{ $schVehicle->pickup_address }}</strong>
                                            <span class="arrow">→</span>
                                            <span>{{ $schVehicle->dropoff_address }}</span>
                                        </div>
                                        <div class="incoming-details">
                                            @if (!$isMultiSch)
                                                <span><strong>Customer:</strong>
                                                    {{ $schVehicle->customer->full_name ?? 'Guest' }}</span>
                                                <span><strong>Phone:</strong>
                                                    {{ $schVehicle->customer->phone ?? 'N/A' }}</span>
                                            @endif
                                            <span><strong>Truck:</strong> {{ $schVehicle->truckType->name ?? '—' }}</span>
                                            <span><strong>Ref:</strong> {{ $schGroupCode }}</span>
                                        </div>
                                        @if ($schVSiblingCompleted)
                                            <div
                                                style="margin-top:8px; padding:7px 10px; background:#dcfce7; border-left:3px solid #16a34a; border-radius:4px; font-size:0.78rem; color:#15803d; font-weight:600;">
                                                ✓ Vehicle 1 complete — dispatch this vehicle now regardless of scheduled
                                                time
                                            </div>
                                        @endif
                                        @if ($schVScheduledFor)
                                            <div class="incoming-details"
                                                style="margin-top:6px;background:#facc1511;padding:6px 8px;">
                                                <span><strong>Scheduled:</strong>
                                                    {{ $schVScheduledFor->format('D, M j, Y') }}
                                                    at {{ $schVScheduledFor->format('g:i A') }}</span>
                                                <span><strong>Expires:</strong> {{ $schVExpiresLabel }}</span>
                                            </div>
                                        @endif
                                        @if (!empty($schVSiblings))
                                            <div
                                                style="margin-top:8px; padding:7px 10px; background:#f8fafc; border:1px solid #e2e8f0; font-size:0.77rem;">
                                                <div
                                                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                                                    <div
                                                        style="color:#94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; font-size:0.68rem;">
                                                        Part of group with:</div>
                                                    <button type="button"
                                                        onclick="event.stopPropagation(); openCustomerBookingPanel(this.closest('[data-group-siblings]'))"
                                                        style="font-size:0.72rem; color:#15803d; background:none; border:none; cursor:pointer; padding:2px 4px; text-decoration:underline;">
                                                        View Full Booking
                                                    </button>
                                                </div>
                                                @foreach ($schVSiblings as $sv)
                                                    @php
                                                        $svStatusColor = match ($sv['status'] ?? '') {
                                                            'in_progress' => '#a16207',
                                                            'completed' => '#15803d',
                                                            'cancelled' => '#dc2626',
                                                            default => '#475569',
                                                        };
                                                        $svStatusBg = match ($sv['status'] ?? '') {
                                                            'in_progress' => '#fef9c3',
                                                            'completed' => '#dcfce7',
                                                            'cancelled' => '#fee2e2',
                                                            default => '#f1f5f9',
                                                        };
                                                        $svStatusLabel = match ($sv['status'] ?? '') {
                                                            'in_progress' => 'In Progress',
                                                            'completed' => 'Completed',
                                                            'cancelled' => 'Cancelled',
                                                            'scheduled' => 'Scheduled',
                                                            'scheduled_confirmed' => 'Confirmed',
                                                            default => ucfirst(
                                                                str_replace('_', ' ', $sv['status'] ?? ''),
                                                            ),
                                                        };
                                                    @endphp
                                                    <div
                                                        style="display:flex; align-items:center; gap:8px; margin-bottom:3px;">
                                                        <span
                                                            style="font-family:monospace; font-weight:700; color:#0f172a;">{{ $sv['booking_code'] }}</span>
                                                        <span style="color:#64748b;">{{ $sv['truck_type'] }}</span>
                                                        <span
                                                            style="margin-left:auto; font-size:0.68rem; font-weight:700; padding:1px 7px; border-radius:999px; background:{{ $svStatusBg }}; color:{{ $svStatusColor }};">{{ $svStatusLabel }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <div class="incoming-right">
                                        <div class="incoming-price">
                                            &#8369;{{ number_format((float) $schVehicle->final_total, 2) }}</div>
                                        @if ($schVIsConfirmed || $schVSiblingCompleted)
                                            <div style="margin-top:8px;">
                                                @if ($schVehicle->active_quotation_id ?? null)
                                                    <button type="button" class="btn-create-quote"
                                                        onclick="viewQuotationDetails({{ $schVehicle->active_quotation_id }})"
                                                        title="View full booking details and dispatch">
                                                        Open Booking
                                                    </button>
                                                @else
                                                    <button type="button" class="btn-create-quote"
                                                        onclick="openNewScheduleQuote(this.closest('.incoming-card'))"
                                                        title="View full booking details and dispatch">
                                                        Open Booking
                                                    </button>
                                                @endif
                                            </div>
                                        @elseif ($schVehicle->active_quotation_id ?? null)
                                            {{-- Active quotation exists — open it directly --}}
                                            <div style="margin-top:8px;">
                                                @php
                                                    $schQBtn = match ($schVehicle->active_quotation_status ?? '') {
                                                        'sent', 'negotiating' => 'View Quotation',
                                                        'draft'               => 'Open Draft',
                                                        default               => 'Set Price',
                                                    };
                                                @endphp
                                                <button type="button" class="btn-create-quote"
                                                    onclick="viewQuotationDetails({{ $schVehicle->active_quotation_id }})"
                                                    title="Open quotation for this booking">
                                                    {{ $schQBtn }}
                                                </button>
                                            </div>
                                        @else
                                            <div style="margin-top:8px;">
                                                <button type="button" class="btn-create-quote"
                                                    onclick="openNewScheduleQuote(this.closest('.incoming-card'))"
                                                    title="Create a quotation for this scheduled booking">
                                                    Create Quote
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    @endif
                </div>

                @include('admin-dashboard.pages._quotations-section')

                <div id="actionModal" class="hidden" aria-hidden="true" role="dialog" aria-modal="true">
                    <div class="modal-card">

                        <div class="modal-icon" id="modalIcon"></div>

                        <h3 id="modalTitle">Confirm Action</h3>
                        <p id="modalText">Are you sure?</p>

                        {{-- Start Job panel: shown only for confirmed bookings --}}
                        <div id="confirmedBookingPanel" style="display:none; margin-bottom:14px;">
                            <div style="background:#ffffff; border:1px solid #bfd3c6; padding:16px;">
                                <div
                                    style="font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:#15803d; margin-bottom:12px;">
                                    Booking to Assign</div>
                                <div
                                    style="display:grid; grid-template-columns:1fr 1fr; gap:10px 20px; font-size:.87rem; color:#374151;">
                                    <div>
                                        <div
                                            style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                            Customer</div>
                                        <div id="cfCustomerName" color:#0f172a;">—</div>
                                    </div>
                                    <div>
                                        <div
                                            style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                            Phone</div>
                                        <div id="cfCustomerPhone" color:#0f172a;">—</div>
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <div
                                            style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                            Route</div>
                                        <div id="cfRoute" color:#0f172a; line-height:1.4;">—</div>
                                    </div>
                                    <div>
                                        <div
                                            style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                            Vehicle Type</div>
                                        <div id="cfTruckType" color:#0f172a;">—</div>
                                    </div>
                                    <div>
                                        <div
                                            style="color:#3b3b3b; font-size:.72rem; text-transform:uppercase; margin-bottom:3px;">
                                            Distance</div>
                                        <div id="cfDistance" color:#0f172a;">—</div>
                                    </div>
                                </div>
                                <div
                                    style="margin-top:14px; padding:10px 14px; background:#fff; border:1px solid #000000; display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-size:.85rem; color:#000000;">Agreed Total (Price
                                        Locked)</span>
                                    <span id="cfAgreedTotal" style="font-size:1.1rem; color:#000000;">—</span>
                                </div>

                                {{-- Assigned unit card --}}
                                <div id="cfUnitBox"
                                    style="display:none; margin-top:12px; background:#ffffff; padding:14px 16px;">
                                    <div
                                        style="font-size:.65rem; text-transform:uppercase; letter-spacing:.08em; color:#000000; margin-bottom:10px;">
                                        Assigned Unit</div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 16px;">
                                        <div>
                                            <div
                                                style="font-size:.62rem; text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                                Unit</div>
                                            <div id="cfUnitName" style="font-size:.92rem;  color:#000000;">—</div>
                                        </div>
                                        <div>
                                            <div
                                                style="font-size:.62rem;  text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                                Type</div>
                                            <div id="cfUnitType" style="font-size:.88rem;  color:#000000;">—</div>
                                        </div>
                                        <div style="grid-column:1/-1;">
                                            <div
                                                style="font-size:.62rem;  text-transform:uppercase; color:#000000; margin-bottom:2px;">
                                                Team Leader</div>
                                            <div id="cfUnitTl" style="font-size:.88rem;  color:#000000;">—</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="quotationReviewGrid" class="quotation-review-grid">
                            <div class="review-surface">
                                <h4>Review Form</h4>

                                <div class="review-form-horizontal">
                                    <div class="modal-input modal-field">
                                        <label for="distanceInput" class="field-label">Distance (km)</label>
                                        <input type="number" id="distanceInput" class="review-field-input"
                                            min="0.01" step="0.01" placeholder="0.00" required>
                                        <small class="inline-field-error" id="distanceInputError"></small>
                                    </div>

                                    <div class="modal-input modal-field">
                                        <label for="distanceFeeInput" class="field-label">Distance Fee</label>
                                        <input type="text" id="distanceFeeInput" class="review-field-input" readonly>
                                        <small class="inline-field-error" id="distanceFeeInputError"></small>
                                    </div>

                                    <div id="priceWrapper" class="modal-input modal-field">
                                        <label for="priceInput" class="field-label">Additional Fee</label>
                                        <input type="text" id="priceInput" class="review-field-input"
                                            inputmode="decimal" placeholder="0.00" autocomplete="off" />
                                        <small class="field-help" id="priceHelper">Leave blank if no dispatcher adjustment
                                            is
                                            needed.</small>
                                        <small class="inline-field-error" id="priceInputError"></small>
                                    </div>

                                    <div id="dispatchZoneWrapper" class="modal-input modal-field full-span">
                                        <label for="dispatchZoneDisplay" class="field-label">Dispatch Zone</label>
                                        <input type="text" id="dispatchZoneDisplay"
                                            class="review-field-input is-locked" readonly>
                                        <small class="field-help">Automatically detected from customer's pickup
                                            address</small>
                                    </div>

                                    <div class="modal-input modal-field full-span">
                                        <label class="field-label">Final Total</label>
                                        <div class="computed-total" id="finalTotalPreview">₱0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="review-surface">
                                <h4>Summary Card</h4>
                                <div class="review-summary-list">
                                    <div class="review-summary-row"><span>Distance</span><strong id="summaryDistance">0.00
                                            km</strong></div>
                                    <div class="review-summary-row"><span>Base Rate (Unit)</span><strong
                                            id="summaryBase">TBD</strong>
                                    </div>
                                    <div class="review-summary-row"><span>Distance Fee (MMDA: ₱300/km after
                                            1st)</span><strong id="summaryDistanceFee">₱0.00</strong></div>
                                    <div class="review-summary-row"><span>Additional Fee</span><strong
                                            id="summaryAdditional">₱0.00</strong></div>
                                    <div class="review-summary-row total"><span>Final Total</span><strong
                                            id="summaryTotal">₱0.00</strong></div>
                                </div>
                            </div>
                        </div>

                        {{-- Unit selector — shown outside the pricing grid for all accept actions --}}
                        <div id="unitWrapper" class="modal-input modal-field" style="display:none; margin-top:14px;">
                            <label for="unitSelect" class="field-label">Available Unit</label>
                            <div class="unit-select-shell">
                                <select id="unitSelect" class="review-field-select" required>
                                    <option value="">Select available unit</option>
                                    @forelse ($availableUnits as $unit)
                                        <option value="{{ $unit['id'] }}" data-selectable="1"
                                            data-team-leader="{{ e($unit['team_leader_name']) }}"
                                            data-driver="{{ e($unit['driver_name']) }}"
                                            data-zones="{{ e(implode(', ', $unit['coverage_zones'] ?? [])) }}"
                                            data-summary="{{ e($unit['status_summary']) }}"
                                            data-base-rate="{{ $unit['base_rate'] ?? 0 }}">
                                            {{ $unit['label'] }} · {{ $unit['team_leader_name'] }}
                                        </option>
                                    @empty
                                        <option value="" disabled>No online ready units available</option>
                                    @endforelse
                                </select>
                            </div>
                            <small class="field-help" id="unitHelper">Only units with online available team leaders are
                                shown
                                here.</small>
                            <small class="inline-field-error" id="unitSelectError"></small>
                        </div>

                        {{-- Dispatcher note — shown for all accept actions --}}
                        <div id="dispatcherNoteWrapper" class="modal-input modal-field"
                            style="display:none; margin-top:12px;">
                            <label for="dispatcherNoteInput" class="field-label">Notes (optional)</label>
                            <textarea id="dispatcherNoteInput" class="review-field-input" rows="2"
                                placeholder="Add any dispatcher notes or instructions for the team leader..."></textarea>
                        </div>

                        <div id="negotiationHint" class="modal-input modal-field" style="display:none;">
                            <label class="field-label">Latest customer request</label>
                            <small id="negotiationHintText"></small>
                        </div>

                        <div id="rejectReasonWrapper" class="modal-input modal-field">
                            <label for="rejectReasonInput" class="field-label">Rejection reason</label>
                            <input type="text" id="rejectReasonInput" placeholder="Enter rejection reason..." />
                        </div>

                        <div id="quoteValidationSummary" class="quote-validation-summary" aria-live="polite"></div>

                        <div class="modal-actions">
                            <button type="button" id="cancelModalBtn"
                                style="padding:10px 18px;border:2px solid #d1d5db;background:#fff;color:#374151;font-weight:700;cursor:pointer;">
                                Cancel
                            </button>

                            <button type="button" id="saveDraftBtn"
                                style="display:none;padding:10px 18px;border:2px solid #000;background:#fff;color:#000;font-weight:700;cursor:pointer;">
                                Save as Draft
                            </button>

                            <button type="button" id="confirmActionBtn" disabled
                                style="padding:10px 22px;border:2px solid #000;background:#000;color:#fff;font-weight:800;cursor:pointer;text-transform:uppercase;letter-spacing:0.04em;">
                                Confirm
                            </button>
                        </div>

                    </div>
                </div>
                <div id="completeJobModal"
                    style="display:none;position:fixed;inset:0;z-index:10000;align-items:flex-start;justify-content:center;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);padding:20px 16px;overflow-y:auto;"
                    aria-modal="true" role="dialog" hidden>
                    <div
                        style="background:#fff;width:100%;max-width:680px;margin:auto;box-shadow:0 32px 80px rgba(0,0,0,.2);overflow:hidden;display:flex;flex-direction:column;border:1px solid #e2e8f0;">

                        <div style="background:#fff;padding:20px 28px 16px;position:relative;">
                            <button id="completeJobClose" type="button" aria-label="Close"
                                style="position:absolute;top:14px;right:16px;width:28px;height:28px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:1rem;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px;">
                                <img src="{{ asset('customer/image/accridetedlogo.png') }}" alt="MMDA Accredited"
                                    style="height:80px;width:auto;object-fit:contain;">
                                <div style="text-align:center;flex:1;">
                                    <div
                                        style="font-size:1.5rem;color:#0f172a;letter-spacing:.06em;text-transform:uppercase;line-height:1;">
                                        TowMate</div>
                                    <div
                                        style="font-size:.65rem;color:#000000;letter-spacing:.12em;text-transform:uppercase;margin-top:4px;">
                                        Towing Management System</div>
                                </div>
                                <img src="{{ asset('customer/image/TowingLogo.png') }}" alt="Jarz Towing"
                                    style="height:80px;width:auto;object-fit:contain;">
                            </div>
                            <div
                                style="border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:10px 0;text-align:center;">
                                <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.14em;color:#b45309;">
                                    Job Completion Record</div>
                                <div id="cjRefBadge"
                                    style="font-size:.8rem;color:#475569;font-family:monospace;letter-spacing:.06em;margin-top:3px;">
                                </div>
                            </div>
                        </div>

                        {{-- ── PRICE SUMMARY BAND ── --}}
                        <div
                            style="background:linear-gradient(90deg,#fef9c3 0%,#fef3c7 100%);padding:16px 28px;border-bottom:1px solid #fde68a;">
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div>
                                    <div
                                        style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:#92400e;margin-bottom:3px;">
                                        Total Amount Collected</div>
                                    <div id="cjTotalBig"
                                        style="font-size:2rem;color:#0f172a;letter-spacing:-.02em;line-height:1;">—
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 28px;text-align:right;">
                                    <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">Base Rate</div>
                                    <div id="cjBaseRate" style="font-size:.75rem;color:#1c1917;">—</div>
                                    <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">Distance Fee</div>
                                    <div id="cjDistanceFee" style="font-size:.75rem;color:#1c1917;">—</div>
                                    <div style="font-size:.75rem;color:#78716c;white-space:nowrap;">VAT (12%)</div>
                                    <div id="cjVat" style="font-size:.75rem;color:#1c1917;">—</div>
                                    <div id="cjDiscountRow"
                                        style="font-size:.75rem;color:#b45309;white-space:nowrap;display:none;">Discount
                                    </div>
                                    <div id="cjDiscount" style="font-size:.75rem;color:#b45309;display:none;">—
                                    </div>
                                    <div style="grid-column:1/-1;height:1px;background:#fde68a;margin:4px 0;"></div>
                                    <div style="font-size:.75rem;font-weight:700;color:#0f172a;white-space:nowrap;">Final Total</div>
                                    <div id="cjFinalTotal" style="font-size:.75rem;font-weight:700;color:#0f172a;">—</div>
                                </div>
                            </div>
                        </div>

                        <div style="padding:20px 28px;display:flex;flex-direction:column;gap:18px;background:#fafafa;">

                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                    <div
                                        style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                        Customer Information</div>
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                </div>
                                <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                                    <div style="display:grid;grid-template-columns:1fr 1fr;">
                                        <div
                                            style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Full Name</div>
                                            <div id="cjCustomerName" style="font-size:.85rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Customer Type</div>
                                            <div id="cjCustomerType" style="font-size:.85rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div
                                            style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Phone</div>
                                            <div id="cjCustomerPhone" style="font-size:.85rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Email</div>
                                            <div id="cjCustomerEmail"
                                                style="font-size:.8rem;color:#0f172a;word-break:break-all;">
                                                —</div>
                                        </div>
                                        <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Pickup</div>
                                            <div id="cjPickup" style="font-size:.8rem;color:#0f172a;line-height:1.4;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Drop-off</div>
                                            <div id="cjDropoff" style="font-size:.8rem;color:#0f172a;line-height:1.4;">—
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                    <div
                                        style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                        Payment Information</div>
                                </div>
                                <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;">
                                        <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Mode</div>
                                            <div id="cjPaymentMode" style="font-size:.88rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Status</div>
                                            <div id="cjPaymentStatus" style="font-size:.88rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Reference #</div>
                                            <div id="cjPaymongoRef"
                                                style="font-size:.78rem;color:#0f172a;word-break:break-all;">—
                                            </div>
                                        </div>
                                    </div>
                                    <div id="cjCashRow"
                                        style="display:none;border-top:1px solid #f1f5f9;padding:10px 14px;">
                                        <div
                                            style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                            Cash Received (&#8369;)</div>
                                        <div id="cjCashReceivedDisplay" style="font-size:.88rem;color:#0f172a;">—
                                        </div>
                                        <p id="cjCashHint" style="margin:4px 0 0;font-size:.68rem;color:#94a3b8;">
                                            Reported by the Team Leader on task completion.</p>
                                    </div>
                                    <div id="cjProofRow"
                                        style="display:none;border-top:1px solid #f1f5f9;padding:12px 14px;">
                                        <div
                                            style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:8px;">
                                            Proof of Payment Required</div>
                                        <div id="cjProofImagesContainer" style="display:flex;flex-wrap:wrap;gap:8px;">
                                        </div>
                                        <p style="margin:6px 0 0;font-size:.7rem;color:#000000;text-align:center;">Click an
                                            image
                                            to open full size</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                    <div
                                        style="font-size:.62rem;text-transform:uppercase;color:#000000;white-space:nowrap;">
                                        Unit &amp; Team Details</div>
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                </div>
                                <div style="border:1px solid #e2e8f0;overflow:hidden;background:#fff;">
                                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;">
                                        <div
                                            style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Unit Name</div>
                                            <div id="cjUnitName" style="font-size:.85rem;color:#0f172a;">—</div>
                                        </div>
                                        <div
                                            style="padding:10px 14px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Plate No.</div>
                                            <div id="cjUnitPlate" style="font-size:.85rem;color:#0f172a;">—</div>
                                        </div>
                                        <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Truck Type</div>
                                            <div id="cjTruckType" style="font-size:.85rem;color:#0f172a;">—</div>
                                        </div>
                                        <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Base Rate (Type)</div>
                                            <div id="cjTruckBaseRate" style="font-size:.85rem;color:#0f172a;">—
                                            </div>
                                        </div>
                                        <div style="padding:10px 14px;border-right:1px solid #f1f5f9;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Team Leader</div>
                                            <div id="cjTlName" style="font-size:.85rem;color:#0f172a;">—</div>
                                        </div>
                                        <div style="padding:10px 14px;">
                                            <div
                                                style="font-size:.6rem;text-transform:uppercase;color:#000000;margin-bottom:2px;">
                                                Driver</div>
                                            <div id="cjDriverName" style="font-size:.85rem;color:#0f172a;">—</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Vehicle Photo (optional) --}}
                            <div id="cjVehiclePhotoWrap" style="display:none;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                    <div
                                        style="font-size:.62rem;text-transform:uppercase;letter-spacing:.12em;color:#000000;white-space:nowrap;">
                                        Vehicle Photo</div>
                                    <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                                </div>
                                <a id="cjVehicleLink" href="#" target="_blank" rel="noopener noreferrer"
                                    style="display:block;overflow:hidden;border:1px solid #e2e8f0;max-height:180px;background:#f8fafc;">
                                    <img id="cjVehicleImg" src="" alt="Vehicle"
                                        style="width:100%;max-height:180px;object-fit:cover;display:block;">
                                </a>
                            </div>

                        </div>

                        <div
                            style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                            <div style="font-size:.65rem;color:#000000;line-height:1.5;">
                                Generated by TowMate &middot; {{ date('M d, Y') }}<br>
                                <span>Receipt will be emailed to customer on completion.</span>
                            </div>
                            <div style="display:flex;gap:10px;flex-shrink:0;">
                                <button id="completeJobCancel" type="button"
                                    style="padding:9px 18px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:.87rem;cursor:pointer;">
                                    Cancel
                                </button>
                                <button id="completeJobOk" type="button"
                                    style="padding:9px 22px;border:none;background:#facc15;color:#0f172a;font-size:.87rem;cursor:pointer;display:flex;align-items:center;gap:6px;">
                                    Mark as Completed
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

            </div>{{-- /.dp-queue-col --}}
            <div class="dp-sidebar-col">

                {{-- ── Live Unit Tracking ──────────────────────────────────────────── --}}
                <div class="tracking-panel" id="trackingPanel">
                    <div class="tracking-panel-header" id="trackingToggleBtn">
                        <span>units — live tracking</span>
                        <span class="tracking-meta" id="trackingMeta">loading...</span>
                        <span class="tracking-toggle-label" id="trackingToggleLabel">hide</span>
                    </div>
                    <div class="tracking-body" id="trackingBody">
                        <div class="tracking-map-wrap">
                            <div id="dispatchLiveMap"></div>
                        </div>
                        <div class="tracking-roster-wrap">
                            <div class="tracking-roster-header">
                                <span id="rosterSortLabel">all units</span>
                                <span class="tracking-roster-hint" id="rosterHint">click a booking to sort by
                                    proximity</span>
                            </div>
                            <div class="zone-filter-chips" id="zoneFilterChips"></div>
                            <div class="tracking-roster" id="trackingRoster"></div>
                        </div>
                    </div>
                </div>
            </div>{{-- /.dp-sidebar-col --}}
        </div>{{-- /.dp-dispatch-layout --}}

    </div>{{-- /.dashboard-container --}}

    {{-- Service Fee Modal --}}
    <div class="sf-modal-backdrop" id="serviceFeeModal" aria-hidden="true">
        <div class="sf-modal-card">
            <h3>💰 Apply Service Fee</h3>
            <p>Apply a cancellation service fee to this booking.</p>

            <label for="serviceFeeAmount">Service Fee Amount (₱)</label>
            <input type="number" id="serviceFeeAmount" min="0" step="0.01" placeholder="0.00" required>

            <label for="serviceFeeReason">Reason</label>
            <textarea id="serviceFeeReason" placeholder="Customer cancelled after unit was dispatched..." required></textarea>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn--cancel" id="cancelServiceFeeBtn">Cancel</button>
                <button type="button" class="sf-btn sf-btn--primary" id="confirmServiceFeeBtn">Apply Fee</button>
            </div>
        </div>
    </div>

    {{-- Customer Risk Modal --}}
    <div class="sf-modal-backdrop" id="customerRiskModal" aria-hidden="true">
        <div class="sf-modal-card">
            <h3>🚩 Mark Customer Risk</h3>
            <p>Flag this customer for future reference.</p>

            <label for="riskLevel">Risk Level</label>
            <select id="riskLevel" required>
                <option value="">Select risk level</option>
                <option value="low">Low - Minor issue</option>
                <option value="medium">Medium - Repeated issues</option>
                <option value="high">High - Serious concern</option>
                <option value="blacklist">Blacklist - Do not serve</option>
            </select>

            <label for="riskReason">Reason</label>
            <textarea id="riskReason" placeholder="Customer cancelled multiple times after dispatch..." required></textarea>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn--cancel" id="cancelRiskBtn">Cancel</button>
                <button type="button" class="sf-btn sf-btn--primary" id="confirmRiskBtn">Mark Customer</button>
            </div>
        </div>
    </div>

    <!-- Customer Booking Slide-out Panel -->
    <div id="customerBookingPanel"
        style="position:fixed; top:0; right:-420px; width:420px; height:100vh;
                background:#fff; border-left:1px solid #e2e8f0; z-index:2000;
                transition:right 0.3s ease; overflow-y:auto;
                box-shadow:-4px 0 24px rgba(0,0,0,0.12);">
        <div
            style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:1;">
            <div>
                <div style="font-size:0.95rem; font-weight:700; color:#0f172a;">Customer Booking</div>
                <div id="cbpGroupCode" style="font-size:0.75rem; color:#94a3b8; font-family:monospace; margin-top:1px;">
                </div>
            </div>
            <button onclick="closeCustomerBookingPanel()"
                style="width:28px; height:28px; border:1px solid #e2e8f0; background:#f8fafc; cursor:pointer; font-size:1rem; color:#64748b; display:flex; align-items:center; justify-content:center;">×</button>
        </div>
        <div id="cbpCustomerInfo" style="padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;"></div>
        <div id="cbpBookingsList" style="padding:14px 20px; display:grid; gap:12px;"></div>
    </div>
    <div id="customerBookingOverlay" onclick="closeCustomerBookingPanel()"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.2); z-index:1999;"></div>

    {{-- Booking drawer ("View & Quote") — Book Now queue only. Content is rendered
         dynamically by booking-drawer.js; this is just the mount point + overlay. --}}
    <div class="rb-drawer-overlay" id="rbDrawerOverlay"></div>
    <div class="rb-drawer" id="rbDrawer"></div>

    <div class="rb-lightbox-backdrop" id="rbLightboxBackdrop">
        <button class="rb-lightbox-close" id="rbLightboxClose" aria-label="Close">✕</button>
        <div class="rb-lightbox">
            <button class="rb-lightbox-nav" id="rbLightboxPrev" aria-label="Previous photo">‹</button>
            <div class="rb-lightbox-stage">
                <div class="rb-lightbox-image" id="rbLightboxImage"></div>
                <div class="rb-lightbox-caption" id="rbLightboxCaption"></div>
            </div>
            <button class="rb-lightbox-nav" id="rbLightboxNext" aria-label="Next photo">›</button>
        </div>
    </div>

    <div class="rb-view-all-modal-backdrop" id="rbUnitsModalBackdrop">
        <div class="rb-view-all-modal" id="rbUnitsModalBox"></div>
    </div>

    {{-- Available units, pre-rendered server-side (same $availableUnits used by #unitSelect
         above) so booking-drawer.js never has to re-fetch it — it filters/sorts client-side
         per booking's truck_type_id. --}}
    <script type="application/json" id="rbAvailableUnitsJson">{!! json_encode($availableUnits ?? [], JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

@endsection

@push('scripts')
    <script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.browser_key') }}"></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="{{ asset('dispatcher/js/dispatch.js') }}?v={{ filemtime(public_path('dispatcher/js/dispatch.js')) }}">
    </script>
    <script src="{{ asset('dispatcher/js/booking-drawer.js') }}?v={{ filemtime(public_path('dispatcher/js/booking-drawer.js')) }}">
    </script>
    <script>
        window.RB_ROUTES = {
            assign:      "{{ route('admin.booking.assign', ':booking') }}",
            saveDraft:   "{{ route('admin.booking.save-draft', ':booking') }}",
            quoteDetails:"{{ route('admin.quotations.details', ':quotation') }}",
            quoteSend:   "{{ route('admin.quotations.send', ':quotation') }}",
            quoteCancel: "{{ route('admin.quotations.cancel', ':quotation') }}",
            quoteUpdatePrice: "{{ route('admin.quotations.update-price', ':quotation') }}",
        };
        window.RB_CSRF = "{{ csrf_token() }}";
    </script>
    <script>
        // Return Reason Action Handlers
        (function() {
            var CSRF = document.querySelector('meta[name="csrf-token"]').content;
            var serviceFeeModal = document.getElementById('serviceFeeModal');
            var customerRiskModal = document.getElementById('customerRiskModal');
            var currentBookingId = null;
            var currentCustomerId = null;

            // Handle return reason action buttons
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.rr-action-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                var action = btn.dataset.action;
                currentBookingId = btn.dataset.bookingId;
                currentCustomerId = btn.dataset.customerId;

                switch (action) {
                    case 'apply_service_fee':
                        openServiceFeeModal();
                        break;
                    case 'mark_customer_risk':
                        openCustomerRiskModal();
                        break;
                    case 'reassign_correct_unit':
                    case 'reassign_urgently':
                    case 'reassign':
                        // Trigger the existing reassign flow by dispatching a synthetic click event
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                var clickEvent = new MouseEvent('click', {
                                    bubbles: true,
                                    cancelable: true,
                                    view: window
                                });
                                acceptBtn.dispatchEvent(clickEvent);
                            }
                        }
                        break;
                    case 'mark_unit_maintenance':
                        var card = btn.closest('.incoming-card');
                        var unitId = card ? card.dataset.assignedUnit : null;
                        if (!unitId) {
                            alert('No unit assigned to this booking');
                            return;
                        }
                        if (confirm(
                                'Mark this unit for maintenance? It will be set to unavailable and require maintenance review.'
                            )) {
                            fetch('/admin-dashboard/units/' + unitId + '/maintenance', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': CSRF,
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        reason: 'Vehicle issue reported during booking ' +
                                            currentBookingId,
                                        booking_id: currentBookingId
                                    })
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data.success) {
                                        alert('Unit marked for maintenance successfully');
                                        location.reload();
                                    } else {
                                        alert(data.message || 'Failed to mark unit for maintenance');
                                    }
                                })
                                .catch(function() {
                                    alert('Error marking unit for maintenance');
                                });
                        }
                        break;
                    case 'assign_different_unit':
                        // Open reassign modal and filter out the problematic unit
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                var currentUnit = card.dataset.assignedUnit;
                                acceptBtn.click();
                                // After modal opens, disable the current unit in the dropdown
                                setTimeout(function() {
                                    var unitSelect = document.getElementById('unitSelect');
                                    if (unitSelect && currentUnit) {
                                        var option = unitSelect.querySelector('option[value="' +
                                            currentUnit + '"]');
                                        if (option) {
                                            option.disabled = true;
                                            option.textContent += ' (Unavailable - Vehicle Issue)';
                                        }
                                        // Pre-fill dispatcher note
                                        var noteField = document.getElementById('dispatcherNoteInput');
                                        if (noteField) {
                                            noteField.value =
                                                'Reassigning to different unit due to vehicle issue with previous unit.';
                                        }
                                    }
                                }, 100);
                            }
                        }
                        break;
                    case 'requote_booking':
                        // Open reassign modal with note about vehicle mismatch
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var acceptBtn = card.querySelector('.btn-accept');
                            if (acceptBtn && !acceptBtn.disabled) {
                                acceptBtn.click();
                                // Pre-fill dispatcher note
                                setTimeout(function() {
                                    var noteField = document.getElementById('dispatcherNoteInput');
                                    if (noteField) {
                                        noteField.value =
                                            'Re-quoting due to vehicle information mismatch. Please verify vehicle details with customer before dispatch.';
                                    }
                                }, 100);
                            }
                        }
                        break;
                    case 'contact_team_leader':
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var tlName = card.dataset.returnedBy || 'Team Leader';
                            alert('Contact Team Leader: ' + tlName +
                                '\n\nPlease follow up to understand the situation and determine the best course of action.'
                            );
                        }
                        break;
                    case 'contact_customer':
                    case 'attempt_contact':
                    case 'contact_for_access':
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var phone = card.querySelector('.incoming-details span:nth-child(2)');
                            if (phone) {
                                alert('Contact customer at: ' + phone.textContent.replace('Phone:', '').trim());
                            }
                        }
                        break;
                    case 'cancel_booking':
                    case 'cancel_with_reason':
                    case 'cancel_if_unresolved':
                        // Trigger the existing reject flow by dispatching a synthetic click event
                        var card = btn.closest('.incoming-card');
                        if (card) {
                            var rejectBtn = card.querySelector('.btn-reject');
                            if (rejectBtn && !rejectBtn.disabled) {
                                var clickEvent = new MouseEvent('click', {
                                    bubbles: true,
                                    cancelable: true,
                                    view: window
                                });
                                rejectBtn.dispatchEvent(clickEvent);
                            }
                        }
                        break;
                    default:
                        console.warn('Unhandled return reason action:', action);
                        alert('Action "' + action +
                            '" is not yet implemented. Please use the standard reassign or cancel buttons.');
                }
            });

            // Service Fee Modal
            function openServiceFeeModal() {
                serviceFeeModal.classList.add('show');
                serviceFeeModal.setAttribute('aria-hidden', 'false');
                document.getElementById('serviceFeeAmount').value = '';
                document.getElementById('serviceFeeReason').value = '';
                setTimeout(function() {
                    document.getElementById('serviceFeeAmount').focus();
                }, 100);
            }

            function closeServiceFeeModal() {
                serviceFeeModal.classList.remove('show');
                serviceFeeModal.setAttribute('aria-hidden', 'true');
            }

            document.getElementById('cancelServiceFeeBtn').addEventListener('click', closeServiceFeeModal);
            serviceFeeModal.addEventListener('click', function(e) {
                if (e.target === serviceFeeModal) closeServiceFeeModal();
            });

            document.getElementById('confirmServiceFeeBtn').addEventListener('click', function() {
                var amount = document.getElementById('serviceFeeAmount').value;
                var reason = document.getElementById('serviceFeeReason').value;

                if (!amount || !reason) {
                    alert('Please fill in all fields');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Applying...';

                fetch('/admin-dashboard/booking/' + currentBookingId + '/service-fee', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            service_fee_amount: amount,
                            service_fee_reason: reason,
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            alert(data.message);
                            closeServiceFeeModal();
                            location.reload();
                        } else {
                            alert(data.message || 'Failed to apply service fee');
                        }
                    })
                    .catch(function() {
                        alert('Error applying service fee');
                    })
                    .finally(function() {
                        document.getElementById('confirmServiceFeeBtn').disabled = false;
                        document.getElementById('confirmServiceFeeBtn').textContent = 'Apply Fee';
                    });
            });

            // Customer Risk Modal
            function openCustomerRiskModal() {
                customerRiskModal.classList.add('show');
                customerRiskModal.setAttribute('aria-hidden', 'false');
                document.getElementById('riskLevel').value = '';
                document.getElementById('riskReason').value = '';
                setTimeout(function() {
                    document.getElementById('riskLevel').focus();
                }, 100);
            }

            function closeCustomerRiskModal() {
                customerRiskModal.classList.remove('show');
                customerRiskModal.setAttribute('aria-hidden', 'true');
            }

            document.getElementById('cancelRiskBtn').addEventListener('click', closeCustomerRiskModal);
            customerRiskModal.addEventListener('click', function(e) {
                if (e.target === customerRiskModal) closeCustomerRiskModal();
            });

            document.getElementById('confirmRiskBtn').addEventListener('click', function() {
                var level = document.getElementById('riskLevel').value;
                var reason = document.getElementById('riskReason').value;

                if (!level || !reason) {
                    alert('Please fill in all fields');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Marking...';

                fetch('/admin-dashboard/booking/' + currentBookingId + '/mark-risk', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            risk_level: level,
                            risk_reason: reason,
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            alert(data.message);
                            closeCustomerRiskModal();
                            location.reload();
                        } else {
                            alert(data.message || 'Failed to mark customer risk');
                        }
                    })
                    .catch(function() {
                        alert('Error marking customer risk');
                    })
                    .finally(function() {
                        document.getElementById('confirmRiskBtn').disabled = false;
                        document.getElementById('confirmRiskBtn').textContent = 'Mark Customer';
                    });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeServiceFeeModal();
                    closeCustomerRiskModal();
                }
            });
        })();
    </script>

    <script>
        /* ── Complete Job modal ── */
        (function() {
            var CSRF = document.querySelector('meta[name="csrf-token"]').content;
            var modal = document.getElementById('completeJobModal');
            var okBtn = document.getElementById('completeJobOk');
            var cancel = document.getElementById('completeJobCancel');
            var closeX = document.getElementById('completeJobClose');

            var _pendingUrl = null;
            var _pendingCard = null;
            var _pendingFinalTotal = 0;
            var _pendingIsCash = false;

            function fmt(n) {
                var v = parseFloat(n) || 0;
                return '₱' + v.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function setText(id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = (val !== undefined && val !== null && val !== '') ? val : '—';
            }

            function show(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = '';
            }

            function hide(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            }

            function openModal(btn) {
                var card = btn.closest('.incoming-card');
                var ds = card ? card.dataset : {};

                // ── Price summary ──
                var finalTotal = parseFloat(ds.finalTotal || ds.currentPrice || 0);
                var baseRate = parseFloat(ds.baseRate || ds.truckTypeBaseRate || 0);
                var distanceFee = parseFloat(ds.distanceFeeAmount || ds.distanceFee || 0);
                var discountPct = parseFloat(ds.discountPercentage || 0);

                var vatAmount = parseFloat(ds.vatAmount || 0);

                setText('cjTotalBig', fmt(finalTotal));
                setText('cjBaseRate', fmt(baseRate));
                setText('cjDistanceFee', fmt(distanceFee) + (ds.distanceKm ? ' (' + parseFloat(ds.distanceKm).toFixed(
                    1) + ' km)' : ''));
                setText('cjVat', vatAmount > 0 ? fmt(vatAmount) : '—');
                setText('cjFinalTotal', fmt(finalTotal));

                var discountRow = document.getElementById('cjDiscountRow');
                var discountVal = document.getElementById('cjDiscount');
                if (discountPct > 0) {
                    setText('cjDiscount', '-' + discountPct + '%' + (ds.discountReason ? ' · ' + ds.discountReason :
                        ''));
                    if (discountRow) discountRow.style.display = 'block';
                    if (discountVal) discountVal.style.display = 'block';
                } else {
                    if (discountRow) discountRow.style.display = 'none';
                    if (discountVal) discountVal.style.display = 'none';
                }

                setText('cjRefBadge', '#' + (ds.jobCode || btn.dataset.ref || '—'));

                setText('cjCustomerName', ds.customerName || btn.dataset.customer);
                setText('cjCustomerType', ds.customerType);
                setText('cjCustomerPhone', ds.customerPhone);
                setText('cjCustomerEmail', ds.customerEmail);
                setText('cjPickup', ds.pickup);
                setText('cjDropoff', ds.dropoff);

                setText('cjPaymentMode', ds.paymentMethodLabel || '—');
                setText('cjPaymentStatus', ds.paymentStatusLabel || '—');
                setText('cjPaymongoRef', ds.paymongoRef || '—');

                _pendingFinalTotal = finalTotal;
                _pendingIsCash = !ds.paymentMethod || ds.paymentMethod === 'cash';
                setText('cjCashReceivedDisplay', ds.cashReceived ? fmt(ds.cashReceived) : '—');
                if (_pendingIsCash) {
                    show('cjCashRow');
                } else {
                    hide('cjCashRow');
                }

                var proofUrls = [];
                try {
                    proofUrls = JSON.parse(ds.paymentProofUrl || '[]');
                } catch (e) {
                    proofUrls = [];
                }
                if (!Array.isArray(proofUrls)) proofUrls = proofUrls ? [proofUrls] : [];
                var proofContainer = document.getElementById('cjProofImagesContainer');
                if (proofContainer) {
                    proofContainer.innerHTML = '';
                    proofUrls.forEach(function(url) {
                        var a = document.createElement('a');
                        a.href = url;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.style.cssText =
                            'flex:1 1 calc(50% - 4px);min-width:100px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc;display:block;';
                        var img = document.createElement('img');
                        img.src = url;
                        img.alt = 'Payment proof';
                        img.style.cssText = 'width:100%;max-height:180px;object-fit:contain;display:block;';
                        a.appendChild(img);
                        proofContainer.appendChild(a);
                    });
                }
                if (proofUrls.length > 0) {
                    show('cjProofRow');
                } else {
                    hide('cjProofRow');
                }

                setText('cjUnitName', ds.unitName);
                setText('cjUnitPlate', ds.unitPlate);
                setText('cjTruckType', ds.truckType);
                setText('cjTruckBaseRate', ds.truckTypeBaseRate ? fmt(ds.truckTypeBaseRate) : '—');
                setText('cjTlName', ds.tlName);
                setText('cjDriverName', ds.driverName);

                var vehicleUrl = ds.vehicleImageUrl || '';
                if (vehicleUrl) {
                    document.getElementById('cjVehicleImg').src = vehicleUrl;
                    document.getElementById('cjVehicleLink').href = vehicleUrl;
                    show('cjVehiclePhotoWrap');
                } else {
                    hide('cjVehiclePhotoWrap');
                }

                _pendingUrl = btn.dataset.confirmUrl;
                _pendingCard = card;

                modal.hidden = false;
                modal.style.display = 'flex';
                okBtn.disabled = false;
                okBtn.innerHTML =
                    'Mark as Completed';
                okBtn.focus();
            }

            function closeModal() {
                modal.hidden = true;
                modal.style.display = 'none';
                _pendingUrl = null;
                _pendingCard = null;
            }

            function showToast(msg, ok) {
                var t = document.createElement('div');
                t.textContent = msg;
                t.style.cssText =
                    'position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;font-size:13px;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.15);animation:tl-slide-in .25s ease;background:' +
                    (ok ? '#09090b' : '#dc2626');
                document.body.appendChild(t);
                setTimeout(function() {
                    t.remove();
                }, 4000);
            }

            /* Delegate click from any .btn-complete-job button */
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.btn-complete-job');
                if (btn) openModal(btn);
            });

            cancel.addEventListener('click', closeModal);
            if (closeX) closeX.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !modal.hidden) closeModal();
            });

            okBtn.addEventListener('click', function() {
                if (!_pendingUrl) return;
                okBtn.disabled = true;
                okBtn.innerHTML = 'Completing…';

                var controller = new AbortController();
                var timeoutId  = setTimeout(function() { controller.abort(); }, 45000);

                var pendingCard = _pendingCard;

                fetch(_pendingUrl, {
                        method: 'POST',
                        signal: controller.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                        },
                        body: JSON.stringify({}),
                    })
                    .then(function(r) {
                        clearTimeout(timeoutId);
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'Failed to complete job.');

                        showToast(data.message || 'Job completed. Receipt will be sent to customer.', true);
                        closeModal();

                        if (pendingCard) {
                            pendingCard.style.transition = 'opacity .35s';
                            pendingCard.style.opacity = '0';
                            setTimeout(function() {
                                if (pendingCard) pendingCard.remove();
                                if (typeof window.applyDispatchQueueFilter === 'function') {
                                    window.applyDispatchQueueFilter(
                                        document.querySelector('.queue-filter-btn.is-active')
                                        ?.dataset.filter || 'ready_completion'
                                    );
                                }
                            }, 360);
                        }
                    })
                    .catch(function(err) {
                        clearTimeout(timeoutId);
                        var msg = err.name === 'AbortError'
                            ? 'Request timed out. Please refresh the page to confirm.'
                            : (err.message || 'Something went wrong.');
                        showToast(msg, false);
                        okBtn.disabled = false;
                        okBtn.innerHTML = 'Mark as Completed';
                    });
            });
        })();
    </script>
@endpush
