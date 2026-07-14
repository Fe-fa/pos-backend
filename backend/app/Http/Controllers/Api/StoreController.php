<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreRequest;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Http\Requests\Store\UpdateStoreSettingsRequest;
use App\Models\DocumentSequence;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    use AuthorizesPermission;

    private const DOCUMENT_SEQUENCE_DEFAULTS = [
        'receipt' => [
            'prefix' => 'REC-',
            'suffix' => '',
            'last_number' => 0,
        ],
        'invoice' => [
            'prefix' => 'INV-',
            'suffix' => '',
            'last_number' => 0,
        ],
        'order' => [
            'prefix' => 'ORD-',
            'suffix' => '',
            'last_number' => 0,
        ],
        'packing_slip' => [
            'prefix' => 'PKG-',
            'suffix' => '',
            'last_number' => 0,
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.view')) return $error;

        $user = $request->user();
        $perPage = max(1, min((int) $request->get('per_page', 10), 100));

        $query = Store::query()->withCount('assignedUsers');

        if (! $user->isAdmin()) {
            $allowedStoreIds = $user->stores()->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->values();

            $query->whereIn('store_id', $allowedStoreIds);
        }

        $query->when($request->search, function ($q, $search) {
            $q->where('store_name', 'like', '%' . trim($search) . '%');
        });

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderBy('store_name');
        $stores = $query->paginate($perPage);

        return response()->json([
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) return $error;

        $store = Store::create([
            ...$request->validated(),
            'settings' => $this->defaultSettings(),
        ]);

        return response()->json([
            'message' => 'Store created successfully.',
            'data' => $store,
        ], 201);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.view')) return $error;

        if (! $this->canAccessStore($request->user(), $store->store_id)) {
            return response()->json(['message' => 'You do not have access to this store.'], 403);
        }

        $store->loadCount('assignedUsers');

        return response()->json([
            'data' => $store,
        ]);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) return $error;

        $store->update($request->validated());

        return response()->json([
            'message' => 'Store updated successfully.',
            'data' => $store->fresh(),
        ]);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) return $error;

        $store->update(['is_active' => false]);

        return response()->json([
            'message' => 'Store deactivated successfully.',
        ]);
    }

    public function settings(Request $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.view')) return $error;

        if (! $this->canAccessStore($request->user(), $store->store_id)) {
            return response()->json(['message' => 'You do not have access to this store.'], 403);
        }

        $store->loadMissing('documentSequences');

        return response()->json([
            'message' => 'Store settings retrieved successfully.',
            'data' => [
                'settings' => array_replace($this->defaultSettings(), $store->settings ?? []),
                'document_sequences' => $this->mapDocumentSequences($store),
                'mpesa' => $this->serializeMpesaSettings($store),
            ],
        ]);
    }

    public function updateSettings(UpdateStoreSettingsRequest $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) return $error;

        $validated = $request->validated();
        $incomingSettings = $validated['settings'] ?? [];
        $incomingSequences = $validated['document_sequences'] ?? [];
        $incomingMpesa = $validated['mpesa'] ?? [];

        DB::transaction(function () use ($store, $incomingSettings, $incomingSequences, $incomingMpesa) {
            if ($incomingSettings) {
                $store->update([
                    'settings' => array_replace(
                        $this->defaultSettings(),
                        $store->settings ?? [],
                        $incomingSettings
                    ),
                ]);
            }

            if ($incomingMpesa) {
                $store->update($this->mapMpesaColumns($incomingMpesa));
            }

            foreach (array_keys(self::DOCUMENT_SEQUENCE_DEFAULTS) as $documentType) {
                if (! array_key_exists($documentType, $incomingSequences)) {
                    continue;
                }

                $sequenceData = $incomingSequences[$documentType] ?? [];
                $defaults = self::DOCUMENT_SEQUENCE_DEFAULTS[$documentType];

                DocumentSequence::updateOrCreate(
                    [
                        'store_id' => $store->store_id,
                        'document_type' => $documentType,
                    ],
                    [
                        'prefix' => $sequenceData['prefix'] ?? $defaults['prefix'],
                        'suffix' => $sequenceData['suffix'] ?? $defaults['suffix'],
                        'last_number' => (int) ($sequenceData['last_number'] ?? $defaults['last_number']),
                    ]
                );
            }
        });

        $store->refresh()->load('documentSequences');

        return response()->json([
            'message' => 'Store settings updated successfully.',
            'data' => [
                'settings' => array_replace($this->defaultSettings(), $store->settings ?? []),
                'document_sequences' => $this->mapDocumentSequences($store),
                'mpesa' => $this->serializeMpesaSettings($store),
                'store' => $store,
            ],
        ]);
    }

    private function canAccessStore($user, int $storeId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return (int) $user->default_store_id === $storeId
            || $user->stores()->where('stores.store_id', $storeId)->exists();
    }

    private function defaultSettings(): array
    {
        return [
            'default_vat_rate' => 15,
            'low_stock_alert' => 5,
            'spacious_layout' => true,
            'show_product_images' => true,
            'receipt_layout' => 'default',
            'receipt_header' => '',
            'invoice_header' => '',
            'receipt_footer' => 'Thank you for your purchase.',
            'invoice_footer' => 'Goods once sold are not returnable.',
            'show_barcode' => true,
            'show_qrcode' => true,
            'show_vat_summary' => true,
            'show_customer_on_print' => true,
            'show_cashier_on_print' => true,
            'show_logo_on_print' => true,
            'show_store_contacts_on_print' => true,
            'show_store_pin_on_print' => true,
            'show_payment_method_on_print' => true,
            'paper_width' => 80,
            'print_delay_ms' => 300,
        ];
    }

    private function mapDocumentSequences(Store $store): array
    {
        $store->loadMissing('documentSequences');
        $indexed = $store->documentSequences->keyBy('document_type');

        $mapped = [];

        foreach (self::DOCUMENT_SEQUENCE_DEFAULTS as $documentType => $defaults) {
            $sequence = $indexed->get($documentType);
            $mapped[$documentType] = [
                'prefix' => $sequence?->prefix ?? $defaults['prefix'],
                'suffix' => $sequence?->suffix ?? $defaults['suffix'],
                'last_number' => (int) ($sequence?->last_number ?? $defaults['last_number']),
            ];
        }

        return $mapped;
    }

    private function serializeMpesaSettings(Store $store): array
    {
        return [
            'enabled' => (bool) $store->mpesa_enabled,
            'environment' => $store->mpesa_environment ?: 'sandbox',
            'shortcode_type' => $store->mpesa_shortcode_type ?: 'paybill',
            'shortcode' => $store->mpesa_shortcode ?? '',
            'till_number' => $store->mpesa_till_number ?? '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'passkey' => '',
            'callback_base_url' => $store->mpesa_callback_base_url ?? '',
            'account_reference_prefix' => $store->mpesa_account_reference_prefix ?? '',
            'consumer_key_set' => (bool) $store->mpesa_consumer_key_set,
            'consumer_secret_set' => (bool) $store->mpesa_consumer_secret_set,
            'passkey_set' => (bool) $store->mpesa_passkey_set,
        ];
    }

    private function mapMpesaColumns(array $mpesa): array
    {
        $updates = [];

        $directMap = [
            'enabled' => 'mpesa_enabled',
            'environment' => 'mpesa_environment',
            'shortcode_type' => 'mpesa_shortcode_type',
            'shortcode' => 'mpesa_shortcode',
            'till_number' => 'mpesa_till_number',
            'callback_base_url' => 'mpesa_callback_base_url',
            'account_reference_prefix' => 'mpesa_account_reference_prefix',
        ];

        foreach ($directMap as $inputKey => $column) {
            if (! array_key_exists($inputKey, $mpesa)) {
                continue;
            }

            $value = $mpesa[$inputKey];

            if (in_array($inputKey, ['shortcode', 'till_number', 'callback_base_url', 'account_reference_prefix'], true)) {
                $value = filled($value) ? trim((string) $value) : null;
            }

            $updates[$column] = $value;
        }

        foreach ([
            'consumer_key' => 'mpesa_consumer_key',
            'consumer_secret' => 'mpesa_consumer_secret',
            'passkey' => 'mpesa_passkey',
        ] as $inputKey => $column) {
            if (! array_key_exists($inputKey, $mpesa)) {
                continue;
            }

            $value = trim((string) ($mpesa[$inputKey] ?? ''));
            if ($value === '') {
                continue;
            }

            $updates[$column] = $value;
        }

        return $updates;
    }
}
