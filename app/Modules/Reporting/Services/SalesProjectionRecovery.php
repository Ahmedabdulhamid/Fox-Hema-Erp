<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Jobs\ProjectSalesDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SalesProjectionRecovery
{
    protected string $domain = 'sales';

    protected string $projectionClass = SalesProjection::class;

    protected string $jobClass = ProjectSalesDay::class;

    protected string $scopeColumn = 'location_id';

    /** @return array<string, string> */
    protected function sources(): array
    {
        return ['sales_invoices' => 'invoice_date', 'sales_credit_notes' => 'credit_date'];
    }

    /** A rotating, bounded scan repairs missed hooks and reconciles old source records. */
    public function scan(int $limit = 100): void
    {
        $limit = max(1, min(500, $limit));
        foreach ($this->sources() as $table => $dateColumn) {
            DB::transaction(function () use ($limit, $table, $dateColumn): void {
                $checkpoints = 'reporting_'.$this->domain.'_checkpoints';
                DB::table($checkpoints)->insertOrIgnore(['source' => $table, 'last_id' => 0]);
                $checkpoint = DB::table($checkpoints)->where('source', $table)->lockForUpdate()->first();
                $documents = DB::table($table)->where('id', '>', $checkpoint->last_id)
                    ->orderBy('id')->limit($limit)->select(['id', 'location_id'])->selectRaw($dateColumn.' AS report_date')->get();
                foreach ($documents->filter(fn ($document): bool => $document->location_id !== null && $document->report_date !== null)
                    ->unique(fn ($document): string => $document->location_id.':'.$document->report_date) as $document) {
                    app($this->projectionClass)->requestIfChanged((int) $document->location_id, $document->report_date);
                }
                DB::table($checkpoints)->where('source', $table)->update([
                    'last_id' => $documents->last()?->id ?? 0, 'scanned_at' => now(),
                ]);
            }, 3);
        }
    }

    /** Durable leases prevent unbounded duplicate dispatch while workers are unavailable. */
    public function dispatch(int $limit = 100): int
    {
        $dispatched = 0;
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $partition = DB::transaction(function (): ?object {
                $partitions = 'reporting_'.$this->domain.'_partitions';
                $partition = DB::table($partitions)->whereColumn('revision', '>', 'projected_revision')
                    ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(5)))
                    ->orderBy('requested_at')->orderBy('id')->lockForUpdate()->first();
                if ($partition !== null) {
                    DB::table($partitions)->where('id', $partition->id)->update(['dispatched_at' => now()]);
                }

                return $partition;
            }, 3);
            if ($partition === null) {
                break;
            }
            try {
                $this->dispatchProjection($partition);
                $dispatched++;
            } catch (Throwable $exception) {
                DB::table('reporting_'.$this->domain.'_partitions')->where('id', $partition->id)->update(['dispatched_at' => null]);
                Log::warning('Reporting dispatch failed; durable work will be retried.', ['domain' => $this->domain, 'exception' => $exception::class]);
                break;
            }
        }

        return $dispatched;
    }

    protected function dispatchProjection(object $partition): void
    {
        $jobClass = $this->jobClass;
        $jobClass::dispatch((int) $partition->{$this->scopeColumn}, $partition->report_date)->afterCommit();
    }
}
