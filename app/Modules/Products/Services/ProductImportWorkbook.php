<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\Size;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use XMLReader;
use ZipArchive;

class ProductImportWorkbook
{
    public const VERSION = 'products-v1';

    public const HEADERS = [
        'Products' => ['product_key', 'name', 'description', 'brand_uuid', 'category_uuid', 'status', 'has_fixed_selling_price', 'shared_sale_price'],
        'Variants' => ['product_key', 'variant_key', 'size_uuid', 'color_uuid', 'sale_price', 'reference_cost', 'is_active'],
        'ProductTaxes' => ['product_key', 'tax_uuid'],
        'ProductDiscounts' => ['product_key', 'discount_uuid'],
        'Images' => ['product_key', 'variant_key', 'image_key', 'upload_name', 'alt_text', 'sort_order', 'is_primary'],
    ];

    public function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workbook' => $message]);
    }

    /** Inspect the bounded container and XML without extracting or following external resources. */
    public function inspect(string $path): void
    {
        $zip = new ZipArchive;
        if (filesize($path) > config('product-import.workbook_bytes') || $zip->open($path) !== true) {
            $this->invalid(__('product_import.invalid_workbook'));
        }
        try {
            if ($zip->numFiles > config('product-import.zip_entries') || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $this->invalid(__('product_import.invalid_workbook'));
            }
            $expanded = 0;
            $physicalRows = 0;
            $workbookType = false;
            $entryNames = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                if (! str_ends_with($name, '/') && ! preg_match('~\A(?:\[Content_Types\]\.xml|_rels/\.rels|docProps/(?:app|core|custom)\.xml|xl/(?:workbook|styles|sharedStrings)\.xml|xl/_rels/workbook\.xml\.rels|xl/worksheets/[A-Za-z0-9_-]+\.xml|xl/worksheets/_rels/[A-Za-z0-9_-]+\.xml\.rels|xl/theme/theme[0-9]+\.xml|xl/comments[0-9]+\.xml|xl/drawings/vmlDrawing[0-9]+\.vml)\z~D', $name)) {
                    $this->invalid(__('product_import.invalid_workbook'));
                }
                if (isset($entryNames[strtolower($name)])) {
                    $this->invalid(__('product_import.invalid_workbook'));
                }
                $entryNames[strtolower($name)] = true;
                $expanded += $stat['size'];
                if ($expanded > config('product-import.expanded_bytes') || $stat['size'] > 32 * 1024 * 1024
                    || ($stat['encryption_method'] ?? 0) !== 0 || str_contains($name, '..') || str_contains($name, '\\')
                    || str_starts_with($name, '/') || str_contains($name, ':')
                    || preg_match('~(?:media|embeddings|externalLinks|vbaProject)~i', $name)
                    || (str_contains($name, 'drawings') && ! preg_match('~^xl/drawings/vmlDrawing[0-9]+\.vml$~D', $name))
                    || (! str_ends_with($name, '/') && ! preg_match('/\.(?:xml|rels|vml)$/iD', $name))) {
                    $this->invalid(__('product_import.invalid_workbook'));
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $this->invalid(__('product_import.invalid_workbook'));
                }
                $bytes = stream_get_contents($stream, $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== $stat['size']) {
                    fclose($stream);
                    $this->invalid(__('product_import.invalid_workbook'));
                }
                $xml = XMLReader::XML($bytes, flags: LIBXML_NONET | LIBXML_COMPACT);
                try {
                    $cells = 0;
                    while ($xml->read()) {
                        if ($xml->nodeType === XMLReader::DOC_TYPE || $xml->nodeType === XMLReader::ENTITY_REF) {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                        if ($xml->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }
                        if (str_ends_with($name, '.vml') && ! in_array($xml->localName, ['xml', 'shapelayout', 'idmap', 'shapetype', 'stroke', 'path'], true)) {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                        if ($xml->localName === 'Override' && $xml->getAttribute('PartName') === '/xl/workbook.xml') {
                            $workbookType = $xml->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
                        }
                        if ($xml->localName === 'Relationship') {
                            $target = $xml->getAttribute('Target') ?? '';
                            if ($xml->getAttribute('TargetMode') === 'External' || str_contains($target, ':') || str_contains($target, '\\') || str_contains($target, '%')) {
                                $this->invalid(__('product_import.invalid_workbook'));
                            }
                            $base = $name === '_rels/.rels' ? '' : str_replace('/_rels', '', dirname($name)).'/';
                            $parts = [];
                            foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : $base.$target) as $part) {
                                if ($part === '..') {
                                    if ($parts === []) {
                                        $this->invalid(__('product_import.invalid_workbook'));
                                    }
                                    array_pop($parts);
                                } elseif ($part !== '' && $part !== '.') {
                                    $parts[] = $part;
                                }
                            }
                            if ($zip->locateName(implode('/', $parts)) === false) {
                                $this->invalid(__('product_import.invalid_workbook'));
                            }
                        }
                        if ($xml->localName === 'definedName') {
                            $definedName = $xml->getAttribute('name') ?? '';
                            if (! ProductImportEmployeeTemplate::safeDefinedName($definedName, $xml->readString())) {
                                $this->invalid(__('product_import.invalid_workbook'));
                            }
                        }
                        if ($xml->localName === 'dataValidation' && $xml->getAttribute('type') !== 'list') {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                        if ($xml->localName === 'formula1' && ! ProductImportEmployeeTemplate::allowedName(ltrim(trim($xml->readString()), '='))) {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                        if ($xml->localName === 'formula2') {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                        if ($xml->localName === 'row') {
                            $cells = 0;
                            $physicalRows++;
                            if ($physicalRows > config('product-import.rows') + 100 || (int) $xml->getAttribute('r') > config('product-import.rows') + 100) {
                                $this->invalid(__('product_import.row_limit'));
                            }
                        }
                        if ($xml->localName === 'c') {
                            $cells++;
                            if ($cells > 255 || ! preg_match('/^[A-Z]{1,2}[0-9]+$/D', $xml->getAttribute('r') ?? '') || in_array($xml->getAttribute('t'), ['e', 'd'], true)) {
                                $this->invalid(__('product_import.invalid_workbook'));
                            }
                            preg_match('/^([A-Z]+)[0-9]+$/D', $xml->getAttribute('r'), $match);
                            $column = 0;
                            foreach (str_split($match[1]) as $letter) {
                                $column = $column * 26 + ord($letter) - 64;
                            }
                            if ($column > 255) {
                                $this->invalid(__('product_import.invalid_workbook'));
                            }
                        }
                        if (in_array($xml->localName, ['f', 'mergeCell', 'oleObject'], true)) {
                            $this->invalid(__('product_import.invalid_workbook'));
                        }
                    }
                } finally {
                    $xml->close();
                    fclose($stream);
                }
            }
            if (! $workbookType) {
                $this->invalid(__('product_import.invalid_workbook'));
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->invalid(__('product_import.invalid_workbook'));
        } finally {
            $zip->close();
        }
    }

    /** @return \Generator<int, array{sheet: string, row_number: int, product_key: string, data: array<string, string>}> */
    public function rows(ProductImport $import): \Generator
    {
        $storage = app(ProductImportStorage::class);
        $path = $storage->assertSource($import);
        $this->inspect($path);
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->setTempFolder($storage->parserDirectory($import));
        $metadata = $this->employeeMetadata($path, $options);
        $employeeLocale = $metadata['locale'];
        $reader = new Reader($options);
        $seen = [];
        $total = 0;
        $counts = [];
        $marker = false;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();
                if (isset($seen[$name]) || count($seen) >= 7 || ! in_array($name, [...array_keys(self::HEADERS), 'Instructions', 'References'], true)) {
                    $this->invalid(__('product_import.invalid_headers'));
                }
                $seen[$name] = true;
                $header = null;
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $total++;
                    if ($total > config('product-import.rows') + 100) {
                        $this->invalid(__('product_import.row_limit'));
                    }
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();
                        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
                            $this->invalid(__('product_import.invalid_cells'));
                        }
                        if (is_string($value) && (mb_strlen($value) > 10000 || strlen($value) > 65535)) {
                            $this->invalid(__('product_import.invalid_cells'));
                        }
                        $values[] = $value;
                    }
                    if ($name === 'Instructions') {
                        if ((int) $number === 1) {
                            $marker = ($values[0] ?? null) === ($employeeLocale === null ? self::VERSION : ProductImportEmployeeFormat::VERSION.'-'.$employeeLocale);
                        }

                        continue;
                    }
                    if ($name === 'References') {
                        continue;
                    }
                    if ($header === null) {
                        $expected = $employeeLocale === null ? self::HEADERS[$name] : app(ProductImportEmployeeFormat::class)->headers($name, $employeeLocale);
                        if ((int) $number !== 1 || $values !== $expected) {
                            $this->invalid(__('product_import.invalid_headers'));
                        }
                        $header = $values;

                        continue;
                    }
                    if (count(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '')) === 0) {
                        continue;
                    }
                    if (count($values) > count($header)) {
                        $this->invalid(__('product_import.invalid_headers'));
                    }
                    if ($employeeLocale !== null) {
                        $data = app(ProductImportEmployeeFormat::class)->row($import, $name, $values, $employeeLocale, (int) $number, $metadata['bindings']);
                        if ($name === 'Variants' && $data['color_uuid'] === '' && $data['size_uuid'] === ''
                            && ($data['_employee']['raw']['color'] ?? '') === '' && ($data['_employee']['raw']['size'] ?? '') === '') {
                            $key = app(ProductImportProductIdentifiers::class)->normalizeName($data['_employee']['name']);
                            $data['_export_unallocated'] = isset($metadata['unallocated'][$key]);
                        }
                    } else {
                        $data = [];
                        foreach ($header as $column => $key) {
                            $value = $values[$column] ?? null;
                            if ($value !== null && ! is_string($value) && ! (in_array($key, ['has_fixed_selling_price', 'is_active', 'is_primary', 'sort_order'], true) && is_numeric($value) && (float) (int) $value === (float) $value)) {
                                $this->invalid(__('product_import.invalid_cells'));
                            }
                            $data[$key] = trim((string) $value);
                        }
                    }
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                    $limit = match ($name) {
                        'Products' => config('product-import.products'),
                        'Variants' => config('product-import.variants'),
                        'Images' => config('product-import.images'),
                        default => config('product-import.rows'),
                    };
                    if ($counts[$name] > $limit) {
                        $this->invalid(__('product_import.row_limit'));
                    }
                    yield ['sheet' => $name, 'row_number' => (int) $number, 'product_key' => strtolower($data['product_key']), 'data' => $data];
                }
                if (isset(self::HEADERS[$name]) && $header === null) {
                    $this->invalid(__('product_import.invalid_headers'));
                }
            }
            if (! $marker || array_diff(['Products', 'Variants', 'Images', 'Instructions'], array_keys($seen)) !== []) {
                $this->invalid(__('product_import.invalid_headers'));
            }
        } finally {
            $reader->close();
        }
    }

    private function employeeMetadata(string $path, Options $options): array
    {
        $reader = new Reader($options);
        $locale = null;
        $referenceRows = [];
        $unallocated = [];
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() === 'References') {
                    foreach ($sheet->getRowIterator() as $number => $row) {
                        if ($number <= 5002) {
                            $referenceRows[] = array_map(fn ($cell): mixed => $cell->getValue(), array_slice($row->getCells(), 0, 17));
                        }
                        if ($number > config('product-import.rows') + 100) {
                            $this->invalid(__('product_import.row_limit'));
                        }
                    }

                    continue;
                }
                if ($sheet->getName() !== 'Instructions') {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $instruction = ($row->getCells()[0] ?? null)?->getValue();
                    if (is_string($instruction) && str_starts_with($instruction, ProductImportEmployeeFormat::UNALLOCATED_PREFIX)) {
                        $productName = base64_decode(substr($instruction, strlen(ProductImportEmployeeFormat::UNALLOCATED_PREFIX)), true);
                        if ($productName === false || ! mb_check_encoding($productName, 'UTF-8') || mb_strlen($productName) > 255) {
                            $this->invalid(__('product_import.invalid_headers'));
                        }
                        $unallocated[app(ProductImportProductIdentifiers::class)->normalizeName($productName)] = true;
                    }
                    if ((int) $number === 1) {
                        $marker = ($row->getCells()[0] ?? null)?->getValue();
                        foreach (['ar', 'en'] as $language) {
                            if ($marker === ProductImportEmployeeFormat::VERSION.'-'.$language) {
                                $locale = $language;
                            }
                        }
                    }
                    if ($number > config('product-import.rows') + 100) {
                        $this->invalid(__('product_import.row_limit'));
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $bindings = [];
        if ($locale !== null) {
            if (count($referenceRows) > 5001) {
                $this->invalid(__('product_import.employee_reference_limit'));
            }
            $expected = [...array_keys(ProductImportEmployeeTemplate::LISTS), ...array_map(fn (string $kind): string => $kind.'_binding', array_keys(ProductImportReferenceResolver::MODELS))];
            if (array_shift($referenceRows) !== $expected) {
                $this->invalid(__('product_import.invalid_headers'));
            }
            foreach ($referenceRows as $row) {
                foreach (array_keys(ProductImportReferenceResolver::MODELS) as $index => $kind) {
                    $label = $row[$index] ?? '';
                    $uuid = $row[$index + 11] ?? '';
                    if ($label === '' && $uuid === '') {
                        continue;
                    }
                    if (! is_string($label) || ! is_string($uuid) || ! Str::isUuid($uuid)) {
                        $this->invalid(__('product_import.invalid_headers'));
                    }
                    $key = app(ProductImportProductIdentifiers::class)->normalizeName($label);
                    if (isset($bindings[$kind][$key])) {
                        $this->invalid(__('product_import.invalid_headers'));
                    }
                    $bindings[$kind][$key] = $uuid;
                }
            }
        }

        return compact('locale', 'bindings', 'unallocated');
    }

    /** Explicit StringCell prevents formula interpretation in generated workbooks. */
    private function row(array $values): Row
    {
        return new Row(array_map(fn (mixed $value): StringCell => new StringCell((string) ($value ?? ''), null), $values));
    }

    public function template(User $actor, string $path, bool $example = false, ?string $locale = null): void
    {
        app(ProductImportEmployeeTemplate::class)->write($actor, $path, $example, $locale ?? app()->getLocale());
    }

    public function legacyTemplate(User $actor, string $path, bool $example = false): void
    {
        app(ProductImportAccess::class)->authorize($actor);
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Instructions');
            $writer->addRow($this->row([self::VERSION]));
            $writer->addRow($this->row([__('product_import.instructions')]));
            $writer->addRow($this->row([__('product_import.image_matching')]));
            foreach (self::HEADERS as $name => $headers) {
                $writer->addNewSheetAndMakeItCurrent()->setName($name);
                $writer->addRow($this->row($headers));
                if ($example && $name === 'Products') {
                    $writer->addRow($this->row(['EXAMPLE-001', 'قميص قطني', '', '', '', 'draft', '1', '249.9000']));
                }
                if ($example && $name === 'Variants') {
                    $writer->addRow($this->row(['EXAMPLE-001', 'standard', '', '', '', '', '1']));
                }
                if ($example && $name === 'Images') {
                    $writer->addRow($this->row(['EXAMPLE-001', '', 'general-front', 'example-general.png', 'General product', '0', '1']));
                    $writer->addRow($this->row(['EXAMPLE-001', 'standard', 'variant-front', 'example-variant.png', 'Standard variant', '0', '1']));
                }
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('References');
            $writer->addRow($this->row(['kind', 'uuid', 'name', 'code', 'parent_name', 'usage', 'scope', 'effective_from', 'effective_to']));
            foreach (['brands' => Brand::class, 'categories' => Category::class, 'sizes' => Size::class, 'colors' => Color::class, 'taxes' => Tax::class, 'discounts' => Discount::class] as $kind => $class) {
                $allowed = in_array($kind, ['taxes', 'discounts'], true)
                    ? app(ProductAssignmentManager::class)->canAssign($actor, $kind)
                    : app(ProductsAccess::class)->allows($actor, 'products.'.$kind.'.view');
                if (! $allowed) {
                    continue;
                }
                $query = $class::where('is_active', true);
                if (in_array($kind, ['taxes', 'discounts'], true)) {
                    $query->whereIn('id', array_keys(app(ProductAssignmentManager::class)->options($actor, $kind)));
                }
                foreach ($query->orderBy('id')->limit(5000)->cursor() as $record) {
                    $writer->addRow($this->row([$kind, $record->uuid, $record->name, $record->code, '', $record->usage, $record->scope, $record->getRawOriginal('effective_from') ?? $record->getRawOriginal('starts_at'), $record->getRawOriginal('effective_to') ?? $record->getRawOriginal('ends_at')]));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
