<table style="width:100%;border-top:1px solid #13b8a6;font-family:dejavusans;font-size:8pt;color:#637b8b" dir="{{ $direction }}"><tr>
<td>{{ $company['name'] ?? '' }}<br>{{ implode(' | ', array_filter([$company['phone'] ?? null, $company['email'] ?? null])) }}</td>
<td style="text-align:right" dir="ltr">{{ $label('page') }} {PAGENO} / {nbpg}</td>
</tr></table>
