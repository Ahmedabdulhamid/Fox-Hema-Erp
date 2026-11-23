<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

class ProductImportEmployeeTemplate
{
    public const LISTS = ['ImportBrands' => 'brands', 'ImportCategories' => 'categories', 'ImportColors' => 'colors', 'ImportSizes' => 'sizes', 'ImportTaxes' => 'taxes', 'ImportDiscounts' => 'discounts', 'ImportStatuses' => 'status', 'ImportVariantStatuses' => 'active', 'ImportPricing' => 'pricing', 'ImportPrimary' => 'boolean', 'ImportImageScope' => 'scope'];

    public const VALIDATIONS = [
        'Products' => ['C' => 'ImportBrands', 'D' => 'ImportCategories', 'E' => 'ImportStatuses', 'F' => 'ImportPricing'],
        'Variants' => ['A' => 'ImportProducts', 'B' => 'ImportColors', 'C' => 'ImportSizes', 'F' => 'ImportVariantStatuses'],
        'ProductTaxes' => ['A' => 'ImportProducts', 'B' => 'ImportTaxes'],
        'ProductDiscounts' => ['A' => 'ImportProducts', 'B' => 'ImportDiscounts'],
        'Images' => ['A' => 'ImportProducts', 'B' => 'ImportColors', 'C' => 'ImportSizes', 'E' => 'ImportPrimary', 'H' => 'ImportImageScope'],
    ];

    public static function productFormula(): string
    {
        $last = (int) config('product-import.products') + 1;

        return 'OFFSET(\'Products\'!$A$2,0,0,MAX(1,COUNTA(\'Products\'!$A$2:$A$'.$last.')),1)';
    }

    public static function allowedName(string $name): bool
    {
        return $name === 'ImportProducts' || array_key_exists($name, self::LISTS);
    }

    public static function safeDefinedName(string $name, string $formula): bool
    {
        $formula = strtoupper(str_replace(["'", ' ', "\n", "\r"], '', ltrim($formula, '=')));
        if ($name === 'ImportProducts') {
            return $formula === strtoupper(str_replace("'", '', self::productFormula()));
        }
        $index = array_search($name, array_keys(self::LISTS), true);
        if ($index === false) {
            return false;
        }
        $column = chr(65 + $index);

        return preg_match('/^REFERENCES!\$'.$column.'\$2:\$'.$column.'\$([0-9]+)$/D', $formula, $match) === 1 && (int) $match[1] >= 2 && (int) $match[1] <= 5001;
    }

    public function write(User $actor, string $path, bool $example = false, string $locale = 'ar'): void
    {
        app(ProductImportAccess::class)->authorize($actor);
        $this->writeWorkbook($actor, $path, $example, $locale);
    }

