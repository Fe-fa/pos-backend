<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
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

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = max(1, min((int) $request->get('per_page', 2), 100));

        $query = Store::query()
            ->withCount('assignedUsers');

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
                'last_page'    => $stores->lastPage(),
                'per_page'     => $stores->perPage(),
                'total'        => $stores->total(),
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
            'data'    => $store,
        ], 201);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
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
            'data'    => $store->fresh(),
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
        if (! $this->canAccessStore($request->user(), $store->store_id)) {
            return response()->json(['message' => 'You do not have access to this store.'], 403);
        }

        $store->loadMissing('documentSequences');

        return response()->json([
            'message' => 'Store settings retrieved successfully.',
            'data'    => [
                'settings'           => array_replace($this->defaultSettings(), $store->settings ?? []),
                'document_sequences' => $this->mapDocumentSequences($store),
            ],
        ]);
    }

    public function updateSettings(UpdateStoreSettingsRequest $request, Store $store): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.manage')) return $error;

        $validated        = $request->validated();
        $incomingSettings  = $validated['settings'] ?? [];
        $incomingSequences = $validated['document_sequences'] ?? [];

        DB::transaction(function () use ($store, $incomingSettings, $incomingSequences) {
            $store->update([
                'settings' => array_replace(
                    $this->defaultSettings(),
                    $store->settings ?? [],
                    $incomingSettings
                ),
            ]);

            foreach (['invoice', 'receipt'] as $documentType) {
                if (! array_key_exists($documentType, $incomingSequences)) {
                    continue;
                }

                $sequenceData  = $incomingSequences[$documentType] ?? [];
                $defaultPrefix = $documentType === 'receipt' ? 'REC-' : 'INV-';

                DocumentSequence::updateOrCreate(
                    [
                        'store_id'      => $store->store_id,
                        'document_type' => $documentType,
                    ],
                    [
                        'prefix'      => $sequenceData['prefix'] ?? $defaultPrefix,
                        'suffix'      => $sequenceData['suffix'] ?? '',
                        'last_number' => $sequenceData['last_number'] ?? 0,
                    ]
                );
            }
        });

        $store->refresh()->load('documentSequences');

        return response()->json([
            'message' => 'Store settings updated successfully.',
            'data'    => [
                'settings'           => array_replace($this->defaultSettings(), $store->settings ?? []),
                'document_sequences' => $this->mapDocumentSequences($store),
                'store'              => $store,
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
            'default_vat_rate'               => 15,
            'low_stock_alert'                => 5,
            'spacious_layout'                => true,
            'show_product_images'            => true,
            'receipt_header'                 => '',
            'invoice_header'                 => '',
            'receipt_footer'                 => 'Thank you for your purchase.',
            'invoice_footer'                 => 'Goods once sold are not returnable.',
            'show_barcode'                   => true,
            'show_qrcode'                    => true,
            'show_vat_summary'               => true,
            'show_customer_on_print'         => true,
            'show_cashier_on_print'          => true,
            'show_logo_on_print'             => true,
            'show_store_contacts_on_print'   => true,
            'show_store_pin_on_print'        => true,
            'show_payment_method_on_print'   => true,
            'paper_width'                    => 80,
            'print_delay_ms'                 => 300,
        ];
    }

    private function mapDocumentSequences(Store $store): array
    {
        $store->loadMissing('documentSequences');

        $indexed = $store->documentSequences->keyBy('document_type');

        return [
            'invoice' => [
                'prefix'      => $indexed->get('invoice')?->prefix ?? 'INV-',
                'suffix'      => $indexed->get('invoice')?->suffix ?? '',
                'last_number' => (int) ($indexed->get('invoice')?->last_number ?? 0),
            ],
            'receipt' => [
                'prefix'      => $indexed->get('receipt')?->prefix ?? 'REC-',
                'suffix'      => $indexed->get('receipt')?->suffix ?? '',
                'last_number' => (int) ($indexed->get('receipt')?->last_number ?? 0),
            ],
        ];
    }
}