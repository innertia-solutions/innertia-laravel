<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Factura {{ $number }}</title>
<style>
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 11px; color: #1f2937; margin: 0; }
    .brand { color: {{ $brand->color }}; font-size: 20px; font-weight: bold; }
    .muted { color: #6b7280; }
    .head { width: 100%; margin-bottom: 18px; }
    .head td { vertical-align: top; }
    .box { border: 1px solid #e5e7eb; padding: 10px; border-radius: 4px; }
    h1 { font-size: 16px; margin: 0 0 4px 0; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.lines th { text-align: left; background: #f3f4f6; padding: 6px; font-size: 10px; text-transform: uppercase; }
    table.lines td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
    .num { text-align: right; white-space: nowrap; }
    .space { background: #fafafa; font-weight: bold; }
    .totals { width: 40%; margin-left: 60%; margin-top: 12px; border-collapse: collapse; }
    .totals td { padding: 4px 6px; }
    .total { font-size: 14px; font-weight: bold; border-top: 2px solid {{ $brand->color }}; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 8px; background: #f3f4f6; font-size: 10px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="brand">{{ $issuer['name'] ?? $brand->name }}</div>
            @if (! empty($issuer['tax_id']))<div class="muted">RUT {{ $issuer['tax_id'] }}</div>@endif
            @if (! empty($issuer['address']))<div class="muted">{{ $issuer['address'] }}</div>@endif
        </td>
        <td style="text-align: right;">
            <h1>Factura {{ $number }}</h1>
            @if ($invoice->folio)<div>Folio SII {{ $invoice->folio }}</div>@endif
            <div class="muted">Emitida {{ $issuedAt ?? '—' }} · Vence {{ $dueAt ?? '—' }}</div>
            <div class="badge">{{ $invoice->status->label() }}</div>
        </td>
    </tr>
</table>

<div class="box">
    <strong>{{ $billing['legal_name'] ?? $invoice->client?->name }}</strong><br>
    @if (! empty($billing['tax_id']))RUT {{ $billing['tax_id'] }}<br>@endif
    @if (! empty($billing['business_activity']))Giro {{ $billing['business_activity'] }}<br>@endif
    @if (! empty($billing['address'])){{ $billing['address'] }}<br>@endif
    @if (! empty($billing['billing_email']))<span class="muted">{{ $billing['billing_email'] }}</span>@endif
</div>

<p class="muted">Período {{ $invoice->period_start->format('d-m-Y') }} al {{ $invoice->period_end->format('d-m-Y') }}</p>

<table class="lines">
    <thead>
        <tr><th>Detalle</th><th class="num">Cantidad</th><th class="num">Precio unitario</th><th class="num">Monto</th></tr>
    </thead>
    <tbody>
    @foreach ($invoice->statements as $statement)
        @if ($invoice->isConsolidated())
            <tr class="space"><td colspan="4">{{ $statement->tenant?->name ?? $statement->tenant_id }} · {{ $statement->period_start->format('d-m-Y') }} al {{ $statement->period_end->format('d-m-Y') }}</td></tr>
        @endif
        @foreach ($statement->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 2, ',', '.'), '0'), ',') }}</td>
                <td class="num">{{ $line->unit_price ? $money($line->unit_price) : '' }}</td>
                <td class="num">{{ $money($line->amount) }}</td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
    <tr><td>IVA</td><td class="num">{{ $money($invoice->tax) }}</td></tr>
    <tr class="total"><td>Total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
    @if ($invoice->balance !== $invoice->total)
        <tr><td>Saldo pendiente</td><td class="num">{{ $money($invoice->balance) }}</td></tr>
    @endif
</table>

@if ($invoice->payment_link && $invoice->balance > 0)
    <p>Paga en línea: {{ $invoice->payment_link }}</p>
@elseif ($instructions)
    <p class="muted">{{ $instructions }}</p>
@endif
</body>
</html>
