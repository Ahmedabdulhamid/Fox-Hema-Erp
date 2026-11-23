<?php

namespace App\Modules\Reporting\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

abstract class CashProjection
{
    public string $domain;

    /** @var list<string> */
    protected array $keys;

    /** @var list<string> */
    protected array $amounts;

    public static function forDomain(string $domain): self
    {
        return app(match ($domain) {
            'treasury' => TreasuryProjection::class,'pos' => PosProjection::class,default => throw new \InvalidArgumentException('Unknown reporting domain.')
        });
    }

    public function scopeColumn(): string
    {
        return $this->domain === 'treasury' ? 'account_id' : 'location_id';
    }

    public function request(int $scope, string $date): void
    {
        app(DailyProjectionState::class)->request($this->domain, $scope, $date);
    }

    public function project(int $scope, string $date): bool
    {
        return app(DailyProjectionState::class)->project($this->domain, $scope, $date, function () use ($scope, $date): void {
            $rows = $this->rows($scope, $date);
            DB::table('reporting_'.$this->domain.'_daily')->where($this->scopeColumn(), $scope)->where('report_date', $date)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('reporting_'.$this->domain.'_daily')->insert(array_map(fn (array $row): array => $row + [$this->scopeColumn() => $scope, 'report_date' => $date, 'projected_at' => now()], $chunk));
            }
        });
    }

    public function matches(int $scope, string $date): bool
    {
        $stored = DB::table('reporting_'.$this->domain.'_daily')->where($this->scopeColumn(), $scope)->where('report_date', $date)->get()->map(fn ($row): array => (array) $row)->all();

        return $this->canonical($stored) === $this->canonical($this->rows($scope, $date));
    }

    public function isCurrent(int $scope, string $date): bool
    {
        $partition = DB::table('reporting_'.$this->domain.'_partitions')->where($this->scopeColumn(), $scope)->where('report_date', $date)->first();

        return $partition !== null && $partition->projected_at !== null && $partition->revision === $partition->projected_revision && $this->matches($scope, $date);
    }

    public function requestIfChanged(int $scope, string $date): void
    {
        DB::transaction(function () use ($scope, $date): void {
            $partition = DB::table('reporting_'.$this->domain.'_partitions')->where($this->scopeColumn(), $scope)->where('report_date', $date)->lockForUpdate()->first();
            if ($partition === null || ($partition->revision === $partition->projected_revision && ! $this->matches($scope, $date))) {
                $this->request($scope, $date);
            }
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    abstract protected function rows(int $scope, string $date): array;

    /** @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function canonical(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = implode(':', array_map(fn (string $field): string => (string) $row[$field], $this->keys));
            $values = [];
            foreach ([...$this->keys, ...$this->amounts] as $field) {
                $values[$field] = in_array($field, $this->amounts, true) ? (string) BigDecimal::of((string) $row[$field])->toScale(4) : (string) $row[$field];
            }
            $result[$key] = $values;
        }
        ksort($result);

        return $result;
    }
}
