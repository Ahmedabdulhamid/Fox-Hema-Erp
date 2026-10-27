<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerAccess
{
    private static int $writing = 0;

    private ?bool $schemaReady = null;

    public function ready(): bool
    {
        return $this->schemaReady ??= Schema::hasTable('payment_customers')
            && Schema::hasTable('customer_invoice_accounts')
            && Schema::hasColumn('payments', 'treasury_account_id')
            && Schema::hasColumn('payment_refunds', 'recovery_operation_id');
    }

    public function requireReady(): void
    {
        abort_unless($this->ready(), 503, __('Customer settlement is unavailable until the pending Payments and Treasury migrations are applied.'));
    }

    public function __construct(private readonly PaymentsAccess $access) {}

    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    public static function write(callable $operation): mixed
    {
        self::$writing++;
        try {
            return $operation();
        } finally {
            self::$writing--;
        }
    }

    public function operation(User $actor, string $permission, int $location): void
    {
        $this->access->authorize($actor, PaymentsPermissions::VIEW, $location);
        $this->access->authorize($actor, $permission, $location);
        $this->requireReady();
    }

    public function scope(Builder $query, User $actor): Builder
    {
        $this->requireReady();

        return $this->access->scope($query, $actor);
    }

    public function allows(?User $actor, string $permission, ?int $location = null): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->access->authorize($actor, PaymentsPermissions::VIEW, $location);
            $this->access->authorize($actor, $permission, $location);

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    public function lock(): void
    {
        $this->requireReady();
        ModuleRegistryLock::acquire();
    }

    public function key(string $key): void
    {
        Validator::make(['operation_key' => $key], ['operation_key' => ['required', 'uuid']], [], __('payments.validation_attributes'))->validate();
    }

    public function match(?string $stored, string $expected): void
    {
        if (! hash_equals($stored ?? '', $expected)) {
            $this->invalid('operation_key', 'Conflicting settlement retry.');
        }
    }

    public function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
