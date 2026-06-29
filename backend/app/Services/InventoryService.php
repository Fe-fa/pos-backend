<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = Inventory::query()
            ->with(['store', 'product.category'])
            ->orderBy('product_id')
            ->orderBy('created_at')
            ->orderBy('inventory_id');

        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique();

            $query->whereIn('store_id', $storeIds);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                })->orWhere('batch_no', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function paginateHistory(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = InventoryHistory::query()
            ->with(['store', 'product', 'user'])
            ->orderByDesc('inventory_history_id');

        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique();

            $query->whereIn('store_id', $storeIds);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['change_type'])) {
            $query->where('change_type', $filters['change_type']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('product_name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                })
                ->orWhere('batch_no', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function create(User $user, array $data): Inventory
    {
        return DB::transaction(function () use ($user, $data) {
            $batchNo     = $this->normalizeBatchNo($data['batch_no'] ?? null);
            $incomingQty = (int) $data['quantity'];

            if ($incomingQty <= 0) {
                abort(response()->json([
                    'message' => 'Incoming quantity must be greater than zero.',
                ], 422));
            }

            $hasExistingLayers = Inventory::query()
                ->where('store_id', $data['store_id'])
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->exists();

            $inventory = Inventory::create([
                'store_id'      => $data['store_id'],
                'product_id'    => $data['product_id'],
                'batch_no'      => $batchNo,
                'quantity'      => $incomingQty,
                'reorder_level' => $data['reorder_level'] ?? 0,
            ]);

            $changeType = $hasExistingLayers ? 'stock_in' : 'opening_stock';
            $reason     = $hasExistingLayers ? 'Stock received (new FIFO layer)' : 'Opening stock';

            $this->logHistory(
                inventory:       $inventory,
                user:            $user,
                quantityBefore:  0,
                quantityChanged: $incomingQty,
                quantityAfter:   $incomingQty,
                changeType:      $changeType,
                reason:          $reason,
                reference:       $batchNo
            );

            if (class_exists(StockMovement::class)) {
                StockMovement::create([
                    'product_id' => $inventory->product_id,
                    'store_id'   => $inventory->store_id,
                    'quantity'   => $incomingQty,
                    'type'       => $changeType,
                    'reason'     => $reason,
                    'user_id'    => $user->user_id,
                ]);
            }

            return $inventory->load(['store', 'product.category']);
        });
    }

    /**
     * Handles both "edit" (no quantity) and "restock" (quantity > 0) modes.
     *
     * - Edit mode:    quantity key is absent → only batch_no + reorder_level update, qty unchanged.
     * - Restock mode: quantity key is present → validated >= 1 by UpdateInventoryRequest,
     *                 accumulated on top of existing qty, history logged as stock_in.
     */
    public function update(User $user, Inventory $inventory, array $data): Inventory
    {
        return DB::transaction(function () use ($user, $inventory, $data) {
            $inventory = Inventory::query()
                ->whereKey($inventory->inventory_id)
                ->lockForUpdate()
                ->firstOrFail();

            $newStoreId   = $data['store_id']   ?? $inventory->store_id;
            $newProductId = $data['product_id'] ?? $inventory->product_id;

            if (
                (string) $newStoreId   !== (string) $inventory->store_id ||
                (string) $newProductId !== (string) $inventory->product_id
            ) {
                abort(response()->json([
                    'message' => 'Changing store or product on an existing FIFO layer is not allowed.',
                ], 422));
            }

            $oldQty = (int) $inventory->quantity;

            // quantity is only present for restock; absent for edit
            $hasQty = array_key_exists('quantity', $data) && $data['quantity'] !== null;
            $addQty = $hasQty ? (int) $data['quantity'] : null;

            if ($hasQty) {
                // restock — accumulate
                if ($addQty <= 0) {
                    abort(response()->json([
                        'message' => 'Quantity to add must be greater than zero.',
                    ], 422));
                }
                $newQty = $oldQty + $addQty;
            } else {
                // edit — keep existing quantity unchanged
                $newQty = $oldQty;
            }

            $batchNo = array_key_exists('batch_no', $data)
                ? $this->normalizeBatchNo($data['batch_no'])
                : $inventory->batch_no;

            $inventory->update([
                'batch_no'      => $batchNo,
                'quantity'      => $newQty,
                'reorder_level' => $data['reorder_level'] ?? $inventory->reorder_level,
            ]);

            // Only log history when stock actually changed
            if ($hasQty && $addQty > 0) {
                $this->logHistory(
                    inventory:       $inventory,
                    user:            $user,
                    quantityBefore:  $oldQty,
                    quantityChanged: $addQty,
                    quantityAfter:   $newQty,
                    changeType:      'stock_in',
                    reason:          'Stock added to existing FIFO layer',
                    reference:       $batchNo
                );

                if (class_exists(StockMovement::class)) {
                    StockMovement::create([
                        'product_id' => $inventory->product_id,
                        'store_id'   => $inventory->store_id,
                        'quantity'   => $addQty,
                        'type'       => 'stock_in',
                        'reason'     => 'Stock added to existing FIFO layer',
                        'user_id'    => $user->user_id,
                    ]);
                }
            }

            return $inventory->fresh()->load(['store', 'product.category']);
        });
    }

    /**
     * Quick Adjust: apply a signed delta (+/-) to an existing FIFO layer.
     * Positive = add stock, negative = remove stock (shrinkage, damage, correction).
     * Result quantity cannot go below zero.
     */
    public function adjust(User $user, Inventory $inventory, array $data): Inventory
    {
        return DB::transaction(function () use ($user, $inventory, $data) {
            $inventory = Inventory::query()
                ->whereKey($inventory->inventory_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldQty = (int) $inventory->quantity;
            $delta  = (int) $data['quantity'];

            if ($delta === 0) {
                abort(response()->json([
                    'message' => 'Adjustment amount cannot be zero.',
                ], 422));
            }

            $newQty = $oldQty + $delta;

            if ($newQty < 0) {
                abort(response()->json([
                    'message'          => 'Adjustment would result in negative stock. Current quantity is ' . $oldQty . '.',
                    'current_quantity' => $oldQty,
                    'adjustment'       => $delta,
                    'would_result_in'  => $newQty,
                ], 422));
            }

            $inventory->update([
                'quantity'      => $newQty,
                'reorder_level' => $data['reorder_level'] ?? $inventory->reorder_level,
            ]);

            $this->logHistory(
                inventory:       $inventory,
                user:            $user,
                quantityBefore:  $oldQty,
                quantityChanged: $delta,
                quantityAfter:   $newQty,
                changeType:      'adjustment',
                reason:          $data['reason'] ?? 'Manual stock adjustment',
                reference:       null
            );

            if (class_exists(StockMovement::class)) {
                StockMovement::create([
                    'product_id' => $inventory->product_id,
                    'store_id'   => $inventory->store_id,
                    'quantity'   => $delta,
                    'type'       => 'adjustment',
                    'reason'     => $data['reason'] ?? 'Manual stock adjustment',
                    'user_id'    => $user->user_id,
                ]);
            }

            return $inventory->fresh()->load(['store', 'product.category']);
        });
    }

    public function consumeFifo(
        User $user,
        int $storeId,
        int $productId,
        int $quantity,
        ?string $reason = null,
        ?string $reference = null,
    ): array {
        return DB::transaction(function () use ($user, $storeId, $productId, $quantity, $reason, $reference) {
            if ($quantity <= 0) {
                abort(response()->json([
                    'message' => 'Quantity must be greater than zero.',
                ], 422));
            }

            $product     = \App\Models\Product::select('product_id', 'product_name', 'sku')->find($productId);
            $productName = $product?->product_name ?? "Product #{$productId}";
            $productSku  = $product?->sku ?? '';

            $allLayers = Inventory::query()
                ->where('store_id', $storeId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->fifo()
                ->get();

            $layers       = $allLayers->where('quantity', '>', 0);
            $availableQty = (int) $layers->sum('quantity');
            $reorderLevel = (int) $allLayers->max('reorder_level');
            $sellableQty  = max($availableQty - $reorderLevel, 0);

            if ($availableQty < $quantity) {
                abort(response()->json([
                    'message'            => "Insufficient stock for \"{$productName}\".",
                    'available_quantity' => $availableQty,
                    'requested_quantity' => $quantity,
                ], 422));
            }

            if ($sellableQty < $quantity) {
                abort(response()->json([
                    'message'            => "\"{$productName}\" is below reorder level. Please restock before selling.",
                    'available_quantity' => $availableQty,
                    'reorder_level'      => $reorderLevel,
                    'sellable_quantity'  => $sellableQty,
                    'requested_quantity' => $quantity,
                    'product_name'       => $productName,
                    'sku'                => $productSku,
                ], 422));
            }

            $remaining      = $quantity;
            $consumedLayers = [];

            foreach ($layers as $layer) {
                if ($remaining <= 0) break;

                $before = (int) $layer->quantity;
                $take   = min($before, $remaining);
                $after  = $before - $take;

                $layer->update(['quantity' => $after]);

                $this->logHistory(
                    inventory:       $layer,
                    user:            $user,
                    quantityBefore:  $before,
                    quantityChanged: -$take,
                    quantityAfter:   $after,
                    changeType:      'stock_out',
                    reason:          $reason ?? 'FIFO stock out',
                    reference:       $reference
                );

                $consumedLayers[] = [
                    'inventory_id'    => $layer->inventory_id,
                    'batch_no'        => $layer->batch_no,
                    'quantity_before' => $before,
                    'quantity_taken'  => $take,
                    'quantity_after'  => $after,
                ];

                $remaining -= $take;
            }

            if (class_exists(StockMovement::class)) {
                StockMovement::create([
                    'product_id' => $productId,
                    'store_id'   => $storeId,
                    'quantity'   => -$quantity,
                    'type'       => 'stock_out',
                    'reason'     => $reason ?? 'FIFO stock out',
                    'user_id'    => $user->user_id,
                ]);
            }

            $remainingTotal = (int) Inventory::query()
                ->where('store_id', $storeId)
                ->where('product_id', $productId)
                ->sum('quantity');

            return [
                'store_id'           => $storeId,
                'product_id'         => $productId,
                'requested_quantity' => $quantity,
                'consumed_quantity'  => $quantity,
                'remaining_quantity' => $remainingTotal,
                'layers'             => $consumedLayers,
            ];
        });
    }

    public function show(Inventory $inventory): Inventory
    {
        return $inventory->load([
            'store',
            'product.category',
            'histories' => fn ($query) => $query
                ->with('user')
                ->orderByDesc('inventory_history_id'),
        ]);
    }

    public function delete(Inventory $inventory): void
    {
        if ((int) $inventory->quantity > 0) {
            abort(response()->json([
                'message' => 'Cannot delete inventory while quantity is above zero.',
            ], 422));
        }

        $inventory->delete();
    }

    private function normalizeBatchNo(?string $batchNo): ?string
    {
        if ($batchNo === null) return null;
        $batchNo = trim($batchNo);
        return $batchNo === '' ? null : $batchNo;
    }

    private function logHistory(
        Inventory $inventory,
        User $user,
        int $quantityBefore,
        int $quantityChanged,
        int $quantityAfter,
        string $changeType,
        ?string $reason = null,
        ?string $reference = null,
    ): InventoryHistory {
        return InventoryHistory::create([
            'inventory_id'     => $inventory->inventory_id,
            'store_id'         => $inventory->store_id,
            'product_id'       => $inventory->product_id,
            'batch_no'         => $inventory->batch_no,
            'quantity_before'  => $quantityBefore,
            'quantity_changed' => $quantityChanged,
            'quantity_after'   => $quantityAfter,
            'change_type'      => $changeType,
            'reference'        => $reference,
            'reason'           => $reason,
            'user_id'          => $user->user_id ?? null,
        ]);
    }
}