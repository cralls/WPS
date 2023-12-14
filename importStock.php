<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/public_html/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);

$objectManager = $bootstrap->getObjectManager();
$state = $objectManager->get('Magento\Framework\App\State');
$state->setAreaCode('frontend');

error_log(date('Y-m-d H:i:s')." - ******* STARTING STEP 1 ******* \r\n", 3, '/home/happytrail/public_html/var/log/pu-inventory.log');
$startTime = date("Y-m-d H:i:s");

$conn = new mysqli('localhost', 'happytrail_m2', 'DyB*6s41X3xs', 'happytrail_m2');
if ($conn->connect_error) {
    die('Connect Error (' . $conn->connect_errno . ') '
            . $conn->connect_error);
}

$conn->query("TRUNCATE TABLE cataloginventory_stock_item_update");

$query = "LOAD DATA LOCAL INFILE '/home/happytrail/stockItems.csv' INTO TABLE cataloginventory_stock_item_update FIELDS TERMINATED BY ',' (sku, qty, status, is_in_stock, max_sale_qty)";
$conn->query($query);

$query = "UPDATE cataloginventory_stock_item_update AS csiu, catalog_product_entity AS cpe SET csiu.product_id = cpe.entity_id WHERE csiu.sku = cpe.sku";
//$query = "UPDATE cataloginventory_stock_item_update AS csiu, os_supplier_product AS osp SET csiu.product_id = osp.product_id WHERE osp.supplier_id = 69 and csiu.sku=osp.product_sku";
$conn->query($query);

// Update the rest with Manufacturer Sku
$manufactererSkusQuery = "SELECT entity_id, value from catalog_product_entity_varchar where attribute_id = 319";
$manufacturerSkusResult = $conn->query($manufactererSkusQuery);
$manufacturerSkus = [];
foreach($manufacturerSkusResult as $manufacturerSku) {
	$manufacturerSkus[$manufacturerSku['value']] = $manufacturerSku['entity_id'];
}
$stockSkusQuery = "SELECT sku from cataloginventory_stock_item_update where product_id = 0";
$stockSkusResult = $conn->query($stockSkusQuery);
foreach($stockSkusResult as $stockSku) {
    if(isset($manufacturerSkus[$stockSku['sku']])) {
        $query = "UPDATE cataloginventory_stock_item_update set product_id = ".$manufacturerSkus[$stockSku['sku']]." where sku = '".$stockSku['sku']."'";
		$conn->query($query);
	}
}

// Disable products that no longer exist in the inventory update
$query = "select distinct entity_id from catalog_product_entity_int where attribute_id = 275 and value = 6390 and entity_id not in (select product_id from cataloginventory_stock_item_update) and entity_id not in (select entity_id from catalog_product_entity where type_id = 'bundle') and entity_id not in (select entity_id from catalog_product_entity where type_id = 'configurable') and entity_id in (select entity_id from catalog_product_entity_int where attribute_id = 96 and value = 1) and entity_id > 30505";
$result = $conn->query($query);
$disabledProducts = '';
$amazonDisabledProducts = 'Need to Disable Amazon Products - ';
foreach($result as $entity_id) {
    $disabledSku = $conn->query("SELECT sku from catalog_product_entity where entity_id = ".$entity_id['entity_id'])->fetch_assoc();
    $disabledProducts .= "[".$disabledSku['sku']."] ";
    $conn->query("update catalog_product_entity_int set value = 2 where attribute_id = 96 and entity_id = ".$entity_id['entity_id']);
    
    // Check if enabled on Amazon
    $query = "SELECT sku FROM `m2epro_amazon_listing_product` where online_qty > 0 and sku in (SELECT sku from catalog_product_entity where entity_id = ".$entity_id['entity_id'].")";
    $amazonProduct = $conn->query($query);
    foreach($amazonProduct as $product) {
        $amazonDisabledProducts .= "[".$product['sku']."] ";
    }
}

