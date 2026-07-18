<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentClaimRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $terminalId, public array $payload)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('terminal.' . $this->terminalId)];
    }

    public function broadcastAs(): string
    {
        return 'payment.claim.requested';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
