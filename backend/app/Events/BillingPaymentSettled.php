<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillingPaymentSettled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('bill.' . $this->payload['billing_id'])];
    }

    public function broadcastAs(): string
    {
        return 'billing.payment.settled';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
