<?php

namespace App\Services\Mpesa;

use App\Models\MpesaAccountBalance;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MpesaBalanceService
{
    public function resolveCredentialsForStore(Store $store): array
    {
        $decrypt = static function (?string $value): ?string {
            if (!$value) {
                return null;
            }

            try {
                return decrypt($value);
            } catch (\Throwable) {
                return $value;
            }
        };

        $environment = (string) ($store->mpesa_environment ?? config('mpesa.environment', 'sandbox'));

        return [
            'consumer_key' => $decrypt($store->mpesa_consumer_key ?? null) ?? config('mpesa.balance.consumer_key') ?? config('mpesa.b2c.consumer_key') ?? config('mpesa.consumer_key'),
            'consumer_secret' => $decrypt($store->mpesa_consumer_secret ?? null) ?? config('mpesa.balance.consumer_secret') ?? config('mpesa.b2c.consumer_secret') ?? config('mpesa.consumer_secret'),
            'initiator_name' => $decrypt($store->mpesa_initiator_name ?? null) ?? config('mpesa.balance.initiator_name') ?? config('mpesa.transaction_status.initiator_name'),
            'security_credential' => $decrypt($store->mpesa_security_credential ?? null) ?? config('mpesa.balance.security_credential') ?? config('mpesa.transaction_status.security_credential'),
            'shortcode' => (string) ($store->mpesa_b2b_shortcode ?? $store->mpesa_shortcode ?? config('mpesa.balance.shortcode') ?? config('mpesa.b2c.sender_shortcode') ?? config('mpesa.shortcode')),
            'identifier_type' => (string) config('mpesa.balance.identifier_type', '4'),
            'environment' => $environment,
            'callback_base_url' => (string) ($store->mpesa_callback_base_url ?? config('mpesa.callback_base_url') ?? config('app.url')),
            'callback_shared_secret' => (string) config('mpesa.callback_shared_secret', ''),
            'preferred_account_type' => (string) config('mpesa.balance.preferred_account', 'utility'),
            'max_age_seconds' => max(30, (int) config('mpesa.balance.max_age_seconds', 300)),
            'auto_request_if_missing' => (bool) config('mpesa.balance.auto_request_if_missing', true),
            'auto_request_if_stale' => (bool) config('mpesa.balance.auto_request_if_stale', true),
            'require_sufficient_before_payout' => (bool) config('mpesa.balance.require_sufficient_before_payout', true),
        ];
    }

    public function latestForStore(Store|int $store): ?MpesaAccountBalance
    {
        $storeId = $store instanceof Store ? $store->store_id : $store;

        return MpesaAccountBalance::query()
            ->where('store_id', $storeId)
            ->latest('mpesa_account_balance_id')
            ->first();
    }

    public function requestForStore(Store $store, array $options = []): MpesaAccountBalance
    {
        $creds = $this->resolveCredentialsForStore($store);

        foreach (['consumer_key', 'consumer_secret', 'initiator_name', 'security_credential', 'shortcode'] as $key) {
            if (blank($creds[$key] ?? null)) {
                throw new RuntimeException("M-Pesa Account Balance is missing credential [{$key}] for store {$store->store_id}.");
            }
        }

        $force = (bool) ($options['force'] ?? false);
        if (!$force) {
            $pending = MpesaAccountBalance::query()
                ->where('store_id', $store->store_id)
                ->whereIn('status', ['pending', 'sent'])
                ->where('requested_at', '>=', now()->subSeconds(90))
                ->latest('mpesa_account_balance_id')
                ->first();

            if ($pending) {
                return $pending;
            }
        }

        $client = new DarajaClient($creds);
        $balance = MpesaAccountBalance::create([
            'store_id' => $store->store_id,
            'shortcode' => $creds['shortcode'],
            'identifier_type' => $creds['identifier_type'],
            'preferred_account_type' => $creds['preferred_account_type'],
            'status' => 'pending',
            'requested_at' => now(),
            'request_payload' => [
                'reason' => (string) ($options['reason'] ?? 'manual_request'),
                'required_amount' => isset($options['required_amount']) ? round((float) $options['required_amount'], 2) : null,
                'context' => $options['context'] ?? null,
            ],
        ]);

        try {
            $response = $client->accountBalance([
                'initiator' => $creds['initiator_name'],
                'security_credential' => $creds['security_credential'],
                'shortcode' => $creds['shortcode'],
                'identifier_type' => $creds['identifier_type'],
                'result_url' => $this->resultUrl($creds),
                'timeout_url' => $this->timeoutUrl($creds),
                'remarks' => (string) ($options['remarks'] ?? 'Account balance preflight'),
            ]);

            $balance->update([
                'status' => 'sent',
                'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
                'conversation_id' => $response['ConversationID'] ?? null,
                'result_code' => (string) ($response['ResponseCode'] ?? '0'),
                'result_desc' => $response['ResponseDescription'] ?? 'Account balance request accepted.',
                'callback_payload' => ['request_ack' => $response],
            ]);
        } catch (\Throwable $e) {
            $balance->update([
                'status' => 'failed',
                'result_code' => 'BALANCE_REQUEST_ERROR',
                'result_desc' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $balance->fresh();
    }

    public function prepareBalanceForPayout(Store $store, float $requiredAmount, array $context = []): array
    {
        $requiredAmount = round(max(0, $requiredAmount), 2);
        $creds = $this->resolveCredentialsForStore($store);

        if (!$creds['require_sufficient_before_payout']) {
            return [
                'ok' => true,
                'reason' => 'skipped',
                'message' => 'Balance preflight is disabled.',
                'required_amount' => $requiredAmount,
                'balance' => $this->serializeBalance($this->latestForStore($store)),
            ];
        }

        $latest = $this->latestForStore($store);
        if ($latest && in_array($latest->status, ['pending', 'sent'], true) && $latest->requested_at?->gte(now()->subSeconds(90))) {
            return [
                'ok' => false,
                'reason' => 'balance_pending',
                'http_status' => 202,
                'message' => 'A fresh M-Pesa account-balance check is already in progress. Retry the payout after the callback arrives.',
                'required_amount' => $requiredAmount,
                'tracking_reference' => $latest->originator_conversation_id ?: $latest->conversation_id,
                'balance' => $this->serializeBalance($latest),
            ];
        }

        if ($latest && $latest->isFresh($creds['max_age_seconds'])) {
            $available = (float) ($latest->available_balance ?? 0);
            if ($available + 0.0001 >= $requiredAmount) {
                return [
                    'ok' => true,
                    'reason' => 'fresh_sufficient',
                    'message' => 'Cached M-Pesa balance is sufficient for payout.',
                    'required_amount' => $requiredAmount,
                    'available_balance' => $available,
                    'balance' => $this->serializeBalance($latest),
                ];
            }

            return [
                'ok' => false,
                'reason' => 'insufficient_balance',
                'http_status' => 409,
                'message' => sprintf(
                    'Cached M-Pesa balance is insufficient. Required %.2f KES, available %.2f KES.',
                    $requiredAmount,
                    $available
                ),
                'required_amount' => $requiredAmount,
                'available_balance' => $available,
                'balance' => $this->serializeBalance($latest),
            ];
        }

        $shouldRequest = !$latest
            ? $creds['auto_request_if_missing']
            : $creds['auto_request_if_stale'];

        if ($shouldRequest) {
            $request = $this->requestForStore($store, [
                'reason' => 'preflight_payout',
                'required_amount' => $requiredAmount,
                'context' => $context,
                'remarks' => 'Preflight balance check before payout',
            ]);

            return [
                'ok' => false,
                'reason' => $latest ? 'stale_balance_refresh_requested' : 'balance_refresh_requested',
                'http_status' => 202,
                'message' => 'A fresh M-Pesa account-balance request has been sent to Safaricom. Retry the payout once the balance callback is received.',
                'required_amount' => $requiredAmount,
                'tracking_reference' => $request->originator_conversation_id ?: $request->conversation_id,
                'balance' => $this->serializeBalance($request),
            ];
        }

        return [
            'ok' => false,
            'reason' => 'stale_or_missing_balance',
            'http_status' => 409,
            'message' => 'No fresh M-Pesa account-balance snapshot is available for payout preflight.',
            'required_amount' => $requiredAmount,
            'balance' => $this->serializeBalance($latest),
        ];
    }

    public function handleResultCallback(array $payload): ?MpesaAccountBalance
    {
        $result = data_get($payload, 'Result', []);
        $params = $this->mapParameters(data_get($result, 'ResultParameters.ResultParameter', []));
        $originatorConversationId = (string) data_get($result, 'OriginatorConversationID', '');
        $conversationId = (string) data_get($result, 'ConversationID', '');
        $resultCode = (int) data_get($result, 'ResultCode', 1);
        $resultDesc = (string) data_get($result, 'ResultDesc', 'Unknown result');
        $rawBalance = (string) ($params['AccountBalance'] ?? '');

        return DB::transaction(function () use ($payload, $originatorConversationId, $conversationId, $resultCode, $resultDesc, $rawBalance, $params) {
            $balance = MpesaAccountBalance::query()
                ->when($originatorConversationId !== '', fn ($q) => $q->where('originator_conversation_id', $originatorConversationId))
                ->when($originatorConversationId === '' && $conversationId !== '', fn ($q) => $q->where('conversation_id', $conversationId))
                ->lockForUpdate()
                ->latest('mpesa_account_balance_id')
                ->first();

            if (!$balance) {
                Log::warning('[Mpesa][Balance] Callback could not find balance request', [
                    'originator_conversation_id' => $originatorConversationId,
                    'conversation_id' => $conversationId,
                ]);
                return null;
            }

            $rows = $this->parseBalanceRows($rawBalance);
            $preferredType = (string) ($balance->preferred_account_type ?: config('mpesa.balance.preferred_account', 'utility'));
            $selectedRow = $this->pickPreferredRow($rows, $preferredType);
            $working = $this->sumBalancesByType($rows, 'working');
            $utility = $this->sumBalancesByType($rows, 'utility');

            $updates = [
                'conversation_id' => $conversationId ?: $balance->conversation_id,
                'originator_conversation_id' => $originatorConversationId ?: $balance->originator_conversation_id,
                'callback_payload' => $payload,
                'result_code' => (string) $resultCode,
                'result_desc' => $resultDesc,
                'received_at' => now(),
                'raw_balance_text' => $rawBalance !== '' ? $rawBalance : $balance->raw_balance_text,
                'working_balance' => $working,
                'utility_balance' => $utility,
                'meta' => [
                    ...($balance->meta ?? []),
                    'balance_rows' => $rows,
                    'result_parameters' => $params,
                ],
            ];

            if ($selectedRow) {
                $updates['account_name'] = $selectedRow['account_name'];
                $updates['currency_code'] = $selectedRow['currency_code'];
                $updates['available_balance'] = $selectedRow['available_balance'];
            }

            if ($resultCode === 0) {
                $updates['status'] = 'success';
            } else {
                $updates['status'] = 'failed';
            }

            $balance->update($updates);
            $balance = $balance->fresh();

            if ($balance->status === 'success' && $balance->store) {
                $this->syncStoreCachedFields($balance->store, $balance);
            }

            return $balance;
        });
    }

    public function handleTimeoutCallback(array $payload): ?MpesaAccountBalance
    {
        $originatorConversationId = (string) (
            data_get($payload, 'OriginatorConversationID')
            ?: data_get($payload, 'Result.OriginatorConversationID')
            ?: ''
        );
        $conversationId = (string) (
            data_get($payload, 'ConversationID')
            ?: data_get($payload, 'Result.ConversationID')
            ?: ''
        );

        return DB::transaction(function () use ($payload, $originatorConversationId, $conversationId) {
            $balance = MpesaAccountBalance::query()
                ->when($originatorConversationId !== '', fn ($q) => $q->where('originator_conversation_id', $originatorConversationId))
                ->when($originatorConversationId === '' && $conversationId !== '', fn ($q) => $q->where('conversation_id', $conversationId))
                ->lockForUpdate()
                ->latest('mpesa_account_balance_id')
                ->first();

            if (!$balance) {
                return null;
            }

            $balance->update([
                'status' => 'timeout',
                'result_code' => 'TIMEOUT',
                'result_desc' => 'Safaricom account-balance timeout callback received.',
                'callback_payload' => $payload,
                'received_at' => now(),
            ]);

            return $balance->fresh();
        });
    }

    public function serializeBalance(?MpesaAccountBalance $balance): ?array
    {
        if (!$balance) {
            return null;
        }

        return [
            'mpesa_account_balance_id' => $balance->mpesa_account_balance_id,
            'store_id' => $balance->store_id,
            'shortcode' => $balance->shortcode,
            'status' => $balance->status,
            'result_code' => $balance->result_code,
            'result_desc' => $balance->result_desc,
            'account_name' => $balance->account_name,
            'preferred_account_type' => $balance->preferred_account_type,
            'currency_code' => $balance->currency_code,
            'available_balance' => $balance->available_balance !== null ? (float) $balance->available_balance : null,
            'working_balance' => $balance->working_balance !== null ? (float) $balance->working_balance : null,
            'utility_balance' => $balance->utility_balance !== null ? (float) $balance->utility_balance : null,
            'originator_conversation_id' => $balance->originator_conversation_id,
            'conversation_id' => $balance->conversation_id,
            'requested_at' => $balance->requested_at?->toAtomString(),
            'received_at' => $balance->received_at?->toAtomString(),
            'raw_balance_text' => $balance->raw_balance_text,
            'meta' => $balance->meta,
        ];
    }

    private function resultUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'balance_result');
    }

    private function timeoutUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'balance_timeout');
    }

    private function callbackUrl(array $creds, string $pathKey): string
    {
        $base = rtrim((string) ($creds['callback_base_url'] ?: config('app.url')), '/');
        $path = (string) config("mpesa.callback_paths.{$pathKey}");
        if ($path === '') {
            throw new RuntimeException("Missing configured M-Pesa callback path [{$pathKey}].");
        }

        $url = $base . $path;
        $secret = (string) ($creds['callback_shared_secret'] ?? '');
        if ($secret !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . urlencode($secret);
        }

        return $url;
    }

    private function mapParameters(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            $key = (string) data_get($row, 'Key', '');
            if ($key === '') {
                continue;
            }
            $flat[$key] = data_get($row, 'Value');
        }

        return $flat;
    }

    private function parseBalanceRows(string $rawBalance): array
    {
        if ($rawBalance === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\s*&\s*/', $rawBalance) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $segment));
            $accountName = $parts[0] ?? 'Unknown Account';
            $currency = $parts[1] ?? 'KES';

            $rows[] = [
                'account_name' => $accountName,
                'account_type' => $this->classifyAccountType($accountName),
                'currency_code' => $currency,
                'available_balance' => $this->toFloat($parts[2] ?? null),
                'ledger_balance' => $this->toFloat($parts[3] ?? null),
                'reserved_balance' => $this->toFloat($parts[4] ?? null),
                'uncleared_balance' => $this->toFloat($parts[5] ?? null),
                'raw_parts' => $parts,
            ];
        }

        return $rows;
    }

    private function pickPreferredRow(array $rows, string $preferredType): ?array
    {
        if (empty($rows)) {
            return null;
        }

        $preferredType = strtolower(trim($preferredType));
        foreach ($rows as $row) {
            if (($row['account_type'] ?? 'unknown') === $preferredType) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (($row['available_balance'] ?? null) !== null) {
                return $row;
            }
        }

        return $rows[0];
    }

    private function sumBalancesByType(array $rows, string $type): ?float
    {
        $matches = array_values(array_filter($rows, fn (array $row) => ($row['account_type'] ?? 'unknown') === $type));
        if (empty($matches)) {
            return null;
        }

        return round(array_sum(array_map(fn (array $row) => (float) ($row['available_balance'] ?? 0), $matches)), 2);
    }

    private function classifyAccountType(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_contains($name, 'utility') => 'utility',
            str_contains($name, 'working') => 'working',
            str_contains($name, 'charges') => 'charges',
            default => 'unknown',
        };
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace([',', ' '], '', (string) $value);
        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function syncStoreCachedFields(Store $store, MpesaAccountBalance $balance): void
    {
        $updates = [];

        if (Schema::hasColumn('stores', 'mpesa_float_balance')) {
            $updates['mpesa_float_balance'] = $balance->available_balance;
        }
        if (Schema::hasColumn('stores', 'mpesa_utility_float_balance')) {
            $updates['mpesa_utility_float_balance'] = $balance->utility_balance ?? $balance->available_balance;
        }
        if (Schema::hasColumn('stores', 'mpesa_available_float')) {
            $updates['mpesa_available_float'] = $balance->available_balance;
        }
        if (Schema::hasColumn('stores', 'mpesa_last_balance_synced_at')) {
            $updates['mpesa_last_balance_synced_at'] = now();
        }

        if (!empty($updates)) {
            $store->forceFill($updates)->save();
        }
    }
}
