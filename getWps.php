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

function getItems($brand, $apiToken, $objectManager, $lastProcessedItemId = null) {
    try {
        throw new Exception("Induced error for testing");
        $base_url = "https://api.wps-inc.com/brands/{$brand['id']}/items";
        
        $productRepository = $objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface');
        
        
        $cursor = null; // Start with no cursor
        $foundLastProcessedItem = ($lastProcessedItemId == null);
        
        do {
            $url = $base_url;
            
            // If there is a cursor, add it as a query parameter
            if ($cursor) {
                $url .= '?page[cursor]=' . $cursor;
            }
            
            $response = getData($url, $apiToken);
            
            if (!$foundLastProcessedItem) {
                foreach($response['data'] as $item) {
                    if (!$foundLastProcessedItem) {
                        if ($item['id'] == $lastProcessedItemId) {
                            $foundLastProcessedItem = true;
                        }
                        $cursor = isset($response['meta']['cursor']['next']) ? $response['meta']['cursor']['next'] : null;
                        continue;
                    }
                }
            }
            
            foreach($response['data'] as $item) {
                
                try {
                    $existingProduct = $productRepository->get($item['sku']);
                    
                    // Check if wps_item_id is set, if not, update it
                    if ($existingProduct->getWpsItemId() == '' && $existingProduct->getAccountingCategory() == '6392') {
                        echo ( "[".date('Y-m-d H:i:s')."] Setting wps_item_id for ".$existingProduct->getSku()." to ".$item['id']."\r\n");
                        $existingProduct->setWpsItemId($item['id']);
                        $productRepository->save($existingProduct);
                    }
                    
                    // If the product exists, skip to the next item
                    continue;
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    // If the product does not exist, continue with creation
                }
                
                // Let's get all the items included data
                $wpsItemData = getData("https://api.wps-inc.com/items/crutch/{$item['sku']}?include=product,country,images,attributevalues,inventory", $apiToken);
                
                // Check if Item is Available
                if(in_array($item['status'], ['NLA'])) continue;
                
                $magentoProduct = $objectManager->create('Magento\Catalog\Model\Product');
                
                /************************
                 * Get product data for description etc..
                 ************************/
                $wpsProductData = $wpsItemData['data']['product']['data'];
                
                /************************
                 * Get product features (bullet points) for short description
                 ************************/
                $wpsProductFeatureData = getData("https://api.wps-inc.com/products/{$wpsProductData['id']}/features", $apiToken);
                $shortDescription = "<ul>";
                $description = "<ul>";
                foreach($wpsProductFeatureData['data'] as $key => $feature) {
                    $shortDescription .= $key < 4 ? "<li>".$feature['name']."</li>" : '';
                    $description .= "<li>".$feature['name']."</li>";
                }
                $shortDescription .= "</ul>";
                $description .= "</ul>";
                $magentoProduct->setData('short_description', $shortDescription);
                $magentoProduct->setData('description', $wpsProductData['description']."<br>".$description);
                
                /************************
                 * Get product Country
                 ************************/
                $wpsCountryData = $wpsItemData['data']['country'];
                $countryCode = substr($wpsCountryData['data']['code'], 0, 2);
                $magentoProduct->setCountryOfManufacture($countryCode);
                
                /************************
                 * Get product Images
                 ************************/
                $wpsImageData = $wpsItemData['data']['images'];
                
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
                $attributeResponses = $wpsItemData['data']['attributevalues'];
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
                            echo "Adding new attribute ".$attributeResponse['name']."\r\n";
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
                
                /************************
                 * Set the Inventory
                 ************************/
                setInventory($wpsItemData['data']['inventory'], $magentoProduct, $objectManager);
                
                // Set Supplier
                $supplierObserver = $objectManager->get('\Magestore\SupplierSuccess\Observer\Catalog\ControllerProductSaveAfter');
                $data = $supplierObserver->processParams($magentoProduct, $suppliers['data']);
                $supplierObserver->deleteSupplierProduct($magentoProduct->getId(), array_keys($data));
                $unsaveData = $supplierObserver->modifySupplierProduct($magentoProduct->getId(), $data);
                if (! empty($unsaveData)) {
                    $supplierObserver->addSupplierProduct($unsaveData);
                }
                
                echo date('Y-m-d H:i:s')." - Product created with SKU ".$item['sku']."\r\n";
                error_log(date('Y-m-d H:i:s')." - Product created with SKU ".$item['sku']."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/wps.log');
                
                //sleep(1); // Give the API a break
            }
            //$allItems = array_merge($allItems, $response['data']); 
            
            // Check if there's a next cursor and update the cursor variable
            $cursor = isset($response['meta']['cursor']['next']) ? $response['meta']['cursor']['next'] : null;
        //} while ($cursor && !$foundLastProcessedItem); // Continue until there's no next cursor
        } while ($cursor);
        //return $allItems;
    } catch (\Exception $e) {
        // Call custom error handler with exception details
        customErrorHandler($e->getMessage(), $e->getFile(), $e->getLine());
        
        // Optionally, re-throw the exception if you want it to be handled further up the chain
        throw $e;
    }
}

function setInventory($inventory, $product, $objectManager) {
    $stockRegistry = $objectManager->get('Magento\CatalogInventory\Api\StockRegistryInterface');
    $inventoryData = $inventory;
    
    if(!isset($inventoryData['data'])) return true;
    
    $stockChange = $objectManager->get('Magestore\InventorySuccess\Model\StockActivity\StockChange');
    
    $warehouseId = 5;
    $productId = $product->getId();
    $qtyChange = $inventoryData['data']['total'];
    
    $stockChange->update($warehouseId, $productId, $qtyChange);
    
    $stockItem = $stockRegistry->getStockItem($product->getId());
    
    if ($stockItem->getTotalQty() > 0) {
        $stockItem->setIsInStock(true);
    } else {
        $stockItem->setIsInStock(false);
    }
    
    $stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
    
    // Echo the SKU of the product that was updated
    echo "[".date('Y-m-d H:i:s')."] - Updated SKU: " . $product->getSku() . ", Stock Status: " . ($stockItem->getTotalQty() > 0 ? "In Stock" : "Out of Stock") . "\n";
}

function updateInventory($apiToken, $objectManager) {
    $productCollection = $objectManager->create('Magento\Catalog\Model\ResourceModel\Product\Collection');
    $stockRegistry = $objectManager->get('Magento\CatalogInventory\Api\StockRegistryInterface');
    $productCollection->addAttributeToSelect('wps_item_id')
    ->addFieldToFilter('wps_item_id', ['gt' => 0])
    ->joinField('qty',
        'cataloginventory_stock_item',
        'qty',
        'product_id=entity_id',
        'qty <= 0', // Filter for quantity 0 or less
        'left')
        ->getSelect()
        ->group('e.entity_id'); // Add group by clause
    
    $foundTargetSku = true;
    foreach ($productCollection as $product) {
        
        // Continue until SKU
        if (!$foundTargetSku) {
            if ($product->getSku() == '72-7308YL') {
                $foundTargetSku = true;
            }
            continue;
        }
        
        $inventoryUrl = "https://api.wps-inc.com/inventory?filter[item_id]={$product->getWpsItemId()}";
        $inventoryData = getData($inventoryUrl, $apiToken);
        
        if(!isset($inventoryData['data'][0])) continue;
        
        $stockChange = $objectManager->get('Magestore\InventorySuccess\Model\StockActivity\StockChange');
        
        $warehouseId = 5;
        $productId = $product->getId();
        $qtyChange = $inventoryData['data'][0]['total'];
            
        $stockChange->update($warehouseId, $productId, $qtyChange);
        
        $stockItem = $stockRegistry->getStockItem($product->getId());
        $totalQty = $inventoryData['data'][0]['total'];
        
        if ($stockItem->getTotalQty() > 0) {
            $stockItem->setIsInStock(true);
        } else {
            $stockItem->setIsInStock(false);
        }
        
        $stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
        
        // Echo the SKU of the product that was updated
        echo "[".date('Y-m-d H:i:s')." - Updated SKU: " . $product->getSku() . ", Stock Status: " . ($stockItem->getTotalQty() > 0 ? "In Stock" : "Out of Stock") . "\n";
    }
}

function customErrorHandler($message, $file, $line) {
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $errorMsg = "Error caught: Message: {$message}\r\n File: {$file}\r\n Line: {$line}";
    
    /** @var \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder */
    $transportBuilder = $objectManager->get('\Magento\Framework\Mail\Template\TransportBuilder');
    
    // Get the inline translation object
    $inlineTranslation = $objectManager->get('Magento\Framework\Translate\Inline\StateInterface');
    
    // Suspend inline translation
    $inlineTranslation->suspend();
    
    $templateId = '15';
    $fromEmail = 'web.sales@happy-trail.com';
    $fromName = 'Happy Trails';
    $toEmail = 'cralls@vectorns.com';
    
    $templateVars = [
        'msg' => $errorMsg
    ];
    
    $from = ['email' => $fromEmail, 'name' => $fromName];
    
    $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
    $templateOptions = [
        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
        'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID
    ];
    $transport = $transportBuilder->setTemplateIdentifier($templateId, $storeScope)
    ->setTemplateOptions($templateOptions)
    ->setTemplateVars($templateVars)
    ->setFrom($from)
    ->addTo($toEmail)
    ->getTransport();
    $transport->sendMessage();
    
    $inlineTranslation->resume();
}


date_default_timezone_set("America/Boise");
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
        if ($brand['name'] === 'K&L') {
            $processBrands = true;
            $lastProcessedItemId = '135-6355';
        }
        
        if (!$processBrands) {
            continue;
        }
        
        echo "[".date('Y-m-d H:i:s')."] Starting on ".$brand["name"]."\r\n";
        $items = getItems($brand, $apiToken, $objectManager, $lastProcessedItemId);
    }
}