// Send mail
$transportBuilder = $objectManager->create('Magento\Framework\Mail\Template\TransportBuilder');
$storeManagerInterface = $objectManager->create('Magento\Store\Model\StoreManagerInterface');

$templateId = '12';
$fromEmail = 'support@happy-trail.com';
$fromName = 'Happy Trails';
$toEmail = 'casey.ralls@happy-trail.com';

try {
    // template variables pass here
    $templateVars = [
        'msg' => $disabledProducts."\r\n\r\n".$amazonDisabledProducts
    ];
    
    $storeId = $storeManagerInterface->getStore()->getId();
    
    $from = ['email' => $fromEmail, 'name' => $fromName];
    //$inlineTranslation->suspend();
    
    $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
    $templateOptions = [
        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
        'store' => $storeId
    ];
    $transport = $transportBuilder->setTemplateIdentifier($templateId, $storeScope)
    ->setTemplateOptions($templateOptions)
    ->setTemplateVars($templateVars)
    ->setFrom($from)
    ->addTo($toEmail)
    ->getTransport();
    $transport->sendMessage();
    //$inlineTranslation->resume();
} catch (\Exception $e) {
    echo $e->getMessage();
}

// Update cataloginventory_stock_item with updated stock
$query = "UPDATE cataloginventory_stock_item AS csi, cataloginventory_stock_item_update AS csiu SET csi.qty = csiu.qty, csi.total_qty = csiu.qty, csi.is_in_stock = csiu.is_in_stock, csi.max_sale_qty = csiu.max_sale_qty WHERE csi.product_id = csiu.product_id and csi.stock_id = 3";
$conn->query($query);

// Update stock status for PU Parts to match csiu
$query = "UPDATE cataloginventory_stock_item as csi left join catalog_product_entity_int as cpei on csi.product_id=cpei.entity_id left join cataloginventory_stock_item_update as csiu on csi.product_id=csiu.product_id set csi.is_in_stock=csiu.is_in_stock where cpei.attribute_id = 275 and cpei.value = 6390 and csi.stock_id = 1 and csi.is_in_stock != csiu.is_in_stock";
$conn->query($query);

// Add Total Quantities between all warehouses and update default qty and set is_in_stock if sum qty is greater than 0
$query = "UPDATE cataloginventory_stock_item as csi left join (SELECT product_id, SUM(qty) as sum_qty from cataloginventory_stock_item where stock_id != 1 group by product_id) as csi2 on csi.product_id = csi2.product_id set csi.qty = csi2.sum_qty, csi.max_sale_qty = IF(csi2.sum_qty > 0, csi2.sum_qty, 1) where stock_id = 1";
$conn->query($query);

// Udate is_in_stock if default qty in stock_id = 1 is > 0
//$query = "UPDATE cataloginventory_stock_item as csi set csi.is_in_stock = 1 where csi.qty > 0 and stock_id = 1";
$query = "UPDATE cataloginventory_stock_item as csi left join catalog_product_entity_int as cpei on csi.product_id = cpei.entity_id set csi.is_in_stock = 1 where csi.qty > 0 and stock_id = 1 AND csi.is_in_stock = 0 and cpei.attribute_id = 96 and cpei.value = 1 and csi.product_id > 21435";
$conn->query($query);

