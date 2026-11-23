<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductCreationManager;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Products\Services\ProductImportManager;
use App\Modules\Products\Services\ProductImportProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database) || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || getenv('DB_PORT') !== '33316') {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-product-import-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$testRoot = realpath(storage_path('framework/testing'));
foreach (['PI_ROOT', 'PI_PUBLIC'] as $name) {
    $path = realpath(getenv($name));
    if ($path === false || ! str_starts_with($path.DIRECTORY_SEPARATOR, $testRoot.DIRECTORY_SEPARATOR)) {
        exit(2);
    }
}
config(['product-import.root' => getenv('PI_ROOT'), 'filesystems.disks.public.root' => getenv('PI_PUBLIC'), 'read_cache.enabled' => false]);
$mode = getenv('PI_MODE');
if ($mode === 'before_commit') {
    app()->instance(ProductImageManager::class, new class extends ProductImageManager
    {
        public function storeImmutableUpload(UploadedFile $upload, string $directory): string
        {
            parent::storeImmutableUpload($upload, $directory);
            fwrite(STDOUT, "allocated\n");
            fflush(STDOUT);
            while (true) {
                usleep(50000);
            }
        }
    });
}
if ($mode === 'after_commit') {
    app()->instance(ProductImageManager::class, new class extends ProductImageManager
    {
        public function save(User $actor, Product $product, array $data, ?ProductImage $image = null): ProductImage
        {
            $saved = parent::save($actor, $product, $data, $image);
            DB::afterCommit(static function (): void {
                fwrite(STDOUT, "committed\n");
                fflush(STDOUT);
                while (true) {
                    usleep(50000);
                }
            });

            return $saved;
        }
    });
}
while (microtime(true) < (float) getenv('PI_START')) {
    usleep(1000);
}
try {
    if ($mode === 'manual') {
        $actor = User::findOrFail((int) getenv('PI_ACTOR'));
        setPermissionsTeamId(null);
        app(ProductCreationManager::class)->create($actor, ['name' => 'Concurrent manual product', 'default_sku' => getenv('PI_KEY')]);
    } elseif (in_array($mode, ['replace', 'start'], true)) {
        $actor = User::findOrFail((int) getenv('PI_ACTOR'));
        setPermissionsTeamId(null);
        $import = ProductImport::findOrFail((int) getenv('PI_IMPORT'));
        $manager = app(ProductImportManager::class);
        if ($mode === 'start') {
            $manager->start($actor, $import);
        } else {
            $manager->discard($actor, $import);
            $filename = getenv('PI_REPLACEMENT');
            if (! in_array($filename, ['one', 'two'], true)) {
                exit(2);
            }
            $manager->submit($actor, new UploadedFile(getenv('PI_ROOT').'/replacement-'.$filename.'.xlsx', 'products.xlsx', test: true), []);
        }
    } else {
        app(ProductImportProcessor::class)->run((int) getenv('PI_IMPORT'));
    }
    if ($mode === 'after_commit') {
        exit(92);
    }
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    if (! in_array($mode, ['replace', 'start'], true) || $exception->getStatusCode() !== 409) {
        throw $exception;
    }
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
