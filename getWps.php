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

function getItems($brand, $apiToken, $objectManager) {
    //$allItems = [];
    $base_url = "https://api.wps-inc.com/brands/{$brand['id']}/items";
    
    $productRepository = $objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface');
    
    
    $cursor = null; // Start with no cursor
    
    do {
        $url = $base_url;
        
        // If there is a cursor, add it as a query parameter
        if ($cursor) {
            $url .= '?page[cursor]=' . $cursor;
        }
        
        $response = getData($url, $apiToken);
        
        foreach($response['data'] as $item) {
            
            try {
                $existingProduct = $productRepository->get($item['sku']);
                // If the product exists, skip to the next item
                continue;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // If the product does not exist, continue with creation
            }
            
            // Check if Item is Available
            if(in_array($item['status'], ['NLA'])) continue;
            
            $magentoProduct = $objectManager->create('Magento\Catalog\Model\Product');
            
            /************************
             * Get product data for description etc..
             ************************/
            $wpsProductData = getData("https://api.wps-inc.com/items/{$item['id']}/product", $apiToken);
            
            /************************
             * Get product features (bullet points) for short description
             ************************/
            $wpsProductFeatureData = getData("https://api.wps-inc.com/products/{$wpsProductData['data']['id']}/features", $apiToken);
            $shortDescription = "<ul>";
            $description = "<ul>";
            foreach($wpsProductFeatureData['data'] as $key => $feature) {
                $shortDescription .= $key < 4 ? "<li>".$feature['name']."</li>" : '';
                $description .= "<li>".$feature['name']."</li>";
            }
            $shortDescription .= "</ul>";
            $description .= "</ul>";
            $magentoProduct->setData('short_description', $shortDescription);
            $magentoProduct->setData('description', $wpsProductData['data']['description']."<br>".$description);
            
            /************************
             * Get product Country
             ************************/
            $wpsCountryData = getData("https://api.wps-inc.com/items/{$item['id']}/country", $apiToken);
            $countryCode = substr($wpsCountryData['data']['code'], 0, 2);
            $magentoProduct->setCountryOfManufacture($countryCode);
            
            /************************
             * Get product Images
             ************************/
            $wpsImageData = getData("https://api.wps-inc.com/items/{$item['id']}/images", $apiToken);
            
            $mediaDirectory = $objectManager->get('Magento\Framework\Filesystem')
            ->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
            
            foreach ($wpsImageData['data'] as $image) {
                $imageUrl = 'https://' . $image['domain'] . $image['path'] . $image['filename'];
                $imageContent = file_get_contents($imageUrl);
                
                $localDir = $mediaDirectory->getAbsolutePath('catalog/product');
                // Check and modify the file extension if necessary
                $pathInfo = pathinfo($imageUrl);
                if (strtolower($pathInfo['extension']) === 'jfif') {
                    $pathInfo['extension'] = 'jpg';
                    $pathInfo['basename'] = $pathInfo['filename'] . '.' . $pathInfo['extension'];
                }
                $newFileName = $localDir . $pathInfo['basename'];
                
                file_put_contents($newFileName, $imageContent);
                
                // Resize image
                $imageType = exif_imagetype($newFileName);
                if ($imageType == IMAGETYPE_JPEG) {
                    $imageResource = imagecreatefromjpeg($newFileName);
                } elseif ($imageType == IMAGETYPE_PNG) {
                    $imageResource = imagecreatefrompng($newFileName);
                }
                $width = imagesx($imageResource);
                $height = imagesy($imageResource);
                $longest_side = max($width, $height);
                $scale = 570 / $longest_side;
                $new_width = round($width * $scale);
                $new_height = round($height * $scale);
                $new_image = imagecreatetruecolor(570, 570);
                $white = imagecolorallocate($new_image, 255, 255, 255);
                imagefill($new_image, 0, 0, $white);
                imagecopyresampled($new_image, $imageResource, (570 - $new_width) / 2, (570 - $new_height) / 2, 0, 0, $new_width, $new_height, $width, $height);
                if ($imageType == IMAGETYPE_JPEG) {
                    imagejpeg($new_image, $newFileName);
                } elseif ($imageType == IMAGETYPE_PNG) {
                    imagepng($new_image, $newFileName);
                }
                
                // Add image to media gallery
                $magentoProduct->addImageToMediaGallery($newFileName, ['image', 'small_image', 'thumbnail'], false, false);
            }
            
            /************************
             * Setup Supplier
             ************************/
            $suppliers = array();
            $suppliers['data'][] = array(
                'id' => 91,
                'supplier_code' => 'WP',
                'product_supplier_sku' => $item['supplier_product_id'],
                'cost' => $item['standard_dealer_price'],
                'tax' => 0,
                'position' => 1,
                'record_id' => 91
            );
            
            /************************
             * Get product attributes
             ************************/
            $attributeResponses = getData("https://api.wps-inc.com/items/{$item['id']}/attributevalues", $apiToken);
            $attributeRepository = $objectManager->get('Magento\Eav\Api\AttributeRepositoryInterface');
            $eavConfig = $objectManager->get('Magento\Eav\Model\Config');
            
            // Inject manufacturer, accounting_category and country set it gets set
            $attributeResponses['data'][] = ['attributekey_id'=>1,'name'=>$brand['name']];
            $attributeResponses['data'][] = ['attributekey_id'=>2,'name'=>'PAM-WPS'];
            
            foreach ($attributeResponses['data'] as $attributeResponse) {
                
                $attributeCode = '';
                if ($attributeResponse['attributekey_id'] == 1) {
                    $attributeCode = 'manufacturer';
                } elseif ($attributeResponse['attributekey_id'] == 13) {
                    $attributeCode = 'size';
                } elseif ($attributeResponse['attributekey_id'] == 15) {
                    $attributeCode = 'color';
                } elseif ($attributeResponse['attributekey_id'] == 2) {
                    $attributeCode = 'accounting_category';
                } elseif ($attributeResponse['attributekey_id'] == 3) {
                    $attributeCode = 'country_of_manufacture';
                }
                
                if ($attributeCode) {
                    //die("attributeCode is ".$attributeCode);
                    $attribute = $eavConfig->getAttribute('catalog_product', $attributeCode);
                    $options = $attribute->getSource()->getAllOptions();
                    
                    // Check if the attribute value exists
                    $valueExists = false;
                    foreach ($options as $option) {
                        if ($option['label'] == $attributeResponse['name']) {
                            $magentoProduct->setData($attributeCode, $option['value']);
                            $valueExists = true;
                            break;
                        }
                    }
                    
                    // If the value does not exist, create it
                    if (!$valueExists) {
                        $option = ['value' => [$attributeResponse['name']], 'label' => $attributeResponse['name']];
                        $attribute->addData(['option' => ['value' => $option]]);
                        $attributeRepository->save($attribute);
                        
                        // Reload to get the new option ID
                        $attribute = $eavConfig->getAttribute('catalog_product', $attributeCode);
                        $newOptionId = null;
                        foreach ($attribute->getSource()->getAllOptions() as $option) {
                            if ($option['label'] == $attributeResponse['name']) {
                                $newOptionId = $option['value'];
                                break;
                            }
                        }
                        $magentoProduct->setData($attributeCode, $newOptionId);
                    }
                }
            }
            
            /************************
             * Set the Category
             ************************/ 
            $brandName = ucwords(strtolower($brand['name']));
            $categoryCollection = $objectManager->create('Magento\Catalog\Model\ResourceModel\Category\CollectionFactory');
            $categories = $categoryCollection->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('name', $brandName)
            ->setPageSize(1);
            
            if ($categories->getSize()) {
                $categoryId = $categories->getFirstItem()->getId();
                $magentoProduct->setCategoryIds([$categoryId]); // Assign the product to the category
            }
            
            // Set the attributes based on your mapping
            $magentoProduct->setSku($item['sku']);
            $magentoProduct->setData('manufacturer_sku', $item['supplier_product_id']);
            $magentoProduct->setName(ucwords(strtolower($item['name'])));
            $magentoProduct->setPrice($item['list_price']);
            $magentoProduct->setData('cost', $item['standard_dealer_price']);
            $magentoProduct->setData('ai_length', $item['length']);
            $magentoProduct->setData('ai_width', $item['width']);
            $magentoProduct->setData('ai_height', $item['height']);
            $magentoProduct->setData('ai_special_box', 0);
            $magentoProduct->setData('weight', $item['weight']);
            $magentoProduct->setData('upc_ean', $item['upc']);
            $magentoProduct->setData('wps_status', $item['status']);
            if($item['mapp_price'] > 0) $magentoProduct->setData('map_price', $item['mapp_price']);
            $magentoProduct->setData('visibility', 4);
            $magentoProduct->setData('status', 1);
            $magentoProduct->setData('wps_item_id', $item['id']);
            $magentoProduct->setData('free_shipping', 169);
            $magentoProduct->setAttributeSetId(12);
            $magentoProduct->setWebsiteIds([1]);
            
            // Save the product
            $magentoProduct->save();
            
            // Set Supplier
            $supplierObserver = $objectManager->get('\Magestore\SupplierSuccess\Observer\Catalog\ControllerProductSaveAfter');
            $data = $supplierObserver->processParams($magentoProduct, $suppliers['data']);
            $supplierObserver->deleteSupplierProduct($magentoProduct->getId(), array_keys($data));
            $unsaveData = $supplierObserver->modifySupplierProduct($magentoProduct->getId(), $data);
            if (! empty($unsaveData)) {
                $supplierObserver->addSupplierProduct($unsaveData);
            }
            
            // Stop the script after creating the first product
            echo date('Y-m-d H:i:s')." - Product created with SKU ".$item['sku']."\r\n";
            error_log(date('Y-m-d H:i:s')." - Product created with SKU ".$item['sku']."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/wps.log');
            
            sleep(5); // Give the API a break
        }
        //$allItems = array_merge($allItems, $response['data']); 
        
        // Check if there's a next cursor and update the cursor variable
        $cursor = isset($response['meta']['cursor']['next']) ? $response['meta']['cursor']['next'] : null;
    } while ($cursor); // Continue until there's no next cursor
    
    //return $allItems;
}

function getInventory($apiToken, $objectManager) {
    $productCollection = $objectManager->create('Magento\Catalog\Model\ResourceModel\Product\Collection');
    $stockRegistry = $objectManager->get('Magento\CatalogInventory\Api\StockRegistryInterface');
    $productCollection->addAttributeToSelect('wps_item_id')
    ->addFieldToFilter('wps_item_id', ['gt' => 0]);
    
    foreach ($productCollection as $product) {
        $inventoryUrl = "https://api.wps-inc.com/inventory?filter[item_id]={$product->getWpsItemId()}";
        $inventoryData = getData($inventoryUrl, $apiToken);
        
        $stockChange = $objectManager->get('Magestore\InventorySuccess\Model\StockActivity\StockChange');
        
        $warehouseId = 5;
        $productId = $product->getId();
        $qtyChange = $inventoryData['data'][0]['total'];
            
        $stockChange->update($warehouseId, $productId, $qtyChange);
        
        $stockItem = $stockRegistry->getStockItem($product->getId());
        $totalQty = $inventoryData['data'][0]['total'];
        
        if ($totalQty > 0) {
            $stockItem->setIsInStock(true);
        } else {
            $stockItem->setIsInStock(false);
        }
        
        $stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
        
        // Echo the SKU of the product that was updated
        echo "Updated product SKU: " . $product->getSku() . ", Stock Status: " . ($totalQty > 0 ? "In Stock" : "Out of Stock") . "\n";
    }
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
    $allBrands = getBrands($apiToken);
    $processBrands = false;
    
    foreach($allBrands as $brand) {
        if ($brand['name'] === 'ALL BALLS') {
            $processBrands = true;
        }
        
        if (!$processBrands) {
            continue;
        }
        
        $items = getItems($brand, $apiToken, $objectManager);
    }
}

if (in_array('getInventory', $argv)) {
    getInventory($apiToken, $objectManager);
}
