<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Product;

class BillingItemService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function addItem(Billing $billing, array $data): BillingItem
    {
        if (!$billing->is_draft && $billing->payments()->exists()) {
            abort(response()->json([
                'message' => 'Cannot modify items after payment has started.',
            ], 422));
        }

if (isset($data['unit_price']) && (float) $data['unit_price'] <= 0) {
            $existingFreeItem = $billing->items()
                ->where('product_id', $data['product_id'])
                ->where('unit_price', '<=', 0)
                ->first();

            if ($existingFreeItem) {
                $newQty = (int) $existingFreeItem->quantity + (int) ($data['quantity'] ?? 1);

                if ($newQty <= 0) {
                    $this->deleteItem($existingFreeItem);
                    return $existingFreeItem;
                }

                return $this->updateItem($existingFreeItem, [
                    'quantity'   => $newQty,
                    'unit_price' => 0,
                ]);
            }
        }

        $product = Product::query()->findOrFail($data['product_id']);

        if (!$product->is_active) {
            abort(response()->json([
                'message' => 'Selected product is inactive.',
            ], 422));
        }

        $qty = (int) $data['quantity'];
        $unitPrice = (float) ($data['unit_price'] ?? $product->price);
        $totalAmount = round($qty * $unitPrice, 2);
        $vatRate = (float) $product->vat_rate;
        $lineSubtotal = round($totalAmount / (1 + ($vatRate / 100)), 2);
        $vatAmount = round($totalAmount - $lineSubtotal, 2);

        $item = BillingItem::create([
            'billing_id' => $billing->billing_id,
            'product_id' => $product->product_id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ]);

        $this->billingService->recalculateTotals($billing->fresh());

        $this->auditLogService->log(
            'billing_item.create',
            $item,
            null,
            $item->toArray(),
            ['billing_uuid' => $billing->uuid],
            $billing->store_id
        );

        return $item->load('product.category');
    }

    public function updateItem(BillingItem $item, array $data): BillingItem
    {
        $billing = $item->billing;

        if (!$billing->is_draft && $billing->payments()->exists()) {
            abort(response()->json([
                'message' => 'Cannot modify items after payment has started.',
            ], 422));
        }

        if ($item->trashed()) {
            abort(response()->json([
                'message' => 'Cannot update a trashed billing item.',
            ], 422));
        }

        $old = $item->toArray();

        $product = $item->product;
        $qty = (int) $data['quantity'];
        $unitPrice = (float) ($data['unit_price'] ?? $item->unit_price);
        $totalAmount = round($qty * $unitPrice, 2);

        $vatRate = (float) $product->vat_rate;
        $lineSubtotal = round($totalAmount / (1 + ($vatRate / 100)), 2);
        $vatAmount = round($totalAmount - $lineSubtotal, 2);

        $item->update([
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ]);

        $this->billingService->recalculateTotals($billing->fresh());

        $this->auditLogService->log(
            'billing_item.update',
            $item,
            $old,
            $item->fresh()->toArray(),
            ['billing_uuid' => $billing->uuid],
            $billing->store_id
        );

        return $item->fresh()->load('product.category');
    }

    public function deleteItem(BillingItem $item): void
    {
        $billing = $item->billing;

        if (!$billing->is_draft && $billing->payments()->exists()) {
            abort(response()->json([
                'message' => 'Cannot delete items after payment has started.',
            ], 422));
        }

        if ($item->trashed()) {
            return;
        }

        $old = $item->toArray();

        $item->delete();

        $this->billingService->recalculateTotals($billing->fresh());

        $this->auditLogService->log(
            'billing_item.delete',
            $item,
            $old,
            null,
            ['billing_uuid' => $billing->uuid],
            $billing->store_id
        );
    }

    public function restoreItem(BillingItem $item): BillingItem
    {
        if (!$item->trashed()) {
            return $item->load('product.category');
        }

        $billing = Billing::query()->findOrFail($item->billing_id);

        if (!$billing->is_draft && $billing->payments()->exists()) {
            abort(response()->json([
                'message' => 'Cannot restore items after payment has started.',
            ], 422));
        }

        $item->restore();

        $this->billingService->recalculateTotals($billing->fresh());

        $this->auditLogService->log(
            'billing_item.restore',
            $item->fresh(),
            null,
            $item->fresh()->toArray(),
            ['billing_uuid' => $billing->uuid],
            $billing->store_id
        );

        return $item->fresh()->load('product.category');
    }
}