// Re-enable products with qty > 0 & disable inactive products
$result = $conn->query("select * from cataloginventory_stock_item_update");
$reEnabled = '';
foreach($result as $stockItem) {
    if($stockItem['is_in_stock'] == 1) {
        // Check if out of stock first before setting in stock
        $currentStatusResult = $conn->query("select * from catalog_product_entity_int where entity_id = ".$stockItem['product_id']." and attribute_id = 96 and store_id = 0");
        $currentStatus = $currentStatusResult->fetch_row()[4];
        
        $priceResult = $conn->query("select * from catalog_product_entity_decimal where entity_id = ".$stockItem['product_id']." and attribute_id = 75");
        $price = $priceResult->fetch_row()[4];
        
        $costResult = $conn->query("select * from catalog_product_entity_decimal where entity_id = ".$stockItem['product_id']." and attribute_id = 79");
        $cost = $costResult->fetch_row()[4];
        
        if($price < $cost) {
            if($currentStatus == 1) {
                error_log(date('Y-m-d H:i:s')." - Disable for ".$stockItem['product_id']." because price $price is less than cost $cost\r\n", 3, '/home/happytrail/public_html/var/log/pu-inventory.log');
                $conn->query("update catalog_product_entity_int set value = 2 where entity_id = ".$stockItem['product_id']." and attribute_id = 96");
            }
        } elseif($currentStatus != 1) {
            // Only re-enable if product has images && cost is not greater than price
            $imageResult = $conn->query("select * from catalog_product_entity_media_gallery_value_to_entity where entity_id = ".$stockItem['product_id']);
            if($imageResult->num_rows > 0) {
                error_log(date('Y-m-d H:i:s')." - Enable for ".$stockItem['product_id']."\n", 3, '/home/happytrail/public_html/var/log/pu-inventory.log');
                $reEnabled .= "[".$stockItem['sku']."] ";
                $conn->query("update catalog_product_entity_int set value = 1 where entity_id = ".$stockItem['product_id']." and attribute_id = 96");
                //$conn->query("update cataloginventory_stock_item set is_in_stock = 1 where product_id = ".$stockItem['product_id']." where stock_id = 3");
            }
        }
    }
    
    // Disable inactive products if they aren't already
    if($stockItem['status'] == 2) {
        $currentStatusResult = $conn->query("select * from catalog_product_entity_int where entity_id = ".$stockItem['product_id']." and attribute_id = 96");
        $currentStatus = $currentStatusResult->fetch_row()[4];
        if($stockItem['product_id'] > 0 && $currentStatus == 1) {
            error_log(date('Y-m-d H:i:s')." - Disable for ".$stockItem['product_id']." because it is inactive \r\n", 3, '/home/happytrail/public_html/var/log/pu-inventory.log');
            $conn->query("update catalog_product_entity_int set value = 2 where entity_id = ".$stockItem['product_id']." and attribute_id = 96");
            //$thisProduct = $objectManager->create('Magento\Catalog\Model\Product')->load($stockItem['product_id']);
            //$thisProduct->save(); // We only do this to update the part on Amazon but it's time consuming and heavy
        }
    }
}

if($reEnabled != '') {
    //mail('cralls@vectorns.com', 'Re-Enabled Products', $reEnabled);
    // this is an example and you can change template id,fromEmail,toEmail,etc as per your need.
    $templateId = '13'; // template id
    $fromEmail = 'support@happy-trail.com';  // sender Email id
    $fromName = 'Happy Trails';             // sender Name
    $toEmail = 'casey.ralls@happy-trail.com'; // receiver email id
    
    try {
        // template variables pass here
        $templateVars = [
            'msg' => $reEnabled
        ];
        
        $storeId = $storeManagerInterface->getStore()->getId();
        
        $from = ['email' => $fromEmail, 'name' => $fromName];
        //$inlineTranslation->suspend();
        
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $templateOptions = [
            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
            'store' => $storeId
        ];
        $transport = $transportBuilder->setTemplateIdentifier($templateId, $storeScope)
        ->setTemplateOptions($templateOptions)
        ->setTemplateVars($templateVars)
        ->setFrom($from)
        ->addTo($toEmail)
        ->getTransport();
        $transport->sendMessage();
        //$inlineTranslation->resume();
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
}

$conn->close();

$minutes = number_format((strtotime(date("Y-m-d H:i:s")) - strtotime($startTime)) / 60, 1);
error_log(date("Y-m-d H:i:s")." - ***** FINISHED STEP 1 IN $minutes minutes *****\r\n", 3, '/home/happytrail/public_html/var/log/pu-inventory.log');

