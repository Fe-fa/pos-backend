<?php

use App\Models\Billing;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('bill.{billingId}', function ($user, $billingId) {
    return Billing::query()->where('billing_id', $billingId)->exists();
});

Broadcast::channel('terminal.{terminalId}', function ($user, $terminalId) {
    return !empty($terminalId);
});

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    return !empty($storeId);
});
