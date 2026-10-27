@if ($rows)
    <div class="fox-credit-products">
        <table>
            <caption class="fox-credit-notice">{{ __('credits.eligible_products') }}</caption>
            <thead><tr>
                <th scope="col">{{ __('invoices.product') }}</th>
                <th scope="col">{{ __('credits.invoiced_quantity') }}</th>
                <th scope="col">{{ __('credits.returned_quantity') }}</th>
                <th scope="col">{{ __('credits.remaining') }}</th>
                <th scope="col">{{ __('credits.available_amount') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>@include('components.product-line-identity', ['variant' => $invoiceItems->get($row['invoice_item_id'])?->variant, 'title' => $row['description']])<x-inventory-variant :variant="$invoiceItems->get($row['invoice_item_id'])?->variant" /></td>
                        <td><bdi>{{ \App\Models\NumericFormatter::format($row['invoice_quantity'], 'quantity') }}</bdi></td>
                        <td><bdi>{{ \App\Models\NumericFormatter::format($row['quantity'], 'quantity') }}</bdi></td>
                        <td><bdi>{{ \App\Models\NumericFormatter::format($row['remaining'], 'quantity') }}</bdi></td>
                        <td><bdi>{{ \App\Models\NumericFormatter::format($row['remaining_amount'], 'money') }}</bdi></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
