<?php

namespace App\Jobs;

use App\Services\Mpesa\RealtimeC2BPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaConfirmationAfterResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function handle(RealtimeC2BPaymentService $service): void
    {
        try {
            $service->processIncomingConfirmation($this->payload);
        } catch (\Throwable $exception) {
            Log::error('[Mpesa Realtime] Deferred confirmation processing failed', [
                'message' => $exception->getMessage(),
                'payload' => $this->payload,
            ]);
        }
    }
}
