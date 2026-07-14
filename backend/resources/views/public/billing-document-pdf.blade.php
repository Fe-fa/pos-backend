@php
    /*
     * Mirrors the receipt/invoice number, label, and title logic in
     * print.js so this document always agrees with the thermal print,
     * regardless of what the controller happens to pass in.
     */
    $isPaid = (float) ($billing->balance_due ?? 0) <= 0;

    $documentNumber = $mode === 'invoice'
        ? ($billing->invnumber
            ?: ($payment->receiptnumber ?? null)
            ?: ($billing->billing_id ? 'INV-' . $billing->billing_id : 'DRAFT'))
        : ($isPaid
            ? (($payment->receiptnumber ?? null)
                ?: $billing->invnumber
                ?: ($billing->billing_id ? 'RCT-' . $billing->billing_id : 'DRAFT'))
            : ($billing->invnumber
                ?: ($payment->receiptnumber ?? null)
                ?: ($billing->billing_id ? 'INV-' . $billing->billing_id : 'DRAFT')));

    $documentLabel = $mode === 'receipt' && $isPaid ? 'Receipt No' : 'Invoice No';

    $documentTitle = $mode === 'invoice'
        ? 'Tax Invoice'
        : ($isPaid ? 'Sales Receipt' : 'Payment Receipt');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} - {{ $documentNumber }}</title>

    <style>
        /*
         * NOTE ON PDF RENDERERS: CSS custom properties (var(--x)) are not
         * reliably supported by every HTML-to-PDF engine (DomPDF's support
         * depends on version; wkhtmltopdf/Chromium-based renderers are
         * generally fine). If your rendered PDF shows raw "var(--x)" text
         * or falls back to default colors, swap the var() calls below for
         * the literal hex values listed in the :root block, or pre-resolve
         * them server-side before render.
         */
        :root {
            --primary: #0E84C3;
            --primary-strong: #0A6CA0;

            --panel: #ffffff;
            --panel-2: #f8fafc;

            --text: #1a1d20;
            --text-strong: #111827;
            --muted: #6b7280;

            --line: #dbe3ea;

            --success: #218353;
            --danger: #cf3a3a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            color: var(--text);
            font-size: 12px;
        }

        .sheet {
            width: 100%;
        }

        .doc-title {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--primary) 10%, white);
            color: var(--primary-strong);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-pill {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 6px;
        }

        .status-pill.is-paid {
            background: color-mix(in srgb, var(--success) 14%, white);
            color: var(--success);
        }

        .status-pill.is-unpaid {
            background: color-mix(in srgb, var(--danger) 12%, white);
            color: var(--danger);
        }

        h1 {
            margin: 8px 0 4px;
            font-size: 22px;
        }

        .muted {
            color: var(--muted);
            font-size: 11px;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .meta-table td {
            border: 1px solid var(--line);
            background: var(--panel-2);
            padding: 10px 12px;
            width: 25%;
            vertical-align: top;
        }

        .meta-table span {
            display: block;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .meta-table strong {
            font-size: 12px;
        }

        .divider {
            border-top: 1px solid var(--line);
            margin: 20px 0;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.items th {
            background: var(--panel-2);
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid var(--line);
        }

        table.items td {
            padding: 10px;
            border-bottom: 1px solid var(--line);
        }

        .align-right {
            text-align: right;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .summary-table td {
            border: 1px solid var(--line);
            background: var(--panel-2);
            padding: 10px 12px;
            width: 25%;
        }

        .summary-table span {
            display: block;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .summary-table strong {
            font-size: 12px;
        }

        .summary-table .total {
            background: color-mix(in srgb, var(--primary) 8%, white);
        }

        .summary-table .total strong {
            color: var(--primary-strong);
        }

        .extra-info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .extra-info-table td {
            border: 1px solid var(--line);
            background: var(--panel-2);
            padding: 8px 12px;
            font-size: 11px;
        }

        .extra-info-table td:first-child {
            color: var(--muted);
            width: 60%;
        }

        .extra-info-table td:last-child {
            text-align: right;
            font-weight: 700;
            color: var(--text-strong);
        }

        .extra-info-table tr.danger td {
            background: color-mix(in srgb, var(--danger) 6%, white);
        }

        .extra-info-table tr.danger td:last-child {
            color: var(--danger);
        }

        .footer-note {
            margin-top: 16px;
            padding: 12px 14px;
            background: var(--panel-2);
            border: 1px solid var(--line);
        }
    </style>
</head>
<body>
    @php
        $paidAmount = (float) ($billing->paid_amount ?? 0);
        $balanceDue = (float) ($billing->balance_due ?? 0);
        $pointsDiscount = (float) ($billing->points_discount ?? 0);
        $changeReturned = $payment->change_returned ?? null;
        $paymentMethod = $payment->payment_method ?? null;
    @endphp

    <div class="sheet">
        <div class="doc-title">{{ $documentTitle }}</div>
        @if($mode === 'receipt')
            <span class="status-pill {{ $isPaid ? 'is-paid' : 'is-unpaid' }}">
                {{ $isPaid ? 'Paid' : 'Balance Due' }}
            </span>
        @endif

        <h1>{{ $billing->store->store_name ?? 'Store' }}</h1>

        @if(($settings['show_store_contacts_on_print'] ?? true) && !empty($billing->store->location))
            <div class="muted">Location: {{ $billing->store->location }}</div>
        @endif

        @if(($settings['show_store_contacts_on_print'] ?? true) && !empty($billing->store->telephone))
            <div class="muted">Tel: {{ $billing->store->telephone }}</div>
        @endif

        @if(($settings['show_store_contacts_on_print'] ?? true) && !empty($billing->store->email_address))
            <div class="muted">Email: {{ $billing->store->email_address }}</div>
        @endif

        @if(($settings['show_store_pin_on_print'] ?? true) && !empty($billing->store->pin))
            <div class="muted">KRA PIN: {{ $billing->store->pin }}</div>
        @endif

        @php
            $headerText = $mode === 'invoice'
                ? ($settings['invoice_header'] ?? '')
                : ($settings['receipt_header'] ?? '');
        @endphp

        @if(!empty($headerText))
            <div class="muted">{{ $headerText }}</div>
        @endif

        <table class="meta-table">
            <tr>
                <td>
                    <span>{{ $documentLabel }}</span>
                    <strong>{{ $documentNumber }}</strong>
                </td>
                <td>
                    <span>Date</span>
                    <strong>{{ optional($payment?->payment_date ?? $billing->billing_date)->format('Y-m-d H:i') }}</strong>
                </td>
                @if(($settings['show_customer_on_print'] ?? true))
                    <td>
                        <span>Customer</span>
                        <strong>{{ $billing->customer->full_name ?? 'Walk-in Customer' }}</strong>
                    </td>
                @endif
                @if(($settings['show_cashier_on_print'] ?? true))
                    <td>
                        <span>Served By</span>
                        <strong>{{ $billing->user->full_name ?? 'Cashier' }}</strong>
                    </td>
                @endif
            </tr>
        </table>

        <div class="divider"></div>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="align-right">Qty</th>
                    <th class="align-right">Unit</th>
                    <th class="align-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($billing->items as $item)
                    <tr>
                        <td>{{ $item->product->product_name ?? 'Product' }}</td>
                        <td class="align-right">{{ (int) $item->quantity }}</td>
                        <td class="align-right">{{ $currencyCode }} {{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="align-right">{{ $currencyCode }} {{ number_format((float) $item->total_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="summary-table">
            <tr>
                <td>
                    <span>Net Amount</span>
                    <strong>{{ $currencyCode }} {{ number_format((float) $billing->subtotal, 2) }}</strong>
                </td>
                <td>
                    <span>VAT Amount</span>
                    <strong>{{ $currencyCode }} {{ number_format((float) $billing->vat_amount, 2) }}</strong>
                </td>
                <td>
                    <span>Paid</span>
                    <strong>{{ $currencyCode }} {{ number_format($paidAmount, 2) }}</strong>
                </td>
                <td class="total">
                    <span>Total</span>
                    <strong>{{ $currencyCode }} {{ number_format((float) $billing->total, 2) }}</strong>
                </td>
            </tr>
        </table>

        @if($pointsDiscount > 0 || ($paymentMethod && ($settings['show_payment_method_on_print'] ?? true)) || $balanceDue > 0 || $changeReturned)
            <table class="extra-info-table">
                @if($pointsDiscount > 0)
                    <tr>
                        <td>Points Discount</td>
                        <td>- {{ $currencyCode }} {{ number_format($pointsDiscount, 2) }}</td>
                    </tr>
                @endif

                @if($paymentMethod && ($settings['show_payment_method_on_print'] ?? true))
                    <tr>
                        <td>Payment Method</td>
                        <td>{{ strtoupper($paymentMethod) }}</td>
                    </tr>
                @endif

                @if($balanceDue > 0)
                    <tr class="danger">
                        <td>Balance Due</td>
                        <td>{{ $currencyCode }} {{ number_format($balanceDue, 2) }}</td>
                    </tr>
                @endif

                @if($changeReturned)
                    <tr>
                        <td>Change</td>
                        <td>{{ $currencyCode }} {{ number_format((float) $changeReturned, 2) }}</td>
                    </tr>
                @endif
            </table>
        @endif

        @if(($settings['show_vat_summary'] ?? true) && count($vatSummary))
            <table class="items" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th>VAT %</th>
                        <th class="align-right">Net</th>
                        <th class="align-right">VAT</th>
                        <th class="align-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vatSummary as $row)
                        <tr>
                            <td>{{ number_format((float) $row['rate'], 2) }}%</td>
                            <td class="align-right">{{ $currencyCode }} {{ number_format((float) $row['net'], 2) }}</td>
                            <td class="align-right">{{ $currencyCode }} {{ number_format((float) $row['vat'], 2) }}</td>
                            <td class="align-right">{{ $currencyCode }} {{ number_format((float) $row['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @php
            $footerText = $mode === 'invoice'
                ? ($settings['invoice_footer'] ?? '')
                : ($settings['receipt_footer'] ?? '');
        @endphp

        @if(!empty($footerText))
            <div class="footer-note">{{ $footerText }}</div>
        @endif

        @if(!empty($billing->notes))
            <div class="footer-note">{{ $billing->notes }}</div>
        @endif
    </div>
</body>
</html>