    /** @param array<string, list<array<string, string>>> $data */
    public function writeExport(User $actor, string $path, array $data, string $locale, array $instructions = [], ?array $references = null): void
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        $this->writeWorkbook($actor, $path, false, $locale, $data, $instructions, $references);
    }

    private function writeWorkbook(User $actor, string $path, bool $example, string $locale, array $data = [], array $instructions = [], ?array $references = null): void
    {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw ValidationException::withMessages(['locale' => __('product_import.invalid_headers')]);
        }
        $resolver = app(ProductImportReferenceResolver::class);
        $lists = [];
        $bindings = [];
        foreach (self::LISTS as $name => $kind) {
            if (isset(ProductImportReferenceResolver::MODELS[$kind])) {
                $options = $references[$kind] ?? $resolver->options($actor, $kind);
                $lists[$name] = array_values($options);
                $bindings[$kind] = array_keys($options);
            } else {
                $lists[$name] = array_values(__('product_import.employee_values.'.$kind, [], $locale));
            }
        }
        $data = $example ? $this->example($lists, $locale) : $data;
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Instructions')->setColumnWidth(110, 1);
            $writer->addRow($this->row([ProductImportEmployeeFormat::VERSION.'-'.$locale]));
            foreach (['employee_instructions', 'employee_name_rule', 'employee_image_help', 'employee_reference_help', 'employee_numeric_help'] as $instruction) {
                $writer->addRow($this->row([__('product_import.'.$instruction, [], $locale)]));
            }
            foreach ($instructions as $instruction) {
                $writer->addRow($this->row([$instruction]));
            }
            $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('008C74')->setShouldWrapText();
            foreach (ProductImportEmployeeFormat::COLUMNS as $sheet => $columns) {
                $writer->addNewSheetAndMakeItCurrent()->setName($sheet)->setColumnWidth(25, ...range(1, count($columns)));
                $writer->addRow($this->row(app(ProductImportEmployeeFormat::class)->headers($sheet, $locale), $header));
                foreach ($data[$sheet] ?? [] as $row) {
                    $writer->addRow($this->row(array_map(fn (string $field): string => (string) ($row[$field] ?? ''), $columns)));
                }
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('References')->setIsVisible(false);
            $writer->addRow($this->row([...array_keys(self::LISTS), ...array_map(fn (string $kind): string => $kind.'_binding', array_keys($bindings))], $header));
            $length = max([1, ...array_map('count', array_values($lists))]);
            for ($index = 0; $index < $length; $index++) {
                $writer->addRow($this->row([...array_values(array_map(fn (array $values): string => $values[$index] ?? '', $lists)), ...array_values(array_map(fn (array $values): string => $values[$index] ?? '', $bindings))]));
            }
        } finally {
            $writer->close();
        }
        $this->decorate($path, $lists, $locale);
    }

    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (string $value): StringCell => new StringCell($value, null), $values), $style);
    }

    /** Add bounded, standard OOXML list validation unsupported by the installed streaming writer. */
    private function decorate(string $path, array $lists, string $locale): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open generated template.');
        }
        try {
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/workbook.xml'), LIBXML_NONET);
            $namespace = $document->documentElement->namespaceURI;
            foreach (['definedNames', 'calcPr'] as $tag) {
                foreach (iterator_to_array($document->getElementsByTagName($tag)) as $existing) {
                    $existing->parentNode->removeChild($existing);
                }
            }
            $names = $document->createElementNS($namespace, 'definedNames');
            $formulas = ['ImportProducts' => self::productFormula()];
            foreach (array_keys(self::LISTS) as $index => $name) {
                $column = chr(65 + $index);
                $formulas[$name] = "'References'!$".$column.'$2:$'.$column.'$'.max(2, count($lists[$name]) + 1);
            }
            foreach ($formulas as $name => $formula) {
                $node = $document->createElementNS($namespace, 'definedName');
                $node->setAttribute('name', $name);
                $node->appendChild($document->createTextNode($formula));
                $names->appendChild($node);
            }
            $document->documentElement->appendChild($names);
            $calculation = $document->createElementNS($namespace, 'calcPr');
            $calculation->setAttribute('calcMode', 'auto');
            $calculation->setAttribute('fullCalcOnLoad', '1');
            $document->documentElement->appendChild($calculation);
            $zip->addFromString('xl/workbook.xml', $document->saveXML());
            foreach (array_keys(ProductImportEmployeeFormat::COLUMNS) as $index => $sheet) {
                $part = 'xl/worksheets/sheet'.($index + 2).'.xml';
                $document = new DOMDocument;
                $document->loadXML($zip->getFromName($part), LIBXML_NONET);
                $xpath = new DOMXPath($document);
                $view = $xpath->query('//*[local-name()="sheetView"]')->item(0);
                if (! $view instanceof DOMElement) {
                    $views = $document->createElementNS($namespace, 'sheetViews');
                    $view = $document->createElementNS($namespace, 'sheetView');
                    $view->setAttribute('workbookViewId', '0');
                    $views->appendChild($view);
                    $beforeViews = $xpath->query('/*/*[local-name()="sheetFormatPr" or local-name()="cols" or local-name()="sheetData"]')->item(0);
                    $document->documentElement->insertBefore($views, $beforeViews);
                }
                if ($view instanceof DOMElement) {
                    $view->setAttribute('rightToLeft', $locale === 'ar' ? '1' : '0');
                }
                $validations = $document->createElementNS($namespace, 'dataValidations');
                $validations->setAttribute('count', (string) count(self::VALIDATIONS[$sheet]));
                $limit = match ($sheet) {
                    'Products' => config('product-import.products'), 'Variants' => config('product-import.variants'), 'Images' => config('product-import.images'), default => config('product-import.rows')
                };
                foreach (self::VALIDATIONS[$sheet] as $column => $list) {
                    $validation = $document->createElementNS($namespace, 'dataValidation');
                    foreach (['type' => 'list', 'allowBlank' => '1', 'showErrorMessage' => '1', 'showInputMessage' => '1', 'errorStyle' => 'stop', 'errorTitle' => __('product_import.employee_choose', [], $locale), 'error' => __('product_import.employee_excel_error', [], $locale), 'sqref' => $column.'2:'.$column.($limit + 1)] as $attribute => $value) {
                        $validation->setAttribute($attribute, $value);
                    }
                    $validation->appendChild($document->createElementNS($namespace, 'formula1', $list));
                    $validations->appendChild($validation);
                }
                $before = null;
                foreach ($document->documentElement->childNodes as $node) {
                    if (in_array($node->localName, ['printOptions', 'pageMargins', 'pageSetup', 'headerFooter', 'drawing', 'legacyDrawing', 'legacyDrawingHF', 'extLst'], true)) {
                        $before = $node;
                        break;
                    }
                }
                $document->documentElement->insertBefore($validations, $before);
                $zip->addFromString($part, $document->saveXML());
            }
        } finally {
            $zip->close();
        }
    }

    private function example(array $lists, string $locale): array
    {
        $names = $locale === 'ar' ? ['نايكي إير ماكس', 'أديداس سامبا', 'بوما سويد'] : ['Nike Air Max', 'Adidas Samba', 'Puma Suede'];
        $labels = fn (string $group, string $value): string => __('product_import.employee_values.'.$group.'.'.$value, [], $locale);
        $data = [];
        foreach ($names as $index => $name) {
            $fixed = $index === 2;
            $data['Products'][] = ['name' => $name, 'brand' => $lists['ImportBrands'][$index] ?? $lists['ImportBrands'][0] ?? '', 'category' => $lists['ImportCategories'][0] ?? '', 'status' => $labels('status', 'draft'), 'pricing_mode' => $labels('pricing', $fixed ? '1' : '0'), 'shared_sale_price' => $fixed ? '1200' : ''];
            $variants = max(count($lists['ImportSizes']), count($lists['ImportColors'])) >= 2 ? 2 : 1;
            for ($variant = 0; $variant < $variants; $variant++) {
                $data['Variants'][] = ['product_name' => $name, 'color' => $lists['ImportColors'][min($variant, count($lists['ImportColors']) - 1)] ?? '', 'size' => $lists['ImportSizes'][min($variant, count($lists['ImportSizes']) - 1)] ?? '', 'sale_price' => $fixed ? '' : '1500', 'variant_status' => $labels('active', '1')];
            }
        }
        foreach (['ProductTaxes' => ['ImportTaxes', 'tax'], 'ProductDiscounts' => ['ImportDiscounts', 'discount']] as $sheet => [$list,$field]) {
            if (isset($lists[$list][0])) {
                $data[$sheet][] = ['product_name' => $names[0], $field => $lists[$list][0]];
            }
        }
        $first = $data['Variants'][0];
        $data['Images'] = [
            ['product_name' => $names[0], 'upload_name' => 'sample-cover.png', 'is_primary' => $labels('boolean', '1'), 'image_scope' => $labels('scope', 'product'), 'sort_order' => '0'],
            ['product_name' => $names[0], 'color' => $first['color'], 'size' => $first['size'], 'upload_name' => 'sample-variant.png', 'is_primary' => $labels('boolean', '1'), 'image_scope' => $labels('scope', 'variant'), 'sort_order' => '1'],
        ];

        return $data;
    }
}
