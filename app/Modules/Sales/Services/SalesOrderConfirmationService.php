<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderConfirmationService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesOrderService $drafts,
        private readonly SalesReservationService $reservations) {}

    public function confirm(User $actor, SalesOrder $order, ?string $revision = null): SalesOrder
    {
        $this->authorize($actor, $order, SalesPermissions::CONFIRM);

        return DB::transaction(function () use ($actor, $order, $revision): SalesOrder {
            ModuleRegistryLock::acquire();
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $order, SalesPermissions::CONFIRM);
            $order->items()->orderBy('id')->lockForUpdate()->get();
            $this->assertUnfulfilled($order);
            if ($order->status === 'confirmed') {
                $this->assertNotCancelled($order);
                $this->assertFingerprint($order, 'confirmation_payload_hash');
                if ($order->confirmed_at === null) {
                    $this->invalid('This sales order has no valid confirmation audit.');
                }
                $this->reservations->verify($order);

                return $order->load(['reservations', 'confirmedBy']);
            }
            if ($order->status !== 'draft' || $order->confirmed_at !== null || $order->confirmed_by !== null || $order->confirmation_payload_hash !== null) {
                $this->invalid('Only draft sales orders can be confirmed.');
            }
            $this->assertNotCancelled($order);
            $this->checkRevision($order, $revision);
            $lines = $this->drafts->validateForConfirmation($actor, $order);
            $hash = $this->fingerprint($order);
            $this->reservations->reserve($order, $lines);
            // Lifecycle model writes are deliberately blocked; this workflow has locked and validated all evidence.
            DB::table('sales_orders')->where('id', $order->id)->update([
                'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $actor->id,
                'confirmation_payload_hash' => $hash, 'updated_at' => now(),
            ]);

            return $order->refresh()->load(['reservations', 'confirmedBy']);
        }, 3);
    }

    public function cancel(User $actor, SalesOrder $order, ?string $revision = null): SalesOrder
    {
        $this->authorize($actor, $order, SalesPermissions::CANCEL);

        return DB::transaction(function () use ($actor, $order, $revision): SalesOrder {
            ModuleRegistryLock::acquire();
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $order, SalesPermissions::CANCEL);
            $order->items()->orderBy('id')->lockForUpdate()->get();
            $this->assertUnfulfilled($order);
            if ($order->status === 'cancelled') {
                $this->assertFingerprint($order, 'cancellation_payload_hash');
                if ($order->cancelled_at === null) {
                    $this->invalid('This sales order has no valid cancellation audit.');
                }
                if ($order->confirmation_payload_hash !== null) {
                    $this->assertFingerprint($order, 'confirmation_payload_hash');
                    $this->reservations->verify($order, released: true);
                } elseif ($order->reservations()->exists() || $order->confirmed_at !== null || $order->confirmed_by !== null) {
                    $this->invalid('Draft cancellation cannot release unexpected reservations.');
                }

                return $order->load('reservations');
            }
            if (! in_array($order->status, ['draft', 'confirmed'], true)) {
                $this->invalid('Only unfulfilled draft or confirmed sales orders can be cancelled.');
            }
            $this->assertNotCancelled($order);
            $this->checkRevision($order, $revision);
            if ($order->status === 'confirmed') {
                if ($order->confirmed_at === null) {
                    $this->invalid('This sales order has no valid confirmation audit.');
                }
                $this->assertFingerprint($order, 'confirmation_payload_hash');
                $this->reservations->release($order);
            } elseif ($order->reservations()->exists() || $order->confirmation_payload_hash !== null || $order->confirmed_at !== null || $order->confirmed_by !== null) {
                $this->invalid('Draft cancellation cannot release unexpected reservations.');
            }
            DB::table('sales_orders')->where('id', $order->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancellation_payload_hash' => $this->fingerprint($order), 'updated_at' => now(),
            ]);

            return $order->refresh()->load(['reservations', 'cancelledBy']);
        }, 3);
    }

    public function fingerprint(SalesOrder $order): string
    {
        $header = Arr::except($order->getAttributes(), ['status', 'fulfillment_status', 'confirmed_at', 'confirmed_by',
            'cancelled_at', 'cancelled_by', 'confirmation_payload_hash', 'cancellation_payload_hash', 'created_at', 'updated_at']);

        $items = $order->items()->orderBy('id')->get()->map(function ($item): array {
            $attributes = $item->getAttributes();
            // Preserve fingerprints created before the nullable evidence column existed.
            if (($attributes['commercial_snapshot'] ?? null) === null) {
                unset($attributes['commercial_snapshot']);
            }

            return $attributes;
        })->all();

        return hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
    }

    private function assertFingerprint(SalesOrder $order, string $field): void
    {
        if ($order->{$field} === null || ! hash_equals($order->{$field}, $this->fingerprint($order))) {
            $this->invalid('This sales order conflicts with its recorded lifecycle operation.');
        }
    }

    private function assertUnfulfilled(SalesOrder $order): void
    {
        if ($order->fulfillment_status !== 'unfulfilled' || $order->hasDownstreamDocuments()) {
            $this->invalid('Orders with fulfillment or downstream documents cannot use these lifecycle actions.');
        }
    }

    private function checkRevision(SalesOrder $order, ?string $revision): void
    {
        if ($revision !== null && ! hash_equals($this->drafts->revision($order), $revision)) {
            $this->invalid('This sales order changed. Reload before continuing.');
        }
    }

    private function assertNotCancelled(SalesOrder $order): void
    {
        if ($order->cancelled_at !== null || $order->cancelled_by !== null || $order->cancellation_payload_hash !== null) {
            $this->invalid('This sales order conflicts with its recorded lifecycle operation.');
        }
    }

    private function authorize(User $actor, SalesOrder $order, string $permission): void
    {
        $this->access->authorize($actor, SalesPermissions::VIEW, (int) $order->location_id);
        $this->access->authorize($actor, $permission, (int) $order->location_id);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['record' => __($message)]);
    }
}
