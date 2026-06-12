<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use Illuminate\Http\Response;

class PublicDocumentController extends Controller
{
    public function show(string $mode, string $uuid): Response
    {
        [$billing, $payload] = $this->buildPayload($mode, $uuid);

        return response()->view('public.billing-document', [
            ...$payload,
            'downloadMode' => false,
            'downloadUrl'  => route('public.documents.download', [
                'mode' => $mode,
                'uuid' => $billing->uuid,
            ]),
        ]);
    }

    public function download(string $mode, string $uuid): Response
    {
        [$billing, $payload] = $this->buildPayload($mode, $uuid);

        $documentNumber = $payload['documentNumber'];
        $fileName       = strtolower($mode) . '-' . preg_replace('/[^A-Za-z0-9\-_]/', '-', $documentNumber) . '.html';

        return response()
            ->view('public.billing-document', [
                ...$payload,
                'downloadMode' => true,
                'downloadUrl'  => null,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function buildPayload(string $mode, string $uuid): array
    {
        abort_unless(in_array($mode, ['receipt', 'invoice'], true), 404);

        $billing = Billing::query()
            ->with([
                'store',
                'customer',
                'user',
                'items.product.category',
                'payments',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Guard: don't expose soft-deleted billings publicly
        if ($billing->trashed()) {
            abort(404);
        }

        $payment = $billing->payments
            ->sortByDesc(fn ($item) => optional($item->payment_date)->timestamp
                ?? optional($item->created_at)->timestamp
                ?? 0)
            ->first();

        if ($mode === 'receipt' && ! $payment) {
            abort(404, 'Receipt not found.');
        }

        $settings      = array_replace($this->defaultSettings(), $billing->store->settings ?? []);
        $vatSummary    = $this->groupVatSummary($billing->items->all());

        $documentNumber = $mode === 'invoice'
            ? ($billing->invnumber ?: optional($payment)->receiptnumber ?: 'INV-' . $billing->billing_id)
            : (optional($payment)->receiptnumber ?: $billing->invnumber ?: 'RCT-' . $billing->billing_id);

        return [
            $billing,
            [
                'mode'          => $mode,
                'billing'       => $billing,
                'payment'       => $payment,
                'settings'      => $settings,
                'vatSummary'    => $vatSummary,
                'documentNumber' => $documentNumber,
                'documentTitle' => $mode === 'invoice' ? 'Tax Invoice' : 'Sales Receipt',
                'currencyCode'  => $billing->store->currency ?: 'KES',
            ],
        ];
    }

    private function groupVatSummary(array $items): array
    {
        $summary = [];

        foreach ($items as $item) {
            $rate   = (float) ($item->vat_rate ?? 0);
            $total  = (float) ($item->total_amount ?? 0);
            $net    = $rate > 0 ? $total / (1 + ($rate / 100)) : $total;
            $vat    = $total - $net;
            $key    = (string) $rate;

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'rate'   => $rate,
                    'net'    => 0,
                    'vat'    => 0,
                    'amount' => 0,
                ];
            }

            $summary[$key]['net']    += $net;
            $summary[$key]['vat']    += $vat;
            $summary[$key]['amount'] += $total;
        }

        return array_values($summary);
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
}