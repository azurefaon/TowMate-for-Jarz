{{-- Expects $roleSlug: 'dispatcher' | 'customer' | 'team-leader' | 'driver' | anything else --}}
@switch($roleSlug ?? '')
    @case('dispatcher')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4.5 13.5 A7.5 7.5 0 0 1 19.5 13.5" />
            <path d="M4.5 13.5 L4.5 17 A1.8 1.8 0 0 0 6.3 18.8 L7 18.8 L7 13.3 L4.5 13.3 Z" />
            <path d="M19.5 13.5 L19.5 17 A1.8 1.8 0 0 1 17.7 18.8 L17 18.8 L17 13.3 L19.5 13.3 Z" />
            <path d="M7 18.8 L7 20 A1.6 1.6 0 0 0 8.6 21.6 L10.8 21.6" />
        </svg>
        @break
    @case('customer')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
            <circle cx="12" cy="8" r="3.4" />
            <path d="M5.5 20 L6.8 14.8 L17.2 14.8 L18.5 20" />
        </svg>
        @break
    @case('team-leader')
        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none">
            <path d="M12 3.3 L14.4 9.1 L20.6 9.7 L15.9 13.8 L17.3 19.9 L12 16.6 L6.7 19.9 L8.1 13.8 L3.4 9.7 L9.6 9.1 Z" />
        </svg>
        @break
    @case('driver')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8" />
            <circle cx="12" cy="12" r="2" />
            <path d="M12 4 L12 8.5" />
            <path d="M6 16 L9.8 13.2" />
            <path d="M18 16 L14.2 13.2" />
        </svg>
        @break
    @default
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
            <circle cx="12" cy="8" r="3.4" />
            <path d="M5.5 20 L6.8 14.8 L17.2 14.8 L18.5 20" />
        </svg>
@endswitch