if (in_array('updateInventory', $argv)) {
    getInventory($apiToken, $objectManager);
}



####### API Item Response #######
/*
Array
(
    [data] => Array
        (
            [id] => 66005
            [brand_id] => 176
            [country_id] => 44
            [product_id] => 221799
            [sku] => 462-91002X
            [name] => GM-54S DSG AZTEC HELMET BLACK 2X
            [list_price] => 244.95
            [standard_dealer_price] => 159.99
            [supplier_product_id] => 2548218
            [length] => 15.1
            [width] => 11
            [height] => 11.3
            [weight] => 5.68
            [upc] => 191361026317
            [superseded_sku] =>
            [status_id] => NLA
            [status] => NLA
            [unit_of_measurement_id] => 12
            [has_map_policy] =>
            [sort] => 5
            [created_at] => 2016-06-17 20:48:39
            [updated_at] => 2023-10-08 08:54:41
            [published_at] => 2016-06-17 20:48:39
            [product_type] => Helmets
            [mapp_price] => 0.00
            [carb] =>
            [propd1] =>
            [propd2] =>
            [prop_65_code] =>
            [prop_65_detail] =>
            [drop_ship_fee] => FR
            [drop_ship_eligible] => 1
            [attributevalues] => Array
                (
                    [data] => Array
                        (
                            [0] => Array
                                (
                                    [id] => 848
                                    [attributekey_id] => 15
                                    [name] => Black
                                    [sort] => 0
                                    [created_at] => 2016-06-17 20:53:25
                                    [updated_at] => 2021-10-29 18:30:25
                                )

                            [1] => Array
                                (
                                    [id] => 1049
                                    [attributekey_id] => 1
                                    [name] => Helmets
                                    [sort] => 0
                                    [created_at] => 2016-06-17 20:53:25
                                    [updated_at] => 2016-06-17 20:53:25
                                )

                            [2] => Array
                                (
                                    [id] => 2407
                                    [attributekey_id] => 13
                                    [name] => 2X-Large
                                    [sort] => 600
                                    [created_at] => 2016-06-22 23:28:04
                                    [updated_at] => 2016-06-22 23:28:04
                                )

                            [3] => Array
                                (
                                    [id] => 10326
                                    [attributekey_id] => 287
                                    [name] => GM-54S
                                    [sort] => 0
                                    [created_at] => 2020-06-03 22:23:30
                                    [updated_at] => 2023-06-22 16:12:47
                                )

                        )

                )

            [country] => Array
                (
                    [data] => Array
                        (
                            [id] => 44
                            [code] => CN
                            [name] => China (Mainland)
                            [created_at] => 2016-05-04 19:22:10
                            [updated_at] => 2016-05-04 19:22:10
                        )

                )

            [images] => Array
                (
                    [data] => Array
                        (
                            [0] => Array
                                (
                                    [id] => 50566
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => 14db-57f69371915ac.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1480284
                                    [signature] => 1abdb65c95777bad29b521434767abf0f0cd3c267776bb0888004874602a928f
                                    [created_at] => 2016-10-06 18:09:58
                                    [updated_at] => 2017-02-23 21:15:23
                                )

                            [1] => Array
                                (
                                    [id] => 50567
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => 451d-57f6937d5ec91.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1637860
                                    [signature] => 246fcbfafb427297da6060f3b6600df113f1227279388267f566b2df8f2e85a6
                                    [created_at] => 2016-10-06 18:10:07
                                    [updated_at] => 2017-02-23 21:15:24
                                )

                            [2] => Array
                                (
                                    [id] => 50568
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => a7a7-57f6938a21204.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1402954
                                    [signature] => 25e95e77f49c3152ee3a71a6f3540a2db0cd9dcc8d7784af3da9e30bc7200ce1
                                    [created_at] => 2016-10-06 18:10:19
                                    [updated_at] => 2017-02-23 21:15:24
                                )

                            [3] => Array
                                (
                                    [id] => 50569
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => eee9-57f69392d4d65.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1436064
                                    [signature] => 0d1050419875ba11a59514d2d212b1b22a54b8769e2cbc122ed9019ced6e7653
                                    [created_at] => 2016-10-06 18:10:29
                                    [updated_at] => 2017-02-23 21:15:24
                                )

                            [4] => Array
                                (
                                    [id] => 50570
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => 82cc-57f6939e530bf.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1516489
                                    [signature] => ae07833039f34fdff7fb5e5332794f8eb1467669b2a581e9409e5f41e2fd73f8
                                    [created_at] => 2016-10-06 18:10:39
                                    [updated_at] => 2017-02-23 21:15:25
                                )

                            [5] => Array
                                (
                                    [id] => 50571
                                    [domain] => cdn.wpsstatic.com/
                                    [path] => images/
                                    [filename] => e063-57f693a88b260.jpg
                                    [alt] =>
                                    [mime] => image/jpeg
                                    [width] => 2000
                                    [height] => 2000
                                    [size] => 1350714
                                    [signature] => 4849b1237638fbe1dba951b51b3fe41d186d2a36e6138a625911c09acd998c94
                                    [created_at] => 2016-10-06 18:10:54
                                    [updated_at] => 2017-02-23 21:15:25
                                )

                        )

                )

            [inventory] => Array
                (
                    [data] => Array
                        (
                            [id] => 634298
                            [item_id] => 66005
                            [sku] => 462-91002X
                            [ca_warehouse] => 0
                            [ga_warehouse] => 0
                            [id_warehouse] => 0
                            [in_warehouse] => 0
                            [pa_warehouse] => 0
                            [pa2_warehouse] => 0
                            [tx_warehouse] => 0
                            [total] => 0
                            [created_at] => 2021-11-20 14:37:39
                            [updated_at] => 2021-11-26 23:25:28
                        )

                )

            [product] => Array
                (
                    [data] => Array
                        (
                            [id] => 221799
                            [designation_id] => 21
                            [name] => DSG GM-54S Aztec Helmet
                            [alternate_name] =>
                            [care_instructions] =>
                            [description] => The DSG GMAX 54S uses the GMAX Ultimate Flip-Up Jaw Breath Guard System. This system allows the snap in breath guard to remain in the jaw when the jaw section is lifted, thus eliminating the additional hassle of removing your gloves.
                            [sort] => 5
                            [image_360_id] =>
                            [image_360_preview_id] =>
                            [size_chart_id] =>
                            [created_at] => 2017-02-17 18:44:46
                            [updated_at] => 2023-10-08 08:54:41
                        )

                )

        )

)
*/
