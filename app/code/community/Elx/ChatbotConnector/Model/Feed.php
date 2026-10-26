<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Feed
{
    private const BATCH_SIZE = 100;

    private const PRODUCTS_PER_FILE = 1000;

    public function generate(): void
    {
        /** @var Elx_ChatbotConnector_Helper_Data $helper */
        $helper = Mage::helper('elx_chatbotconnector');
        $store = $helper->getStore();
        $previousStore = Mage::app()->getStore();
        Mage::app()->setCurrentStore($store);

        try {
            $products = $this->collectProducts($store);
        } finally {
            Mage::app()->setCurrentStore($previousStore);
        }

        $this->publish($helper, $store, $products);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    private function publish(
        Elx_ChatbotConnector_Helper_Data $helper,
        Mage_Core_Model_Store $store,
        array $products,
    ): void {
        $feedFile = $helper->getFeedFilePath();
        $directory = dirname($feedFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the product feed directory.');
        }

        $chunks = $products === [] ? [] : array_chunk($products, self::PRODUCTS_PER_FILE);
        $origin = rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/');
        $pages = [];
        foreach ($chunks as $index => $chunk) {
            $page = $index + 1;
            $filename = 'feed-' . $page . '.json';
            $this->writeJson($directory . '/' . $filename, $chunk);
            $pages[] = [
                'url' => $origin . '/feeds/' . $filename,
                'products' => count($chunk),
            ];
        }

        $this->deleteStalePages($directory, count($chunks));

        $host = parse_url($origin, PHP_URL_HOST);
        $this->writeJson($feedFile, [
            'feed_name' => 'Product Feed - ' . (is_string($host) && $host !== '' ? $host : (string) $store->getName()),
            'products_per_file' => self::PRODUCTS_PER_FILE,
            'total_products' => count($products),
            'total_files' => count($chunks),
            'generated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP'),
            'feeds' => $pages,
        ]);
    }

    private function writeJson(string $path, mixed $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Could not encode the product feed.');
        }

        $tempFile = dirname($path) . '/feed.' . getmypid() . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tempFile, $json . "\n") === false) {
            throw new RuntimeException('Could not write the product feed.');
        }

        if (is_file($path)) {
            unlink($path);
        }
        if (!rename($tempFile, $path)) {
            if (!copy($tempFile, $path)) {
                throw new RuntimeException('Could not publish the product feed.');
            }
            unlink($tempFile);
        }
    }

    private function deleteStalePages(string $directory, int $pageCount): void
    {
        $files = glob($directory . '/feed-*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (preg_match('/feed-(\d+)\.json$/', $file, $match) !== 1) {
                continue;
            }
            if ((int) $match[1] > $pageCount) {
                unlink($file);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectProducts(Mage_Core_Model_Store $store): array
    {
        $categories = $this->categoryNames();
        $mediaBase = rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA), '/');
        $currency = (string) $store->getCurrentCurrencyCode();

        $collection = Mage::getModel('catalog/product')->getCollection();
        $collection->setStoreId((int) $store->getId());
        $collection->addStoreFilter($store);
        $collection->addAttributeToSelect($this->attributeCodes());
        $collection->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
        $collection->addUrlRewrite();
        $collection->setPageSize(self::BATCH_SIZE);

        $products = [];
        $lastPage = max(1, (int) $collection->getLastPageNumber());
        for ($page = 1; $page <= $lastPage; $page++) {
            $collection->setCurPage($page);
            $collection->load();
            $stock = $this->stockByProductId($this->idsOf($collection));

            foreach ($collection as $product) {
                if (!$product instanceof Mage_Catalog_Model_Product) {
                    continue;
                }
                $product->setStoreId((int) $store->getId());
                $products[] = $this->buildProduct($product, $mediaBase, $currency, $categories, $stock, $store);
            }

            $collection->clear();
        }

        return $products;
    }

    /**
     * @return list<string>
     */
    private function attributeCodes(): array
    {
        $codes = [
            'name',
            'sku',
            'description',
            'image',
            'price',
            'special_price',
            'status',
            'visibility',
            'url_key',
            'weight',
        ];
        $manufacturer = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'manufacturer');
        if ($manufacturer && $manufacturer->getId()) {
            $codes[] = 'manufacturer';
        }

        return $codes;
    }

    /**
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @return list<int>
     */
    private function idsOf(Mage_Catalog_Model_Resource_Product_Collection $collection): array
    {
        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }

        return $ids;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array{qty: float, in_stock: bool}>
     */
    private function stockByProductId(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $collection = Mage::getModel('cataloginventory/stock_item')->getCollection();
        $collection->addFieldToFilter('product_id', ['in' => $productIds]);

        $stock = [];
        foreach ($collection as $item) {
            $stock[(int) $item->getProductId()] = [
                'qty' => (float) $item->getQty(),
                'in_stock' => (int) $item->getIsInStock() === 1,
            ];
        }

        return $stock;
    }

    /**
     * @return array<int, string>
     */
    private function categoryNames(): array
    {
        $names = [];
        $collection = Mage::getModel('catalog/category')->getCollection();
        $collection->addAttributeToSelect('name');
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
        }

        return $names;
    }

    /**
     * @param array<int, string> $categories
     * @param array<int, array{qty: float, in_stock: bool}> $stock
     * @return array<string, mixed>
     */
    private function buildProduct(
        Mage_Catalog_Model_Product $product,
        string $mediaBase,
        string $currency,
        array $categories,
        array $stock,
        Mage_Core_Model_Store $store,
    ): array {
        $parent = $this->visibleParent($product);
        [$price, $specialPrice] = $this->pricesFor($product, $parent);
        $collectionNames = [];
        foreach ($product->getCategoryIds() as $categoryId) {
            $name = $categories[(int) $categoryId] ?? '';
            if ($name !== '') {
                $collectionNames[] = $name;
            }
        }
        $collectionNames = array_values(array_unique($collectionNames));
        $stockRow = $stock[(int) $product->getId()] ?? ['qty' => 0.0, 'in_stock' => false];
        $vendor = '';
        if ($product->getResource()->getAttribute('manufacturer')) {
            $vendor = (string) $product->getAttributeText('manufacturer');
        }
        if ($vendor === '') {
            $vendor = (string) $store->getName();
        }

        $variants = $this->variants($product, $mediaBase, $currency, $stock);
        $stockQty = (int) $stockRow['qty'];
        if ($variants !== []) {
            $stockQty = 0;
            foreach ($variants as $variant) {
                $stockQty += (int) ($variant['stock'] ?? 0);
            }
        }

        return [
            'id' => (string) $product->getId(),
            'name' => (string) $product->getName(),
            'description' => trim(strip_tags((string) $product->getDescription())),
            'sku' => (string) $product->getSku(),
            'status' => (int) $product->getStatus() === Mage_Catalog_Model_Product_Status::STATUS_ENABLED
                ? 'ACTIVE'
                : 'INACTIVE',
            'price' => $this->priceValue($price, $specialPrice, $variants),
            'prevPrice' => $this->previousPrice($price, $specialPrice),
            'currency' => $currency,
            'giftcard' => false,
            'image' => $this->imageUrl($mediaBase, (string) $product->getImage()),
            'tags' => [],
            'vendor' => $vendor,
            'productHandle' => (string) $product->getUrlKey(),
            'parentId' => (string) $product->getId(),
            'parentName' => (string) $product->getName(),
            'collections' => $collectionNames,
            'category' => $collectionNames[0] ?? '',
            'stock' => $stockQty,
            'available' => $product->isAvailable(),
            'updatedAt' => $this->formatUpdatedAt((string) $product->getUpdatedAt()),
            'url' => $parent instanceof Mage_Catalog_Model_Product
                ? (string) $parent->getProductUrl()
                : (string) $product->getProductUrl(),
            'type' => (string) $product->getTypeId(),
            'variantOptions' => $this->variantOptions($product),
            'variants' => $variants,
        ];
    }

    /**
     * @param array<int, array{qty: float, in_stock: bool}> $stock
     * @return list<array<string, mixed>>
     */
    private function variants(
        Mage_Catalog_Model_Product $product,
        string $mediaBase,
        string $currency,
        array $stock,
    ): array {
        if ($product->getTypeId() !== Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {
            return [];
        }

        $type = $product->getTypeInstance();
        if (!$type instanceof Mage_Catalog_Model_Product_Type_Configurable) {
            return [];
        }

        $variants = [];
        foreach ($type->getUsedProducts(null, $product) as $child) {
            if (!$child instanceof Mage_Catalog_Model_Product) {
                continue;
            }
            $child->setStoreId((int) $product->getStoreId());
            [$childPrice, $specialPrice] = $this->pricesFor($child, $product);
            $childStock = $stock[(int) $child->getId()] ?? null;
            if ($childStock === null) {
                $item = Mage::getModel('cataloginventory/stock_item')->loadByProduct($child);
                $childStock = [
                    'qty' => (float) $item->getQty(),
                    'in_stock' => (int) $item->getIsInStock() === 1,
                ];
            }

            $variants[] = [
                'id' => (string) $child->getId(),
                'name' => (string) $child->getName(),
                'sku' => (string) $child->getSku(),
                'price' => $this->formatPrice($childPrice, $specialPrice),
                'prevPrice' => $this->previousPrice($childPrice, $specialPrice),
                'stock' => (int) $childStock['qty'],
                'available' => $child->isAvailable(),
                'url' => (string) $product->getProductUrl(),
                'image' => $this->imageUrl($mediaBase, (string) $child->getImage()),
                'options' => $this->variantOptionValues($child, $product, $type),
                'allPrices' => $this->formatPrice($childPrice, $specialPrice) . ' ' . $currency,
                'updatedAt' => $this->formatUpdatedAt((string) $child->getUpdatedAt()),
            ];
        }

        return $variants;
    }

    /**
     * @return array<string, list<string>>
     */
    private function variantOptions(Mage_Catalog_Model_Product $product): array
    {
        if ($product->getTypeId() !== Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {
            return [];
        }

        $type = $product->getTypeInstance();
        if (!$type instanceof Mage_Catalog_Model_Product_Type_Configurable) {
            return [];
        }

        $options = [];
        $children = $type->getUsedProducts(null, $product);
        foreach ($type->getConfigurableAttributes($product) as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            if (!$productAttribute) {
                continue;
            }
            $code = (string) $productAttribute->getAttributeCode();
            $label = (string) $productAttribute->getStoreLabel();
            if ($label === '') {
                $label = (string) $productAttribute->getFrontendLabel();
            }
            $values = [];
            foreach ($children as $child) {
                $value = $child->getAttributeText($code) ?: $child->getData($code);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                if ($value) {
                    $values[] = (string) $value;
                }
            }
            if ($values !== []) {
                $options[$label] = array_values(array_unique($values));
            }
        }

        return $options;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function variantOptionValues(
        Mage_Catalog_Model_Product $child,
        Mage_Catalog_Model_Product $parent,
        Mage_Catalog_Model_Product_Type_Configurable $type,
    ): array {
        $options = [];
        foreach ($type->getConfigurableAttributes($parent) as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            if (!$productAttribute) {
                continue;
            }
            $code = (string) $productAttribute->getAttributeCode();
            $label = (string) $productAttribute->getStoreLabel();
            if ($label === '') {
                $label = (string) $productAttribute->getFrontendLabel();
            }
            $value = $child->getAttributeText($code) ?: $child->getData($code);
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            if ($value) {
                $options[] = [
                    'name' => $label,
                    'value' => (string) $value,
                ];
            }
        }

        return $options;
    }

    private function imageUrl(string $mediaBase, string $image): string
    {
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        return $mediaBase . '/catalog/product' . $image;
    }

    /**
     * Associated simples are not sold on their own. A missing price uses the parent.
     *
     * @return array{0: float, 1: float}
     */
    private function pricesFor(Mage_Catalog_Model_Product $product, ?Mage_Catalog_Model_Product $parent = null): array
    {
        $price = (float) $product->getPrice();
        $specialPrice = (float) $product->getData('special_price');
        if ($this->effectivePrice($price, $specialPrice) > 0 || !$parent instanceof Mage_Catalog_Model_Product) {
            return [$price, $specialPrice];
        }

        return [(float) $parent->getPrice(), (float) $parent->getData('special_price')];
    }

    private function effectivePrice(float $price, float $specialPrice): float
    {
        return ($specialPrice > 0 && $specialPrice < $price) ? $specialPrice : $price;
    }

    private function visibleParent(Mage_Catalog_Model_Product $product): ?Mage_Catalog_Model_Product
    {
        if ((int) $product->getVisibility() !== Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }

        $parentIds = Mage::getSingleton('catalog/product_type_configurable')->getParentIdsByChild((int) $product->getId());
        $parentId = (int) ($parentIds[0] ?? 0);
        if ($parentId === 0) {
            return null;
        }

        $parent = Mage::getModel('catalog/product')
            ->setStoreId((int) $product->getStoreId())
            ->load($parentId);

        return $parent->getId() ? $parent : null;
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function priceValue(float $price, float $specialPrice, array $variants): string
    {
        $amounts = [];
        foreach ($variants as $variant) {
            $amount = (float) ($variant['price'] ?? 0);
            if ($amount > 0) {
                $amounts[] = $amount;
            }
        }

        $amounts = array_values(array_unique($amounts));
        if ($amounts === []) {
            return $this->formatPrice($price, $specialPrice);
        }

        sort($amounts, SORT_NUMERIC);
        $low = number_format($amounts[0], 2, '.', '');
        $high = number_format($amounts[count($amounts) - 1], 2, '.', '');

        return $low === $high ? $low : $low . '-' . $high;
    }

    private function formatPrice(float $price, float $specialPrice): string
    {
        $effective = ($specialPrice > 0 && $specialPrice < $price) ? $specialPrice : $price;

        return number_format($effective, 2, '.', '');
    }

    private function previousPrice(float $price, float $specialPrice): string
    {
        if ($specialPrice > 0 && $specialPrice < $price) {
            return number_format($price, 2, '.', '');
        }

        return '0';
    }

    private function formatUpdatedAt(string $updatedAt): string
    {
        if ($updatedAt === '') {
            return '';
        }

        try {
            return (new DateTime($updatedAt))->format('Y-m-d\TH:i:s\Z');
        } catch (Exception) {
            return $updatedAt;
        }
    }
}
