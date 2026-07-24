<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ChequeReconciliationService
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    public function pullDailyFeed(): array
    {
        $url = env('BANK_CHEQUE_STATEMENT_FEED_URL');
        if (!$url) {
            return [];
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken((string) env('BANK_CHEQUE_STATEMENT_FEED_TOKEN'))
            ->get($url, ['date' => now()->toDateString()]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data', $response->json() ?: []);
    }

    public function reconcileEntries(array $entries, ?User $systemUser = null): array
    {
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($entries as $entry) {
            try {
                $reference = trim((string) Arr::get($entry, 'deposit_reference', ''));
                $paymentId = Arr::get($entry, 'payment_id');
                $payment = null;

                if ($paymentId) {
                    $payment = Payment::query()->find($paymentId);
                }

                if (!$payment && $reference !== '') {
                    $payment = Payment::query()
                        ->where('cheque_deposit_reference', $reference)
                        ->latest('payment_id')
                        ->first();
                }

                if (!$payment || strtolower((string) $payment->payment_method) !== 'cheque') {
                    $skipped++;
                    continue;
                }

                $status = strtolower(trim((string) Arr::get($entry, 'status', '')));
                $payload = [
                    'transition_ip' => Arr::get($entry, 'source_ip', '127.0.0.1'),
                    'cheque_clearing_reference' => Arr::get($entry, 'clearing_reference'),
                    'cheque_return_code' => Arr::get($entry, 'return_code'),
                    'cheque_return_reason' => Arr::get($entry, 'return_reason'),
                ];

                $actor = $systemUser ?? User::query()->where('email', env('BANK_RECONCILIATION_SYSTEM_USER_EMAIL'))->first();
                if (!$actor) {
                    $skipped++;
                    continue;
                }

                if (in_array($status, ['cleared', 'paid'], true) && $payment->cheque_status !== 'cleared') {
                    $payload['cheque_clearing_reference'] = $payload['cheque_clearing_reference'] ?: ('BANK-' . now()->format('YmdHis'));
                    $this->paymentService->clearCheque($payment, $actor, $payload);
                    $updated++;
                    continue;
                }

                if (in_array($status, ['returned', 'bounced', 'failed'], true) && $payment->cheque_status !== 'returned') {
                    $payload['cheque_return_code'] = $payload['cheque_return_code'] ?: 'other';
                    $this->paymentService->returnCheque($payment, $actor, $payload);
                    $updated++;
                    continue;
                }

                $skipped++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
