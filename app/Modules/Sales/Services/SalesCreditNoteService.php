<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesCreditNoteService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesCreditCalculator $calculator,
        private readonly PurchaseOrderCalculator $decimals) {}

    public function create(User $actor, array $data, string $submissionId): SalesCreditNote
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => 'required|uuid'])->validate();
        $input = $this->input($data);

        return DB::transaction(function () use ($actor, $input, $submissionId): SalesCreditNote {
            $return = $this->lockSources($actor, $input['sales_return_id'], $input['sales_invoice_id'], SalesPermissions::CREDITS_CREATE);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = SalesCreditNote::where('uuid', strtolower($submissionId))->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->created_by === (int) $actor->id && (int) $existing->sales_return_id === (int) $return->id, 403);
                if ($existing->submission_payload_hash !== $hash) {
                    $this->invalid('changed');
                }
                if ($existing->status === 'posted') {
                    $this->verifyPosted($existing);
                }

                return $existing;
            }
            $lines = $this->prepare($return, $input['sales_invoice_id'], $input['items']);
            $id = DB::table('sales_credit_notes')->insertGetId(Arr::except($input, 'items') + $this->decimals->totals($lines) + [
                'uuid' => strtolower($submissionId), 'credit_note_number' => 'DRAFT-'.strtolower($submissionId),
                'location_id' => $return->location_id, 'status' => 'draft', 'created_by' => $actor->id,
                'submission_payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->saveLines($id, $lines);
            $note = SalesCreditNote::findOrFail($id);
            $this->audit($actor, $note, 'created');

            return $note;
        }, DB::transactionLevel() === 0 ? 3 : 1);
    }

    public function update(User $actor, SalesCreditNote $note, array $data, string $revision): SalesCreditNote
    {
        $input = $this->input($data);

        return DB::transaction(function () use ($actor, $note, $input, $revision): SalesCreditNote {
            [$return, $note] = $this->lock($actor, $note, SalesPermissions::CREDITS_UPDATE);
            $this->draft($note);
            $this->checkRevision($note, $revision);
            if ($input['sales_return_id'] !== (int) $note->sales_return_id || $input['sales_invoice_id'] !== (int) $note->sales_invoice_id) {
                $this->invalid('inconsistent');
            }
            $lines = $this->prepare($return, $input['sales_invoice_id'], $input['items']);
            DB::table('sales_credit_note_items')->where('sales_credit_note_id', $note->id)->delete();
            DB::table('sales_credit_notes')->where('id', $note->id)->update(Arr::only($input, ['credit_date', 'reason']) + $this->decimals->totals($lines) + ['updated_at' => now()]);
            $this->saveLines($note->id, $lines);
            $this->audit($actor, $note->refresh(), 'updated');

            return $note;
        }, DB::transactionLevel() === 0 ? 3 : 1);
    }

    public function post(User $actor, SalesCreditNote $note, string $revision): SalesCreditNote
    {
        return DB::transaction(function () use ($actor, $note, $revision): SalesCreditNote {
            [$return, $note] = $this->lock($actor, $note, SalesPermissions::CREDITS_POST);
            if ($note->status === 'posted') {
                $this->verifyPosted($note);
                if (! in_array($revision, [$note->posting_revision, $this->revision($note)], true)) {
                    $this->invalid('changed');
                }

                return $note;
            }
            $this->draft($note);
            $this->checkRevision($note, $revision);
            $items = $note->items()->orderBy('sales_return_posted_allocation_id')->get();
            $lines = $this->prepare($return, (int) $note->sales_invoice_id, $items->map(fn ($item): array => [
                'sales_return_posted_allocation_id' => $item->sales_return_posted_allocation_id, 'quantity' => $item->quantity])->all());
            foreach ($items as $index => $item) {
                foreach ($lines[$index] as $field => $value) {
                    if ($field === 'commercial_snapshot'
                        ? SalesPricingSnapshot::normalize($item->{$field}) !== SalesPricingSnapshot::normalize($value)
                        : (string) $item->{$field} !== (string) $value) {
                        $this->invalid('review');
                    }
                }
            }
            foreach ($this->decimals->totals($lines) as $field => $value) {
                if ($note->{$field} !== $value) {
                    $this->invalid('inconsistent');
                }
            }
            $invoice = SalesInvoice::findOrFail($note->sales_invoice_id);
            $snapshot = $invoice->document_snapshot + ['invoice_number' => $invoice->invoice_number, 'return_number' => $return->return_number,
                'customer_name' => $invoice->customer_name, 'currency' => $invoice->currency];
            $number = app(DocumentNumberService::class)->allocate((int) $note->location_id, 'CN', $note->uuid);
            DB::table('sales_credit_notes')->where('id', $note->id)->update(['status' => 'posted', 'credit_note_number' => $number,
                'posted_at' => now(), 'posted_by' => $actor->id, 'posting_revision' => $revision,
                'document_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $note->refresh();
            DB::table('sales_credit_notes')->where('id', $note->id)->update(['posting_payload_hash' => $this->revision($note)]);
            $this->audit($actor, $note->refresh(), 'posted');

            return $note;
        }, DB::transactionLevel() === 0 ? 3 : 1);
    }

    public function revision(SalesCreditNote $note): string
    {
        $header = Arr::except($note->getAttributes(), 'posting_payload_hash');
        ksort($header);
        $items = $note->items()->orderBy('id')->get()->map->getAttributes()->all();

        return hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
    }

    public function verifyPosted(SalesCreditNote $note): void
    {
        if ($note->status !== 'posted' || $note->posted_at === null || $note->posted_by === null || $note->posting_revision === null
            || $note->document_snapshot === null || $note->posting_payload_hash === null || ! hash_equals($note->posting_payload_hash, $this->revision($note))) {
            $this->invalid('inconsistent');
        }
    }

    public function scope(Builder $query, ?User $actor): Builder
    {
        if (! $this->access->allows($actor, SalesPermissions::CREDITS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn ($q) => $q->where('type', 'branch'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where($query->getModel()->qualifyColumn('location_id'), getPermissionsTeamId());
    }

    public function returns(?User $actor): Builder
    {
        return $this->scope(SalesReturn::query(), $actor)->where('status', 'posted')->whereNotNull('posting_evidence_hash')
            ->whereHas('location', fn (Builder $query) => $query->where('is_active', true));
    }

    public function preview(User $actor, array $data): array
    {
        $input = $this->input($data);

        return DB::transaction(function () use ($actor, $input): array {
            $return = $this->lockSources($actor, $input['sales_return_id'], $input['sales_invoice_id'], SalesPermissions::CREDITS_VIEW);

            return $this->decimals->totals($this->prepare($return, $input['sales_invoice_id'], $input['items']));
        });
    }

    public function details(?User $actor, mixed $returnId, mixed $invoiceId = null): array
    {
        if (! is_scalar($returnId) || ! ctype_digit((string) $returnId) || ! $this->returns($actor)->whereKey($returnId)->exists()) {
            return [];
        }

        return DB::table('sales_return_posted_allocations as a')->join('sales_invoice_items as i', 'i.id', '=', 'a.sales_invoice_item_id')
            ->join('sales_invoices as v', 'v.id', '=', 'a.sales_invoice_id')->join('sales_returns as r', 'r.id', '=', 'a.sales_return_id')
            ->whereColumn('v.location_id', 'r.location_id')->whereColumn('v.sales_order_id', 'r.sales_order_id')->where('a.sales_return_id', $returnId)->where('v.status', 'posted')
            ->when($invoiceId !== null, fn ($q) => $q->where('v.id', $invoiceId))
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'i.variant_id')->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->select('pv.product_id', 'p.brand_id', 'a.id', 'a.quantity', 'a.sales_invoice_id', 'v.invoice_number', 'i.description', 'i.id as invoice_item_id', 'i.quantity as invoice_quantity', 'i.unit_price', 'i.subtotal', 'i.discount_amount', 'i.tax_amount', 'i.line_total')
            ->orderBy('a.id')->get()->mapWithKeys(function ($row): array {
                $used = DB::table('sales_credit_note_items as i')->join('sales_credit_notes as n', 'n.id', '=', 'i.sales_credit_note_id')
                    ->where('n.status', 'posted')->where('i.sales_return_posted_allocation_id', $row->id)->sum('i.quantity');
                $remaining = BigDecimal::of($row->quantity)->minus($used)->toScale(4);
                if ($remaining->isNegative()) {
                    $this->invalid('inconsistent');
                }
                $invoicedUsed = DB::table('sales_credit_note_items as i')->join('sales_credit_notes as n', 'n.id', '=', 'i.sales_credit_note_id')
                    ->where('n.status', 'posted')->where('i.sales_invoice_item_id', $row->invoice_item_id)->sum('i.quantity');
                $amounts = $remaining->isZero() ? ['line_total' => '0.0000'] : $this->calculator->allocate(array_replace((array) $row, ['quantity' => $row->invoice_quantity]), (string) $invoicedUsed, (string) $remaining);

                return [$row->id => (array) $row + ['remaining' => (string) $remaining, 'remaining_amount' => $amounts['line_total']]];
            })->all();
    }

    private function lockSources(User $actor, int $returnId, int $invoiceId, string $permission): SalesReturn
    {
        ModuleRegistryLock::acquire();
        $order = SalesOrder::whereKey(SalesReturn::whereKey($returnId)->value('sales_order_id'))->lockForUpdate()->firstOrFail();
        app(SalesDocumentLocks::class)->acquire($order);
        $return = $order->returns()->whereKey($returnId)->firstOrFail();
        $this->access->authorize($actor, SalesPermissions::CREDITS_VIEW, (int) $order->location_id);
        $this->access->authorize($actor, $permission, (int) $order->location_id);
        $invoice = $order->invoices()->whereKey($invoiceId)->firstOrFail();
        if ((int) $return->location_id !== (int) $order->location_id || (int) $invoice->location_id !== (int) $order->location_id) {
            $this->invalid('inconsistent');
        }
        app(SalesReturnPostingService::class)->verifyFingerprint($return);
        app(SalesReturnApprovalService::class)->verifyApproved($return, true);
        app(SalesInvoiceService::class)->verifyPosted($invoice);
        $notes = SalesCreditNote::whereIn('sales_invoice_id', $order->invoices()->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        DB::table('sales_credit_note_items')->whereIn('sales_credit_note_id', $notes->modelKeys())->orderBy('id')->lockForUpdate()->get();
        foreach ($notes as $note) {
            if ($note->status === 'posted') {
                $this->verifyPosted($note);
            }
        }

        return $return;
    }

    private function lock(User $actor, SalesCreditNote $note, string $permission): array
    {
        ModuleRegistryLock::acquire();
        $stored = SalesCreditNote::findOrFail($note->id);
        $return = $this->lockSources($actor, (int) $stored->sales_return_id, (int) $stored->sales_invoice_id, $permission);
        $stored = SalesCreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ((int) $stored->location_id !== (int) $return->location_id) {
            $this->invalid('inconsistent');
        }

        return [$return, $stored];
    }

    private function prepare(SalesReturn $return, int $invoiceId, array $input): array
    {
        if ($input === [] || count($input) > 200 || count(array_unique(array_column($input, 'sales_return_posted_allocation_id'))) !== count($input)) {
            $this->invalid('ineligible');
        }
        usort($input, fn ($a, $b) => $a['sales_return_posted_allocation_id'] <=> $b['sales_return_posted_allocation_id']);
        $running = [];
        $lines = [];
        foreach ($input as $request) {
            $allocation = $return->postedAllocations()->whereKey($request['sales_return_posted_allocation_id'])->where('sales_invoice_id', $invoiceId)->first();
            if ($allocation === null || $allocation->sales_invoice_item_id === null) {
                $this->invalid('ineligible');
            }
            $source = SalesInvoiceItem::findOrFail($allocation->sales_invoice_item_id);
            $quantity = $this->decimals->decimal($request['quantity'], 'quantity', 18, 4);
            $history = DB::table('sales_credit_note_items as i')->join('sales_credit_notes as n', 'n.id', '=', 'i.sales_credit_note_id')
                ->where('n.status', 'posted')->where('i.sales_invoice_item_id', $source->id)->select('i.*')->orderBy('i.id')->get();
            $usedAllocation = $history->where('sales_return_posted_allocation_id', $allocation->id)->reduce(fn ($sum, $row) => $sum->plus($row->quantity), BigDecimal::zero());
            if (! BigDecimal::of($quantity)->isPositive() || $usedAllocation->plus($quantity)->isGreaterThan($allocation->quantity)) {
                $this->invalid('ineligible');
            }
            $previous = $history->reduce(fn ($sum, $row) => $sum->plus($row->quantity), BigDecimal::zero());
            $expected = $previous->isZero() ? ['subtotal' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'line_total' => '0']
                : $this->calculator->allocate($source->getAttributes(), '0', (string) $previous);
            foreach ($expected as $field => $amount) {
                $sum = $history->reduce(fn ($sum, $row) => $sum->plus($row->{$field}), BigDecimal::zero());
                if (! $sum->isEqualTo($amount)) {
                    $this->invalid('inconsistent');
                }
            }
            $running[$source->id] ??= (string) $previous;
            $amounts = $this->calculator->allocate($source->getAttributes(), $running[$source->id], $quantity);
            $running[$source->id] = (string) BigDecimal::of($running[$source->id])->plus($quantity);
            $lines[] = $amounts + ['sales_invoice_item_id' => $source->id, 'sales_return_item_id' => $allocation->sales_return_item_id,
                'sales_return_posted_allocation_id' => $allocation->id, 'quantity' => $quantity, 'unit_price' => $source->unit_price,
                'commercial_snapshot' => ['description' => $source->description, 'invoice' => $source->commercial_snapshot,
                    'original_quantity' => $source->quantity, 'original_subtotal' => $source->subtotal,
                    'original_discount' => $source->discount_amount, 'original_tax' => $source->tax_amount, 'original_total' => $source->line_total,
                    'allocation' => 'cumulative-net-discount-tax-half-up-4-final-residual']];
        }

        return $lines;
    }

    private function input(array $data): array
    {
        $validated = Validator::make($data, [
            'sales_return_id' => 'required|integer|min:1', 'sales_invoice_id' => 'required|integer|min:1',
            'credit_date' => 'required|date_format:Y-m-d', 'reason' => 'nullable|string|max:16000',
            'items' => 'required|array|min:1|max:200', 'items.*' => 'required|array:sales_return_posted_allocation_id,quantity',
            'items.*.sales_return_posted_allocation_id' => 'required|integer|min:1|distinct', 'items.*.quantity' => 'required',
        ])->validate();
        if (array_diff(array_keys($data), ['sales_return_id', 'sales_invoice_id', 'credit_date', 'reason', 'items']) !== []) {
            $this->invalid('workflow_only');
        }
        $validated['sales_return_id'] = (int) $validated['sales_return_id'];
        $validated['sales_invoice_id'] = (int) $validated['sales_invoice_id'];
        $validated['reason'] ??= null;
        foreach ($validated['items'] as &$item) {
            $item['sales_return_posted_allocation_id'] = (int) $item['sales_return_posted_allocation_id'];
            $item['quantity'] = $this->decimals->decimal($item['quantity'], 'quantity', 18, 4);
        }
        unset($item);
        usort($validated['items'], fn ($a, $b) => $a['sales_return_posted_allocation_id'] <=> $b['sales_return_posted_allocation_id']);

        return $validated;
    }

    private function saveLines(int $id, array $lines): void
    {
        foreach ($lines as $line) {
            $line['commercial_snapshot'] = json_encode($line['commercial_snapshot'], JSON_THROW_ON_ERROR);
            DB::table('sales_credit_note_items')->insert($line + ['uuid' => (string) Str::uuid7(), 'sales_credit_note_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function draft(SalesCreditNote $note): void
    {
        if ($note->status !== 'draft' || $note->posted_at !== null || $note->posted_by !== null || $note->posting_payload_hash !== null
            || $note->posting_revision !== null || $note->submission_payload_hash === null || $note->cancelled_at !== null || $note->cancelled_by !== null) {
            $this->invalid('workflow_only');
        }
    }

    private function checkRevision(SalesCreditNote $note, string $revision): void
    {
        if (! hash_equals($this->revision($note), $revision)) {
            $this->invalid('changed');
        }
    }

    private function audit(User $actor, SalesCreditNote $note, string $event): void
    {
        app(AuditLogger::class)->record('sales_credit_note_'.$event, actor: $actor, metadata: [
            'sales_credit_note_uuid' => $note->uuid, 'sales_return_id' => $note->sales_return_id, 'sales_invoice_id' => $note->sales_invoice_id,
            'location_id' => $note->location_id, 'total_amount' => $note->total_amount, 'status' => $note->status]);
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['record' => __('credits.'.$key)]);
    }
}
