<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SalesReturnApprovalService
{
    public function __construct(private readonly SalesReturnService $returns, private readonly SalesReturnQuantityService $quantities,
        private readonly SalesReturnAllocationService $allocations) {}

    public function approve(User $actor, SalesReturn $return, string $revision): SalesReturn
    {
        return DB::transaction(function () use ($actor, $return, $revision): SalesReturn {
            [$order, $return] = $this->returns->lock($actor, $return, SalesPermissions::RETURNS_APPROVE);
            abort_if((int) $actor->id === (int) $return->created_by, 403, __('returns.self_approval'));
            $this->returns->assertNoDownstream($return);
            if ($return->status === 'approved') {
                $this->returns->validateSources($order, $return);
                $this->verifyApproved($return);
                if (! in_array($revision, [$return->approval_revision, $this->returns->revision($return)], true)) {
                    $this->returns->invalid('changed');
                }
                $this->quantities->quantities($return->items()->pluck('sales_delivery_item_id')->all());

                return $return->load('items.invoiceAllocations', 'quantityClaims');
            }
            $this->returns->draft($return);
            $this->returns->checkRevision($return, $revision);
            $this->returns->validateSources($order, $return);
            $this->quantities->assertAvailable($return->items()->get()->toArray(), $return->id);
            $this->allocations->verifyDraft($order, $return);
            $hash = $this->returns->fingerprint($return);
            $now = now();
            foreach ($return->items()->orderBy('id')->get() as $item) {
                DB::table('sales_return_quantity_claims')->insert([
                    'sales_return_id' => $return->id, 'sales_return_item_id' => $item->id,
                    'sales_delivery_item_id' => $item->sales_delivery_item_id, 'quantity' => $item->quantity,
                    'status' => 'active', 'approval_identity' => $hash, 'approved_by' => $actor->id, 'approved_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('sales_returns')->where('id', $return->id)->update([
                'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $now,
                'approval_revision' => $revision, 'approval_payload_hash' => $hash, 'updated_at' => $now,
            ]);
            $return->refresh();
            DB::table('sales_returns')->where('id', $return->id)->update(['approval_evidence_hash' => $this->evidenceHash($return)]);
            $this->verifyApproved($return->refresh());
            $this->quantities->quantities($return->items()->pluck('sales_delivery_item_id')->all());
            $this->returns->audit($actor, $return, 'sales_return_approved');

            return $return->load('items.invoiceAllocations', 'quantityClaims');
        }, 3);
    }

    public function cancel(User $actor, SalesReturn $return, string $revision, string $reason): SalesReturn
    {
        if (trim($reason) === '' || mb_strlen($reason) > 5000) {
            $this->returns->invalid('reason_required');
        }

        return DB::transaction(function () use ($actor, $return, $revision, $reason): SalesReturn {
            [, $return] = $this->returns->lock($actor, $return, SalesPermissions::RETURNS_CANCEL);
            $this->returns->assertNoDownstream($return);
            if ($return->status === 'cancelled') {
                if ($return->cancelled_at === null || $return->cancelled_by === null
                    || $return->cancellation_reason !== $reason || $return->cancellation_payload_hash === null
                    || ! hash_equals($return->cancellation_payload_hash, $this->cancellationHash($return))
                    || ! in_array($revision, [$return->cancellation_revision, $this->returns->revision($return)], true)) {
                    $this->returns->invalid('changed');
                }

                return $return;
            }
            $this->returns->checkRevision($return, $revision);
            if ($return->status === 'approved') {
                $this->verifyApproved($return);
            } else {
                $this->returns->draft($return);
            }
            $now = now();
            if ($return->status === 'approved') {
                DB::table('sales_return_quantity_claims')->where('sales_return_id', $return->id)->where('status', 'active')->update([
                    'status' => 'released', 'released_by' => $actor->id, 'released_at' => $now, 'release_reason' => $reason, 'updated_at' => $now,
                ]);
            }
            DB::table('sales_returns')->where('id', $return->id)->update([
                'status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => $now,
                'cancellation_reason' => $reason, 'cancellation_revision' => $revision, 'updated_at' => $now,
            ]);
            $return->refresh();
            DB::table('sales_returns')->where('id', $return->id)->update(['cancellation_payload_hash' => $this->cancellationHash($return)]);
            $this->quantities->quantities($return->items()->pluck('sales_delivery_item_id')->all());
            $this->returns->audit($actor, $return->refresh(), 'sales_return_cancelled');

            return $return->load('quantityClaims');
        }, 3);
    }

    public function verifyApproved(SalesReturn $return, bool $posted = false): void
    {
        if ($return->status !== ($posted ? 'posted' : 'approved') || $return->approved_at === null || $return->approved_by === null
            || (int) $return->approved_by === (int) $return->created_by
            || $return->approval_revision === null || $return->approval_payload_hash === null || $return->approval_evidence_hash === null
            || $return->cancelled_at !== null || $return->cancelled_by !== null || $return->cancellation_payload_hash !== null
            || (! $posted && ($return->posted_at !== null || $return->posted_by !== null))
            || ! hash_equals($return->approval_payload_hash, $this->returns->fingerprint($return))
            || ! hash_equals($return->approval_evidence_hash, $this->evidenceHash($return))) {
            $this->returns->invalid('quantity_evidence_invalid');
        }
        $claims = $return->quantityClaims()->orderBy('id')->lockForUpdate()->get()->keyBy('sales_return_item_id');
        $items = $return->items()->orderBy('id')->get();
        if ($claims->count() !== $items->count()) {
            $this->returns->invalid('quantity_evidence_invalid');
        }
        foreach ($items as $item) {
            $claim = $claims->get($item->id);
            if ($claim === null || $claim->status !== ($posted ? 'consumed' : 'active') || $claim->quantity !== $item->quantity
                || (int) $claim->sales_delivery_item_id !== (int) $item->sales_delivery_item_id
                || (int) $claim->approved_by !== (int) $return->approved_by || $claim->approval_identity !== $return->approval_payload_hash
                || $claim->getRawOriginal('approved_at') !== $return->getRawOriginal('approved_at')
                || $claim->released_at !== null || $claim->released_by !== null) {
                $this->returns->invalid('quantity_evidence_invalid');
            }
        }
        $this->allocations->verifyRecorded($return);
    }

    private function evidenceHash(SalesReturn $return): string
    {
        $claims = $return->quantityClaims()->orderBy('id')->get()->map(fn ($claim) => Arr::only($claim->getAttributes(),
            ['id', 'sales_return_id', 'sales_return_item_id', 'sales_delivery_item_id', 'quantity', 'approval_identity', 'approved_by', 'approved_at']))->all();

        return hash('sha256', json_encode([$return->approval_payload_hash, $return->approval_revision,
            $return->getRawOriginal('approved_at'), $return->approved_by, $claims], JSON_THROW_ON_ERROR));
    }

    private function cancellationHash(SalesReturn $return): string
    {
        return hash('sha256', json_encode([$this->returns->fingerprint($return),
            Arr::except($return->getAttributes(), ['cancellation_payload_hash', 'posting_payload_hash', 'posting_evidence_hash', 'posting_revision']),
            $return->quantityClaims()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }
}
