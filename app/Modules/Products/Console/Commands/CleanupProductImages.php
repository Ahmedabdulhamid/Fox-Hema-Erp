<?php

namespace App\Modules\Products\Console\Commands;

use App\Modules\Products\Services\ProductImageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupProductImages extends Command
{
    protected $signature = 'products:cleanup-images {--limit=100 : Maximum pending files to retry}';

    protected $description = 'Retry committed product-image cleanup tasks; never remove referenced files';

    public function handle(ProductImageManager $manager): int
    {
        if (! Schema::hasTable('product_image_cleanup_tasks')) {
            $this->error('The Products cleanup migration is required.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        $failed = 0;
        foreach (DB::table('product_image_cleanup_tasks')->where(fn ($query) => $query->whereNull('ready_at')->orWhere('ready_at', '<=', now()))->orderBy('id')->limit($limit)->pluck('id') as $id) {
            if (! $manager->cleanup((int) $id)) {
                $failed++;
            }
        }
        $this->info('Cleanup complete. Pending failures: '.$failed);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
