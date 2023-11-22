<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/public_html/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$state = $objectManager->get('Magento\Framework\App\State');
$state->setAreaCode('frontend');

$productCollectionFactory = $objectManager->create('Magento\Catalog\Model\ResourceModel\Product\CollectionFactory');
$productRepository = $objectManager->create('Magento\Catalog\Api\ProductRepositoryInterface');
$productResource = $objectManager->get('Magento\Catalog\Model\ResourceModel\Product');
$eavConfig = $objectManager->get('Magento\Eav\Model\Config');

// Check if manufacturer argument is provided
if (!isset($argv[1])) {
    die("Please provide a manufacturer name as an argument.\n");
}
$manufacturerName = $argv[1];

// Fetch the manufacturer option ID based on the provided name
$manufacturerAttr = $eavConfig->getAttribute('catalog_product', 'manufacturer');
$manufacturerOptionId = null;
foreach ($manufacturerAttr->getSource()->getAllOptions() as $option) {
    if ($option['label'] == $manufacturerName) {
        $manufacturerOptionId = $option['value'];
        break;
    }
}

if (!$manufacturerOptionId) {
    die("Could not find a manufacturer with the name: $manufacturerName.\n");
}

$csvFile = 'map_prices_seizmik2.csv';
if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile");
}

$rows = array_map('str_getcsv', file($csvFile));

$brandProductsCollection = $productCollectionFactory->create();
$brandProductsCollection->addAttributeToSelect(['entity_id', 'sku', 'manufacturer_sku', 'manufacturer'])
->addFieldToFilter('manufacturer', $manufacturerOptionId);
$brandProducts = [];
foreach($brandProductsCollection as $brandProductCollection) {
    $brandProducts[$brandProductCollection->getManufacturerSku()] = $brandProductCollection;
}

$counter = 0;
foreach ($rows as $row) {
    if(!isset($brandProducts[trim($row[0])])) continue;
    //$manufacturerSku = trim($row[0]);
    $mapPriceValue = floatval(trim($row[1]));
    $mapPrice = number_format($mapPriceValue, 2);
    
    /*$productCollection = $productCollectionFactory->create();
    $productCollection->addAttributeToSelect(['entity_id', 'sku', 'manufacturer'])
    ->addFieldToFilter('manufacturer_sku', $manufacturerSku)
    ->addFieldToFilter('manufacturer', $manufacturerOptionId)  // Filtering by manufacturer option ID
    ->setPageSize(1);
    
    $product = $productCollection->getFirstItem();*/
    $product = $brandProducts[trim($row[0])];
    if ($product->getId()) {
        $repoProduct = $productRepository->get($product->getSku());
        $repoProduct->setMapPrice($mapPrice);
        $productResource->saveAttribute($repoProduct, 'map_price');
        $counter++;
        
        echo "$counter: Updated SKU: " . $repoProduct->getSku() . " with Map Price: $" . $mapPrice . "\n";
    }
}

echo "Updated map_price for {$counter} products based on the CSV.\n";
?>
