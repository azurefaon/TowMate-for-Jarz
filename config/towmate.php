<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team Leader Demo Arrival
    |--------------------------------------------------------------------------
    |
    | When enabled, the Team Leader mobile app's "Demo Arrival" action is
    | allowed to skip the physical GPS proximity check for an arrival-claim
    | status transition (arrived_pickup / arrived_dropoff). Every other
    | validation (auth, TL ownership, current status, allowed transition)
    | still applies unconditionally — this flag only ever affects the GPS
    | radius check. Must default to false so a production deployment rejects
    | is_demo=true even if TL_DEMO_ARRIVAL_ENABLED is never set.
    |
    */

    'demo_arrival_enabled' => env('TL_DEMO_ARRIVAL_ENABLED', false),

];
