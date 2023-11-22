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

// Get manufacturer option ID by label (CLI argument)
$manufacturerName = isset($argv[1]) ? $argv[1] : null;
$manufacturerOptionId = null;
if ($manufacturerName) {
    $attribute = $eavConfig->getAttribute('catalog_product', 'manufacturer');
    $manufacturerOptionId = $attribute->getSource()->getOptionId($manufacturerName);
}

$collection = $productCollectionFactory->create();
$collection->addAttributeToSelect(['price', 'cost', 'special_price', 'map_price', 'accounting_category', 'status', 'conversion_cost']);

// Filter by accounting_category
$collection->addFieldToFilter('accounting_category', ['in' => ['6390', '6391','7847',329, 7594, 7592, 6392]]);
//$collection->addFieldToFilter('accounting_category', ['in' => [329, 7594, 7592, 6392]]);
//$collection->addFieldToFilter('sku', '9501-0259');

// Add manufacturer filter if manufacturer name was passed
if ($manufacturerOptionId) {
    $collection->addAttributeToFilter('manufacturer', $manufacturerOptionId);
}

$totalProducts = $collection->getSize();
$counter = 0;

foreach ($collection as $product) {
    //if($counter < 154000) { $counter++; continue; }
    $price = $product->getData('price');
    $cost = $product->getData('cost');
    $mapPrice = $product->getData('map_price');
    $specialPrice = null;
	$conversionCost = $product->getConversionCost() > 0 ? $product->getConversionCost() : null;

    // Logic for setting special_price
	if(!in_array($product->getAccountingCategory(), [329, 7594, 7592, 6392])) {  // Don't update special_price for PAM, KLIM, WPS and GARMIN
		if ($mapPrice > 0 && $mapPrice < $price) {
        	$specialPrice = $mapPrice;
    	} elseif($mapPrice > 0) {
        	$specialPrice = null;
    	} else {
			$calc1 = ($cost / (1 - 0.192)) + $conversionCost;
        	$calc2 = $cost / (1 - 0.2835);
        	$calc3 = $cost / (1 - 0.192);

        	if ($calc1 <= $price && $conversionCost > 0) {
            	$specialPrice = $calc1;
        	} elseif ($calc2 <= $price) {
            	$specialPrice = $calc2;
        	} elseif ($calc3 <= $price) {
				$specialPrice = $calc3;
			}
   		}
	}
    
    $repoProduct = $productRepository->get($product->getSku());
    
    // Logic to update or clear special_price
    if ($specialPrice !== null && $specialPrice > 0) {
        $specialPrice = number_format($specialPrice, 2, '.', '');
        $repoProduct->setSpecialPrice($specialPrice);
    } else {
        $repoProduct->setSpecialPrice(null); // Clear the special_price if it's null
        //$repoProduct->save();
    }
    
    $productResource->saveAttribute($repoProduct, 'special_price');
    
    // Logic to calculate and update profit margin
    $profitMargin = null;
    if ($specialPrice && $specialPrice > 0) {
        $profitMargin = number_format((($specialPrice - $cost) / $specialPrice) * 100, 2);
    } elseif ($cost && $price && $cost > 0 && $price > 0) {
        $profitMargin = number_format((($price - $cost) / $price) * 100, 2);
    }
    
    //echo "[".date('Y-m-d H:i:s')."] - Update [SKU: ".$product->getSku()."] Price: $price - Cost: ".$cost." - Special Price: $specialPrice - Map Price: $mapPrice - Profit Margin: $profitMargin - Conversion Cost = $conversionCost \r\n";
    
    $repoProduct->setProfitMargin($profitMargin);
    $productResource->saveAttribute($repoProduct, 'profit_margin');

    $counter++;

    // Echo progress every 1000 products
    if ($counter % 1000 == 0) {
        echo "Processed {$counter} products out of {$totalProducts} so far...\n";
        echo "[".date('Y-m-d H:i:s')."] - Update [SKU: ".$product->getSku()."] Price: $price - Cost: ".$cost." - Special Price: $specialPrice - Map Price: $mapPrice - Profit Margin: $profitMargin - Conversion Cost = $conversionCost \r\n";
    }
}

echo "Finished processing {$counter} out of {$totalProducts} products!";
?>

