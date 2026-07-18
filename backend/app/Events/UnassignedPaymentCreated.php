<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UnassignedPaymentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $storeId, public array $payload)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('store.' . $this->storeId)];
    }

    public function broadcastAs(): string
    {
        return 'unassigned.payment.created';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
