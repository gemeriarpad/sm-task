<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Live sync updates for the dashboard, targets table and target pages. Every
// logged-in user may listen (the app has a single admin role); guests are
// rejected by the private channel's authentication.
Broadcast::channel('sync', fn (User $user) => true);
