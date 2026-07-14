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
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        /*
         * Local token block so this standalone document renders correctly
         * even if it's opened outside the main app bundle. Values are kept
         * in sync with the app-wide :root in app.css — if you already load
         * app.css on this page via <link>, this block is redundant but
         * harmless (later app.css :root wins by source order/specificity
         * only if loaded after this block).
         */
        :root {
            --primary: #0E84C3;
            --primary-strong: #0A6CA0;
            --accent: #FA7316;
            --accent-strong: #F96E14;

            --bg: #eef2f5;
            --bg-soft: #f7f8f9;
            --panel: #ffffff;
            --panel-2: #f8fafc;

            --text: #1a1d20;
            --text-strong: #111827;
            --muted: #6b7280;

            --line: #dbe3ea;
            --line-strong: #cbd5e1;

            --success: #218353;
            --danger: #cf3a3a;
            --warning: #d58c1f;

            --shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 6px 18px rgba(15, 23, 42, 0.05);

            --radius: 18px;
            --radius-sm: 12px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 880px;
            margin: 24px auto;
            padding: 0 16px 32px;
        }

        .toolbar {
            display: {{ $downloadMode ? 'none' : 'flex' }};
            justify-content: flex-end;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .btn.primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .btn.primary:hover {
            background: var(--primary-strong);
            border-color: var(--primary-strong);
        }

        .sheet {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .sheet-header {
            padding: 28px 28px 20px;
            border-bottom: 1px solid var(--line);
            display: grid;
            gap: 18px;
        }

        .topline {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .brand {
            display: grid;
            gap: 8px;
        }

        .brand h1 {
            margin: 0;
            font-size: 1.8rem;
        }

        .muted {
            color: var(--muted);
        }

        .doc-title {
            display: inline-flex;
            width: fit-content;
            padding: 8px 14px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--primary) 10%, white);
            color: var(--primary-strong);
            font-size: 0.84rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .status-pill {
            display: inline-flex;
            width: fit-content;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .status-pill.is-paid {
            background: color-mix(in srgb, var(--success) 14%, white);
            color: var(--success);
        }

        .status-pill.is-unpaid {
            background: color-mix(in srgb, var(--danger) 12%, white);
            color: var(--danger);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .meta-box {
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            padding: 14px;
            background: var(--panel-2);
            display: grid;
            gap: 6px;
        }

        .meta-box span {
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }

        .meta-box strong {
            font-size: 0.98rem;
        }

        .section {
            padding: 24px 28px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: var(--panel-2);
            color: var(--muted);
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-align: left;
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .align-right {
            text-align: right;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .summary-box {
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            padding: 14px;
            background: var(--panel-2);
            display: grid;
            gap: 6px;
        }

        .summary-box span {
            color: var(--muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }

        .summary-box strong {
            font-size: 1rem;
        }

        .summary-box.total {
            background: color-mix(in srgb, var(--primary) 8%, white);
            border-color: color-mix(in srgb, var(--primary) 22%, white);
        }

        .summary-box.total strong {
            color: var(--primary-strong);
        }

        .summary-box.danger {
            background: color-mix(in srgb, var(--danger) 8%, white);
            border-color: color-mix(in srgb, var(--danger) 22%, white);
        }

        .summary-box.danger strong {
            color: var(--danger);
        }

        .extra-info-list {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .extra-info-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: var(--panel-2);
            font-size: 0.9rem;
        }

        .extra-info-row span:first-child {
            color: var(--muted);
        }

        .extra-info-row strong {
            color: var(--text-strong);
        }

        .footer-note {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            background: var(--panel-2);
            border: 1px solid var(--line);
            white-space: pre-line;
        }

        @media (max-width: 720px) {
            .meta-grid,
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .section,
            .sheet-header {
                padding: 18px;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                margin: 0;
                max-width: none;
                padding: 0;
            }

            .toolbar {
                display: none !important;
            }

            .sheet {
                box-shadow: none;
                border: 0;
                border-radius: 0;
            }
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

    <div class="page">
        <div class="toolbar">
            <button class="btn primary" onclick="window.print()">Print</button>
            @if($downloadUrl)
                <a class="btn" href="{{ $downloadUrl }}">Download</a>
            @endif
        </div>

        <article class="sheet">
            <div class="sheet-header">
                <div class="topline">
                    <div class="brand">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap;">
                            <div class="doc-title">{{ $documentTitle }}</div>
                            @if($mode === 'receipt')
                                <span class="status-pill {{ $isPaid ? 'is-paid' : 'is-unpaid' }}">
                                    {{ $isPaid ? 'Paid' : 'Balance Due' }}
                                </span>
                            @endif
                        </div>
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
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-box">
                        <span>{{ $documentLabel }}</span>
                        <strong>{{ $documentNumber }}</strong>
                    </div>

                    <div class="meta-box">
                        <span>Date</span>
                        <strong>{{ optional($payment?->payment_date ?? $billing->billing_date)->format('Y-m-d H:i') }}</strong>
                    </div>

                    @if(($settings['show_customer_on_print'] ?? true))
                        <div class="meta-box">
                            <span>Customer</span>
                            <strong>{{ $billing->customer->full_name ?? 'Walk-in Customer' }}</strong>
                        </div>
                    @endif

                    @if(($settings['show_cashier_on_print'] ?? true))
                        <div class="meta-box">
                            <span>Served By</span>
                            <strong>{{ $billing->user->full_name ?? 'Cashier' }}</strong>
                        </div>
                    @endif
                </div>
            </div>

            <div class="section">
                <table>
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

                <div class="summary-grid">
                    <div class="summary-box">
                        <span>Net Amount</span>
                        <strong>{{ $currencyCode }} {{ number_format((float) $billing->subtotal, 2) }}</strong>
                    </div>

                    <div class="summary-box">
                        <span>VAT Amount</span>
                        <strong>{{ $currencyCode }} {{ number_format((float) $billing->vat_amount, 2) }}</strong>
                    </div>

                    <div class="summary-box">
                        <span>Paid</span>
                        <strong>{{ $currencyCode }} {{ number_format($paidAmount, 2) }}</strong>
                    </div>

                    <div class="summary-box total">
                        <span>Total</span>
                        <strong>{{ $currencyCode }} {{ number_format((float) $billing->total, 2) }}</strong>
                    </div>
                </div>

                <div class="extra-info-list">
                    @if($pointsDiscount > 0)
                        <div class="extra-info-row">
                            <span>Points Discount</span>
                            <strong>- {{ $currencyCode }} {{ number_format($pointsDiscount, 2) }}</strong>
                        </div>
                    @endif

                    @if($paymentMethod && ($settings['show_payment_method_on_print'] ?? true))
                        <div class="extra-info-row">
                            <span>Payment Method</span>
                            <strong>{{ strtoupper($paymentMethod) }}</strong>
                        </div>
                    @endif

                    @if($balanceDue > 0)
                        <div class="extra-info-row" style="border-color: color-mix(in srgb, var(--danger) 30%, var(--line)); background: color-mix(in srgb, var(--danger) 6%, white);">
                            <span>Balance Due</span>
                            <strong style="color: var(--danger);">{{ $currencyCode }} {{ number_format($balanceDue, 2) }}</strong>
                        </div>
                    @endif

                    @if($changeReturned)
                        <div class="extra-info-row">
                            <span>Change</span>
                            <strong>{{ $currencyCode }} {{ number_format((float) $changeReturned, 2) }}</strong>
                        </div>
                    @endif
                </div>

                @if(($settings['show_vat_summary'] ?? true) && count($vatSummary))
                    <div style="margin-top: 24px;">
                        <table>
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
                    </div>
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
        </article>
    </div>
</body>
</html>