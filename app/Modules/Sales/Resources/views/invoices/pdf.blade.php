<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head><meta charset="UTF-8"><style>
body { font-family: dejavusans; color: #152c44; font-size: 9pt; line-height: 1.5; }
table { width: 100%; border-collapse: collapse; }
.masthead { background: #11263e; color: #ffffff; border-bottom: 4px solid #13b8a6; }
.masthead td { padding: 18px; vertical-align: middle; }
.brand { font-size: 19pt; font-weight: bold; color: #ffffff; }
.eyebrow { font-size: 8pt; color: #13b8a6; letter-spacing: 2px; }
.title { font-size: 25pt; font-weight: bold; }
.contact { color: #dbe6ef; font-size: 8pt; }
.panel { background: #eff5f8; margin-top: 18px; }
.panel td { padding: 12px 15px; vertical-align: top; width: 50%; }
.label { font-size: 8pt; color: #526f82; }
.value { font-size: 10pt; font-weight: bold; }
.status { color: #087f78; font-weight: bold; }
.section-title { margin: 22px 0 9px; font-size: 12pt; font-weight: bold; }
.lines { table-layout: fixed; font-size: 7.8pt; }
.lines thead th { background: #11263e; color: #ffffff; padding: 10px 5px; text-align: center; }
.lines td { padding: 10px 5px; border-bottom: 1px solid #dce7ed; vertical-align: top; }
.stripe { background: #f3f8fa; }
.num { direction: ltr; text-align: right; white-space: nowrap; }
.muted { color: #637b8b; font-size: 7pt; }
.summary { margin-top: 18px; page-break-inside: avoid; }
.summary td { padding: 7px 10px; }
.grand td { background: #11263e; color: #ffffff; border-top: 3px solid #13b8a6; font-size: 13pt; font-weight: bold; padding: 13px 10px; }
.thanks { color: #087f78; font-size: 12pt; margin-top: 24px; font-weight: bold; }
.notes { color: #526f82; margin-top: 8px; white-space: pre-wrap; }
</style></head>
<body dir="{{ $direction }}">
<table class="masthead"><tr><td width="57%">
<img src="var:logo" width="175" alt="Fox &amp; Hema"><br>
<span class="brand">{{ $company['name'] ?? '' }}</span><br>
<span class="contact">{{ $company['address'] ?? '' }}<br>{{ implode(' | ', array_filter([$company['phone'] ?? null, $company['email'] ?? null])) }}</span>
@if(!empty($company['tax_number']))<br><span class="contact">{{ $label('tax_number') }}: {{ $company['tax_number'] }}</span>@endif
@if(!empty($company['registration_number']))<br><span class="contact">{{ $label('registration_number') }}: {{ $company['registration_number'] }}</span>@endif
</td><td><span class="eyebrow">FOX &amp; HEMA</span><br><span class="title">{{ $label('invoice') }}</span><br><span dir="ltr">{{ $invoice->invoice_number }}</span></td></tr></table>
<table class="panel"><tr><td>
<span class="label">{{ $label('customer') }}</span><br><span class="value">{{ $invoice->customer_name ?? '—' }}</span><br>
<span dir="ltr">{{ $invoice->customer_phone }}</span><br><br>
<span class="label">{{ $label('branch') }}</span><br>{{ $branch['name'] ?? '' }}
</td><td>
<span class="label">{{ $label('issue_date') }}</span> <span dir="ltr">{{ $invoice->invoice_date->format('Y-m-d') }}</span><br>
<span class="label">{{ $label('status') }}</span> <span class="status">{{ $label('posted') }}</span><br>
<span class="label">{{ $label('order') }}</span> <span dir="ltr">{{ $orderNumber }}</span><br>
<span class="label">{{ $label('currency') }}</span> <span dir="ltr">{{ $invoice->currency }}</span>
@if($invoice->due_date)<br><span class="label">{{ $label('due_date') }}</span> <span dir="ltr">{{ $invoice->due_date->format('Y-m-d') }}</span>@endif
</td></tr></table>
<div class="section-title">{{ $label('items') }}</div>
<table class="lines" dir="{{ $direction }}"><thead><tr>
<th width="4%">#</th><th width="24%">{{ $label('product') }}</th><th width="11%">{{ $label('sku') }}</th>
<th width="6%">{{ $label('size') }}</th><th width="7%">{{ $label('color') }}</th><th width="9%">{{ $label('quantity') }}</th>
<th width="11%">{{ $label('unit_price') }}</th><th width="9%">{{ $label('discount') }}</th><th width="8%">{{ $label('tax') }}</th><th width="11%">{{ $label('total') }}</th>
</tr></thead><tbody>
@foreach($items as $item)
<tr class="{{ $loop->even ? 'stripe' : '' }}">
<td>{{ $loop->iteration }}</td><td><b>{{ $item->commercial_snapshot['product_name'] ?? $item->description }}</b>
@if($item->description)<br><span class="muted">{{ $item->description }}</span>@endif</td>
<td dir="ltr">{{ $item->commercial_snapshot['sku'] ?? '' }}</td><td>{{ $item->commercial_snapshot['size'] ?? '—' }}</td><td>{{ $item->commercial_snapshot['color'] ?? '—' }}</td>
<td class="num">{{ $item->quantity }}</td><td class="num">{{ $money($item->unit_price) }}</td>
<td class="num">{{ $money($item->discount_amount) }}</td><td class="num">{{ $money($item->tax_amount) }}</td><td class="num"><b>{{ $money($item->line_total) }}</b></td>
</tr>
@endforeach
</tbody></table>
<table class="summary"><tr><td width="48%"></td><td>
<table><tr><td>{{ $label('subtotal') }}</td><td class="num">{{ $money($invoice->subtotal) }} {{ $invoice->currency }}</td></tr>
<tr><td>{{ $label('discount') }}</td><td class="num">{{ $money($invoice->discount_amount) }} {{ $invoice->currency }}</td></tr>
<tr><td>{{ $label('tax') }}</td><td class="num">{{ $money($invoice->tax_amount) }} {{ $invoice->currency }}</td></tr>
<tr class="grand"><td>{{ $label('grand_total') }}</td><td class="num">{{ $money($invoice->total_amount) }} {{ $invoice->currency }}</td></tr></table>
</td></tr></table>
<div class="thanks">{{ $label('thanks') }}</div>
@if($invoice->notes)<div class="notes">{{ $invoice->notes }}</div>@endif
</body></html>
