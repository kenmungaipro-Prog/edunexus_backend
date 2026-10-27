<?php

return [
    'dispatch_phone' => env('TRANSPORT_DISPATCH_PHONE'),
    'off_route_radius_buffer_meters' => env('TRANSPORT_OFF_ROUTE_BUFFER_METERS', 500),
    'demo_vehicle_id' => env('TRANSPORT_DEMO_VEHICLE_ID'),
    'traccar_webhook_token' => env('TRACCAR_WEBHOOK_TOKEN'),
];
