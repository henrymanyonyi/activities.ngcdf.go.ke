{{-- Jetstream pages (profile, two-factor set-up) render inside the same admin shell. --}}
@include('layouts.admin', ['slot' => $slot, 'title' => 'Profile and security'])
