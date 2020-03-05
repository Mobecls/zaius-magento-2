<?php

namespace Zaius\Engage\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Paypal\Model\Pro;
use Magento\Store\Model\Store;
use Zaius\Engage\Logger\Logger;

class ProductScopeManager
{
    /**
     * @var TrackScopeManager
     */
    private $trackScopeManager;
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var ProductFactory
     */
    private $productFactory;

    public function __construct(
        TrackScopeManager $trackScopeManager,
        ProductRepositoryInterface $productRepository,
        ProductFactory $productFactory,
        Client $client,
        Logger $logger
    ) {
        $this->trackScopeManager = $trackScopeManager;
        $this->productRepository = $productRepository;
        $this->client = $client;
        $this->logger = $logger;
        $this->productFactory = $productFactory;
    }

    /**
     * @param Product $product
     */
    public function sync($product)
    {
        try {
            $trackingIds = $this->getStoreByTrackingId($product);
            $genericProductId = $product->getId();
            $baseProduct = $this->productFactory->create()->setStoreId(Store::DEFAULT_STORE_ID)->load($product->getId());
            foreach ($trackingIds as $trackingId => $storeIds) {
                if (sizeof($storeIds) > 1) {
                    foreach ($storeIds as $storeId) {
                        $scopeProduct = $this->productRepository->getById($product->getId(), false, $storeId);

                        if ($this->hasVariants($baseProduct, $scopeProduct)) {
                            $productId = $product->getId() . '-' . $this->trackScopeManager->getStoreCode($storeId);
                            $scopeProduct->setData('has_view_variants', true);
                            $scopeProduct->setId($productId);
                            $scopeProduct->setData('generic_product_id', $genericProductId);
                            $this->client->postProduct('catalog_product_save_after', $scopeProduct, $storeId);
                        }
                    }
                    //main product
                    $baseProduct->setData('has_view_variants', true);
                    $baseProduct->setData('generic_product_id', $baseProduct->getId());
                    $this->client->postProduct('catalog_product_save_after', $baseProduct, current($storeIds));
                    continue;
                }
                $this->client->postProduct('catalog_product_save_after', $baseProduct, current($storeIds));
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf("Error trying to load product %s", $e->getMessage()));
        }
    }

    /**
     * @param $product Product
     * @return array
     */
    private function getStoreByTrackingId($product)
    {
        $tracks = [];
        foreach ($product->getStoreIds() as $storeId) {
            $trackId = $this->trackScopeManager->getConfig($storeId);
            $tracks[$trackId][] = $storeId;
        }
        return $tracks;
    }

    /**
     * @param $productBase
     * @param $productStore
     * @return bool
     */
    private function hasVariants($productBase, $productStore)
    {
        $hasVariants = false;
        foreach ($productBase->getData() as $k => $value) {
            if ($k === 'store_id') {
                continue;
            }
            if ($productBase->getData($k) != $productStore->getData($k)) {
                return true;
            }
        }
        return $hasVariants;
    }
}
