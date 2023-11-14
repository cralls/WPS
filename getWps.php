<?php

use Magento\Framework\App\Bootstrap;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;

require __DIR__ . '/../app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();
$objectManager->get('Magento\Framework\App\State')->setAreaCode('adminhtml');

$storeManager = $objectManager->get(StoreManagerInterface::class);
$categoryFactory = $objectManager->get(CategoryFactory::class);
$categoryRepository = $objectManager->get(CategoryRepository::class);
$categoryCollectionFactory = $objectManager->get(CategoryCollectionFactory::class);

function categoryExists($categoryName, $categoryCollectionFactory) {
    $collection = $categoryCollectionFactory->create();
    $collection->addAttributeToFilter('name', strtoupper($categoryName));
    return $collection->getFirstItem()->getId();
}

function createCategory($categoryName, $categoryFactory, $categoryRepository) {
    // ID of the "All Brands" category
    $parentId = 7;
    
    // Load the "All Brands" category to get its data, especially the path
    $parentCategory = $categoryRepository->get($parentId);
    
    $category = $categoryFactory->create();
    $category->setName(ucwords(strtolower($categoryName)));
    $category->setIsActive(true);
    $category->setParentId($parentId); // Set the parent ID to "All Brands" ID
    
    // The new category path is the parent category path plus the parent category ID
    $category->setPath($parentCategory->getPath() . '/' . $parentCategory->getId());
    
    // Save the category and automatically generate the correct path
    $categoryRepository->save($category);
    
    echo "Created category: " . $category->getName() . " under 'All Brands'" . PHP_EOL;
}

// The getData function performs the API request and returns the JSON response.
function getData($url, $apiToken) {
    // Initialize cURL session.
    $curl = curl_init($url);
    
    // Set the Authorization header with the Bearer token.
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $apiToken));
    
    // Return the transfer as a string.
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    
    // Execute the cURL session.
    $result = curl_exec($curl);
    
    // Close cURL session.
    curl_close($curl);
    
    // Return the decoded JSON response.
    return json_decode($result, true);
}

// The getBrands function constructs the URL and calls getData.
function getBrands($apiToken, $brandId = null) {
    $allBrands = []; // Array to hold all brands
    $base_url = 'https://api.wps-inc.com/brands';
    $cursor = null; // Start with no cursor
    
    do {
        $url = $base_url;
        if ($brandId) {
            $url .= '/' . $brandId;
        }
        
        // If there is a cursor, add it as a query parameter
        if ($cursor) {
            $url .= '?page[cursor]=' . $cursor;
        }
        
        $response = getData($url, $apiToken);
        $allBrands = array_merge($allBrands, $response['data']); // Merge the current page brands with the allBrands array
        
        // Check if there's a next cursor and update the cursor variable
        $cursor = isset($response['meta']['cursor']['next']) ? $response['meta']['cursor']['next'] : null;
    } while ($cursor); // Continue until there's no next cursor
    
    return $allBrands;
}

function getItems($brandId, $apiToken, $objectManager) {
    $allItems = [];
    $base_url = "https://api.wps-inc.com/brands/{$brandId}/items";
    
    $cursor = null; // Start with no cursor
    
    do {
        $url = $base_url;
        
        // If there is a cursor, add it as a query parameter
        if ($cursor) {
            $url .= '?page[cursor]=' . $cursor;
        }
        
        $response = getData($url, $apiToken);
        
        foreach($response['data'] as $item) {
            $magentoProduct = $objectManager->create('Magento\Catalog\Model\Product');
            
            $attributeResponses = getData("https://api.wps-inc.com/items/{$item['id']}/attributevalues", $apiToken);
            foreach($attributeResponses['data'] as $attributeResponse) {
                // 13 = Size
                // 15 = Color
                if($attributeResponse['attribute_id'] == 13) $magentoProduct->setSize($attributeResponse['name']);
                if($attributeResponse['attribute_id'] == 15) $magentoProduct->setColor($attributeResponse['name']);
            }
            
            // Set the attributes based on your mapping
            $magentoProduct->setSku($item['sku']);
            $magentoProduct->setData('manufacturer_sku', $item['supplier_product_id']);
            $magentoProduct->setName($item['name']);
            $magentoProduct->setPrice($item['list_price']);
            $magentoProduct->setData('cost', $item['standard_dealer_price']);
            $magentoProduct->setData('length', $item['length']);
            $magentoProduct->setData('width', $item['width']);
            $magentoProduct->setData('height', $item['height']);
            $magentoProduct->setWeight($item['weight']);
            $magentoProduct->setData('upc_ean', $item['upc']);
            $magentoProduct->setData('wps_status', $item['status']);
            $magentoProduct->setData('map_price', $item['map_price']);
            
            // Save the product
            $magentoProduct->save();
            
            // Stop the script after creating the first product
            die("First product created. Check Magento admin.");
        }
        $allItems = array_merge($allItems, $response['data']); 
        
        // Check if there's a next cursor and update the cursor variable
        $cursor = isset($response['meta']['cursor']['next']) ? $response['meta']['cursor']['next'] : null;
    } while ($cursor); // Continue until there's no next cursor
    
    return $allItems;
}

$apiToken = getenv('WPS_API_KEY');

// Main script execution.
if (in_array('getBrands', $argv)) {
    $allBrands = getBrands($apiToken);
    
    foreach ($allBrands as $brand) {
        if (!categoryExists($brand['name'], $categoryCollectionFactory)) {
            createCategory($brand['name'], $categoryFactory, $categoryRepository, $storeManager);
        }
    }
}

if (in_array('getItems', $argv)) {
    //$allBrands = getBrands($apiToken);
    
    //foreach($allBrands as $brand) {
        $items = getItems(8, $apiToken, $objectManager);
        //$items = getItems($brand['id'], $apiToken, $objectManager);
        sleep(5);
    //}
}
