<?php
namespace VNS\Custom\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;

class AfterPlaceOrder implements ObserverInterface
{
    /**
     * Order Model
     *
     * @var \Magento\Sales\Model\Order $order
     */
    protected $order;

     public function __construct(
        \Magento\Sales\Model\Order $order,
         \Magento\Directory\Model\RegionFactory $regionFactory,
         \Magento\Catalog\Model\ProductRepository $productRepository,
         \Magestore\SupplierSuccess\Model\ResourceModel\Product\Supplier\Collection $supplierCollection,
         TransportBuilder $transportBuilder,
         StoreManagerInterface $storeManager,
         StateInterface $state,
         \Magestore\PurchaseOrderSuccess\Model\PurchaseOrder $purchaseOrder,
         \Magestore\PurchaseOrderSuccess\Model\PurchaseOrderFactory $purchaseOrderFactory,
         \Magestore\PurchaseOrderSuccess\Controller\Adminhtml\PurchaseOrder\Save $poSave,
         \Magestore\PurchaseOrderSuccess\Controller\Adminhtml\PurchaseOrder\Product\Save $productSave,
         \VNS\Admin\Block\Adminhtml\Madata $madata,
         \Magento\Variable\Model\Variable $variable,
         \Magento\Sales\Model\Order\Item $salesItem,
         \Magestore\PurchaseOrderSuccess\Model\PurchaseOrder\Item $purchaseOrderItem,
         \Magestore\PurchaseOrderSuccess\Model\Repository\PurchaseOrderRepository $purchaseOrderRepository,
         \Magento\CatalogInventory\Api\StockStateInterface $stockState
    )
    {
        $this->order = $order;
        $this->regionFactory = $regionFactory;
        $this->productRepository = $productRepository;
        $this->supplierCollection = $supplierCollection;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->inlineTranslation = $state;
        $this->purchaseOrder = $purchaseOrder;
        $this->purchaseOrderFactory = $purchaseOrderFactory;
        $this->poSave = $poSave;
        $this->productSave = $productSave;
        $this->madata = $madata;
        $this->variable = $variable;
        $this->salesItem = $salesItem;
        $this->purchaseOrderItem = $purchaseOrderItem;
        $this->purchaseOrderRepository = $purchaseOrderRepository;
        $this->stockState = $stockState;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
       $orderIds = $observer->getEvent()->getOrderIds();
       if(is_array($orderIds)) {
           foreach ($orderIds as $orderId) {
                $order = $this->order->load($orderId);
           }
       } else {
           return true;
       }
       
       // Set order status to international shipping if that is the method
       if(strpos($order->getShippingDescription(), 'International Shipping') !== false) {
           $order->setStatus('international_shipment');
           $order->save();
           return true;
       }
       
       if($order->getStatus() == 'pending' && strpos($_SERVER['SERVER_NAME'], 'dev') === false && $_SERVER['HTTP_CF_CONNECTING_IP'] != '98.97.117.233') return true;
       if(strpos($order->getCustomerEmail(), 'rockymountainatv.com') !== false) {
           $order->setState('payment_review');
           $order->setStatus('fraud');
           $order->save();
           $this->sendEmail('Rocky Mountain Fraud: '.$order->getIncrementId());
           return true;
       }
       if(strpos($order->getShippingDescription(), 'Pickup')) return true;
       if($order->getGrandTotal() > 1500) {
           $order->setState('payment_review');
           $order->setStatus('fraud');
           $order->save();
           $this->sendEmail('Suspected Fraud on Order '.$order->getIncrementId().' with a grand total of '.$order->getGrandTotal());
       }
       if($order->getWeltpixelFraudScore() > 4.99) {
           $order->setState('payment_review');
           $order->setStatus('fraud');
           $order->save();
           $this->sendEmail('Suspected Fraud on Order '.$order->getIncrementId().' with a fraud score of '.$order->getWeltpixelFraudScore());
       } else {
			$shippingAddress = $order->getShippingAddress();
        	$shippingStreet = $shippingAddress->getStreet();
		   	$billingAddress = $order->getBillingAddress();
            $billingStreet = $billingAddress->getStreet();
            
            // If billing doesn't match RED FLAG
            if($billingStreet[0] != $shippingStreet[0] && strpos($order->getIncrementId(), '-') === false) {
                $order->setState('payment_review');
                $order->setStatus('fraud');
                $order->save();
                $this->sendEmail('MISMATCHED ADDRESSES!! - Suspected Fraud on Order '.$order->getIncrementId().' with a fraud score of '.$order->getWeltpixelFraudScore());
                return true;
            }
            
           // TODO: Check if order has both TR and PU
           //$result = $this->sendPuOrder($order);
           if(!$result) {
            //$result = $this->sendTrOrder($order);
           }
           
           // Add HTP Parts to PO
           try {
               $this->addPo($order);
           } catch (Exception $e) {
               error_log(date('Y-m-d H:i:s')." - AfterPlaceOrder.php Error - ".$e->getMessage()."\r\n", 3, '/home/'.get_current_user().'/public_html/error_log');
           }
       }
    }
    
    public function addPo($order) {
        $items = $order->getItemsCollection();
        $mixedOrder = false;
		$isHtOrder = false;
        $simpleItems = [];
		$stockQtys = [];
        foreach($items as $item) {
            if($item->getProductType() != 'bundle' && $item->getPo() == null) $simpleItems[$item->getProductId()] = $item;
            $product = $this->productRepository->getById($item->getProductId());
            if($product->getManufacturer() != 51) {
				$mixedOrder = true;
			} elseif(!$isHtOrder) {
				$isHtOrder = true;
				error_log("\r\n[".date('Y-m-d H:i:s')."] ****** Processing Order ".$order->getIncrementId()." ******\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
				// Check if in stock
				$stockQty = $this->stockState->getStockQty($product->getId(), 0);
				if($stockQty > 0) {
					$mixedOrder = true;
					$stockQtys[$item->getProductId()] = $stockQty;
				}
			}
        }
        foreach($simpleItems as $key => $item) {
            if(isset($stockQtys[$key]) && $stockQtys[$key] > 0) continue;
            $product = $this->productRepository->getById($item->getProductId());
            
            error_log("[".date('Y-m-d H:i:s')."] Order Item: ".$item->getSku()." - Manufacturer: ".$product->getManufacturer()." - Type: ".$item->getProductType()." - Material: ".$product->getAttributeText('material')."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
            
            if($product->getManufacturer() == 51 && $product->getAttributeText('material') != 'Assembly') {
                if($product->getTypeId() == 'simple' && count($product->getOptions()) > 0) return $item->getSku()." has Configurable Options on it";

                // Setup estimatedShipDate
                $itemsWaiting = $this->madata->getItemsWaiting();
                $buildTime = $itemsWaiting['buildTime'];
                $variableData = $this->variable->loadByCode('fab_hours', 'base');
                $fabHours = $variableData->getPlainValue();
                $weeks = $buildTime / $fabHours;
                $hours = ceil($weeks * 168);
                $estimatedShipDate = date('n/j/Y', strtotime('+'.$hours.' hours'));
                $day = date('D', strtotime($estimatedShipDate));
                if($day != 'Fri') $estimatedShipDate = date('Y-m-d', strtotime('this friday, 11:59am', strtotime($estimatedShipDate)));
                
                // Check if the part is already Waiting and if there is room for it on another PO
                $purchaseOrderItems = $this->purchaseOrderItem->getCollection()->addFieldToFilter('product_sku', ['eq'=>$item->getSku()])->addFieldToFilter('build_status', ['in' => ['Waiting', 'Processing']])->setOrder('purchase_order_item_id', 'ASC');
                $added = false;
                if(count($purchaseOrderItems) > 0) {
                    foreach($purchaseOrderItems as $purchaseOrderItem) {
                        if(($purchaseOrderItem->getQtyOrderred() - $purchaseOrderItem->getQtyComplete()) > 0 && ($purchaseOrderItem->getQtyOrderred() - $purchaseOrderItem->getQtyPriority()) > 0) {
                            $po = $this->purchaseOrder->load($purchaseOrderItem->getPurchaseOrderId());
                            if($po->getStatus() != 3) continue;
                            
                            error_log("[".date('Y-m-d H:i:s')."]      Adding ".$item->getSku()." to Confirmed PO ".$po->getPurchaseCode()."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
                            
                            $poData = $this->addExistingToPo($purchaseOrderItem, $item, $estimatedShipDate, $order, $product, $po, $mixedOrder);
                            
                            $this->productSave->addFromOrder(['purchase_id'=>$po->getPurchaseOrderId(),'supplier_id'=>148,'selected'=>[0=>$item->getProductId()]]);
                            $this->poSave->addFromOrder($poData);
                            
                            $added = true;
                            break;
                        }
                    }
                }
                
                if(!$added) {
                    // Check for a Pending PO
                    $pos = $this->purchaseOrder->getCollection()->addFieldToFilter('supplier_id', ['eq'=>148])->addFieldToFilter('status', ['eq'=>1]);
                    if(count($pos) > 0) {
                        foreach($pos as $po) { // Add item to existing PO
                            
                            // Check if item already exists on the PO
                            $poItems = $po->getItems();
                            $existingItem = 0;
                            foreach($poItems as $poItem) {
                                if($poItem->getProductId() == $item->getProductId()) {
                                    $existingItem = $poItem;
                                }
                            }
                            error_log("[".date('Y-m-d H:i:s')."]      Adding ".$item->getSku()." to Pending PO ".$po->getPurchaseCode()."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
                            
                            if(is_object($existingItem)) {
                                $poData = $this->addExistingToPo($existingItem, $item, $estimatedShipDate, $order, $product, $po, $mixedOrder);
                            } else {
                                $poData = $this->addNewToPo($item, $estimatedShipDate, $order, $product, $weeks, $po, $mixedOrder);
                            }
                            
                            $this->productSave->addFromOrder(['purchase_id'=>$po->getPurchaseOrderId(),'supplier_id'=>148,'selected'=>[0=>$item->getProductId()]]);
                            $this->poSave->addFromOrder($poData);
                            break;
                        }
                    } else { // Create a new PO and add item and forcast needs
                        $po = $this->purchaseOrderFactory->create()->setPurchasedAt(date('m/d/Y'))->setSupplierId('148')->setCurrencyCode('USD')->setCurrencyRate('1')->setUserId(2)->setType(1)->save();
                        
                        error_log("[".date('Y-m-d H:i:s')."]      Adding ".$item->getSku()." to New PO ".$po->getPurchaseCode()."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
                        
                        $poData = $this->addNewToPo($item, $estimatedShipDate, $order, $product, $weeks, $po, $mixedOrder);
                        
                        $this->productSave->addFromOrder(['purchase_id'=>$po->getPurchaseOrderId(),'supplier_id'=>148,'selected'=>[0=>$item->getProductId()]]);
                        $this->poSave->addFromOrder($poData);
                        $this->purchaseOrderRepository->convert($po->getPurchaseOrderId());
                    }
                }
            }
        }
        return 1;
    }
    
    public function addNewToPo($item, $estimatedShipDate, $order, $product, $weeks, $po, $mixedOrder) {
        $forcastQty = $this->getForcastQty($weeks, $item, $product);
        
        $poItem = [
            'product_id' => $item->getProductId(),
            'cost' => $item->getCost(),
            'cost_old' => '',
            'qty_ordered' => $forcastQty,
            'qty_ordered_old' => '',
            'assoc_orders' => $order->getIncrementId(),
            'assoc_orders_old' => '',
            'po_comments' => '',
            'po_comments_old' => '',
            'qty_priority' => $item->getQtyOrdered(),
            'qty_priority_old' => '',
            'ship_date' => $estimatedShipDate,
            'ship_date_old' => '',
            'time_ea' => $product->getBuildTime(),
            'time_ea_old' => '',
            'ttl_time' => $product->getBuildTime()*$forcastQty,
            'ttl_time_old' => '',
            'actual_time' => 0,
            'actual_time_old' => 0,
            'gone_to_powder' => '',
            'gone_to_powder_old' => ''
        ];
        
        return $poData = $this->getPoData($po, $poItem, false, $mixedOrder);
    }
    
    public function addExistingToPo($existingItem, $item, $estimatedShipDate, $order, $product, $po, $mixedOrder) {
        $orderLines = explode("<br>", str_replace("<br><br>", "<br>", ltrim($existingItem->getAssocOrders(), "<br>")));
        $assocOrders = [];
        foreach($orderLines as $orderLine) {
            $orderNum = explode("::", $orderLine);
            if(isset($orderNum[0]) && $orderNum[0] != '') $assocOrders[] = '10000'.trim($orderNum[0]);
        }
        $assocOrders[] = $order->getIncrementId();
        
        //$qty_ordered = (int)$existingItem->getQtyOrderred() + $item->getQtyOrdered();
        $qty_priority = $existingItem->getQtyPriority()+$item->getQtyOrdered();
        $qty_ordered = (int)$existingItem->getQtyOrderred() >= $qty_priority ? (int)$existingItem->getQtyOrderred() : $qty_priority;
        
        $poItem = [
            'product_id' => $item->getProductId(),
            'cost' => $existingItem->getCost(),
            'cost_old' => $existingItem->getCostOld(),
            'qty_ordered' => $qty_ordered,
            'qty_ordered_old' => $existingItem->getQtyOrderred(),
            'assoc_orders' => implode(",", $assocOrders),
            'assoc_orders_old' => $existingItem->getAssocOrders(),
            'po_comments' => $existingItem->getPoComments(),
            'po_comments_old' => $existingItem->getPoComments(),
            'qty_priority' => $qty_priority,
            'qty_priority_old' => $existingItem->getQtyPriority(),
            'ship_date' => $estimatedShipDate,
            'ship_date_old' => $existingItem->getShipDate(),
            'time_ea' => $product->getBuildTime(),
            'time_ea_old' => $product->getBuildTime(),
            'ttl_time' => $product->getBuildTime()*$qty_ordered,
            'ttl_time_old' => $existingItem->getTtlTime(),
            'actual_time' => $existingItem->getActualTime(),
            'actual_time_old' => $existingItem->getActualTime(),
            'gone_to_powder' => $existingItem->getGoneToPowder(),
            'gone_to_powder_old' => $existingItem->getGoneToPowder()
        ];
        return $poData = $this->getPoData($po, $poItem, true, $mixedOrder);
    }
    
    public function getForcastQty($weeks, $item, $product) {
        // Forcast order qty needed according to current lead time
        $leadTimeAgo = date('Y-m-d H:i:s', strtotime("-".ceil($weeks)." weeks"));
        
        $recentSales = $this->salesItem->getCollection();
        //$recentSales->addFieldToFilter('main_table.parent_id', array('neq' => null))->addFieldToFilter('order.state', array('eq' => \Magento\Sales\Model\Order::STATE_COMPLETE));
        $recentSales->addAttributeToFilter('created_at', ['gteq'=>$leadTimeAgo])->addAttributeToFilter('created_at', ['lteq'=>date('Y-m-d H:i:s')])
                    ->addAttributeToFilter('sku', ['eq'=>$item->getSku()])
                    ->addAttributeToFilter('qty_refunded', ['eq'=>0]);
        $recentSalesItems = $recentSales->getColumnValues('qty_ordered');
        $recentSalesItems = array_sum($recentSalesItems);
        
        $lastYearSales = $this->salesItem->getCollection();
        //$lastYearSales->addFieldToFilter('main_table.parent_id', array('neq' => null))->addFieldToFilter('order.state', array('eq' => \Magento\Sales\Model\Order::STATE_COMPLETE));
        $lastYearSales->addAttributeToFilter('created_at', ['gteq'=>date('Y-m-d H:i:s', strtotime('-1 year'))])
                      ->addAttributeToFilter('created_at', ['lteq'=>date('Y-m-d H:i:s', strtotime('-1 year', strtotime("+".ceil($weeks)." weeks")))])
                      ->addAttributeToFilter('sku', ['eq'=>$item->getSku()])
                      ->addAttributeToFilter('qty_refunded', ['eq'=>0]);
        $lastYearSalesItems = $lastYearSales->getColumnValues('qty_ordered');
        $lastYearSalesItems = array_sum($lastYearSalesItems);
        
        $buildQuantity = $product->getBuildQuantity();
        
        /*error_log(date('Y-m-d H:i:s')." recentSales query ".$recentSales->getSelect()."\r\n", 3, '/home/'.get_current_user().'/public_html/error_log');
         error_log(date('Y-m-d H:i:s')." lastYearSales query ".$lastYearSales->getSelect()."\r\n", 3, '/home/'.get_current_user().'/public_html/error_log');
         error_log(date('Y-m-d H:i:s')." buildQty is $buildQuantity\r\n", 3, '/home/'.get_current_user().'/public_html/error_log');
         error_log(date('Y-m-d H:i:s')." Qty ".$recentSalesItems." - ".$lastYearSalesItems." - ".$buildQuantity."\r\n", 3, '/home/'.get_current_user().'/public_html/error_log');*/
        
        // Check for reasonable downtick in sales
        $downTick = false;
        $salesDiff = $lastYearSalesItems - $recentSalesItems;
        if($buildQuantity > 0 && $salesDiff > 0 && $salesDiff > $buildQuantity) $downTick = true;
        
        if ($lastYearSalesItems > $recentSalesItems && $lastYearSalesItems > $buildQuantity && !$downTick) {
            $forcastQty = $lastYearSalesItems;
            
            error_log("[".date('Y-m-d H:i:s')."]      Forecasted with lastYearSalesItems = ".$lastYearSalesItems." QUERY: ".$lastYearSales->getSelect()."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
            
        } elseif(($downTick && $recentSalesItems > $buildQuantity) || ($recentSalesItems >= $lastYearSalesItems && $recentSalesItems > $buildQuantity)) {
            $forcastQty = $recentSalesItems;
            
            error_log("[".date('Y-m-d H:i:s')."]      Forecasted with recentSalesItems = ".$recentSalesItems." QUERY: ".$recentSales->getSelect()."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
            
        } else {
            $forcastQty = $buildQuantity;
            
            error_log("[".date('Y-m-d H:i:s')."]      Forecasted with buildQuantity = ".$buildQuantity."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/addPo.log');
            
        }
        
        return $forcastQty = $forcastQty > 0 ? $forcastQty : $item->getQtyOrdered();
    }
    
    public function getPoData($po, $poItem, $existing, $mixedOrder) {
        return $poData = [
            'purchase_order_id' => $po->getPurchaseOrderId(),
            'purchase_code' => $po->getPurchaseCode(),
            'supplier_id' => $po->getSupplierId(),
            'type' => $po->getType(),
            'status' => $po->getStatus(),
            'send_email' => $po->getSendEmail(),
            'is_sent' => $po->getIsSent(),
            'comment' => $po->getComment(),
            'shipping_address' => $po->getShippingAddress(),
            'shipping_method' => $po->getShippingMethod(),
            'shipping_cost' => $po->getShippingCost(),
            'payment_term' => $po->getPaymentTerm(),
            'placed_via' => $po->getPlacedVia(),
            'created_by' => $po->getCreatedBy(),
            'user_id' => $po->getUserId(),
            'total_qty_orderred' => $po->getTotalQtyOrderred(),
            'total_qty_received' => $po->getTotalQtyReceived(),
            'total_qty_transferred' => $po->getTotalQtyTransferred(),
            'total_qty_returned' => $po->getTotalQtyReturned(),
            'total_qty_billed' => $po->getTotalQtyBilled(),
            'subtotal' => $po->getSubtotal(),
            'total_tax' => $po->getTotalTax(),
            'total_discount' => $po->getTotalDiscount(),
            'grand_total_excl_tax' => $po->getTotalExclTax(),
            'grand_total_incl_tax' => $po->getTotalInclTax(),
            'currency_code' => $po->getCountryCode(),
            'currency_rate' => $po->getCurrenctyRate(),
            'purchased_at' => $po->getPurchasedAt(),
            'started_at' => $po->getStartedAt(),
            'expected_at' => $po->getExpectedAt(),
            'created_at' => $po->getCreatedAt(),
            'updated_at' => date('Y-m-d H:i:s'),
            'purchase_key' => $po->getPurchaseKey(),
            'new_shipping_method' => $po->getNewShippingMethod(),
            'new_payment_term' => $po->getNewPaymentTerm(),
            'data' => [
                'sales_period' => 'last_7_days',
                'warehouse_ids' => '',
                'from_date' => '',
                'to_date' => '',
                'forecast_date_to' => '',
            ],
            'low_stock_select' => $po->getLowStockSelect(),
            'mixedOrder' => $mixedOrder,
            'selected_products' => json_encode([
                $poItem['product_id'] => [
                    'cost' => $poItem['cost'],
                    'cost_old' => $poItem['cost_old'],
                    'qty_orderred' => $poItem['qty_ordered'],
                    'qty_orderred_old' => $poItem['qty_ordered_old'],
                    'assoc_orders' => $poItem['assoc_orders'],
                    'assoc_orders_old' => $poItem['assoc_orders_old'],
                    'po_comments' => $poItem['po_comments'],
                    'po_comments_old' => $poItem['po_comments_old'],
                    'qty_priority' => $poItem['qty_priority'],
                    'qty_priority_old' => $poItem['qty_priority_old'],
                    'ship_date' => $poItem['ship_date'],
                    'ship_date_old' => $poItem['ship_date_old'],
                    'time_ea' => $poItem['time_ea'],
                    'time_ea_old' => $poItem['time_ea_old'],
                    'ttl_time' => $poItem['ttl_time'],
                    'ttl_time_old' => $poItem['ttl_time_old'],
                    'actual_time' => $poItem['actual_time'],
                    'actual_time_old' => $poItem['actual_time_old'],
                    'gone_to_powder' => $poItem['gone_to_powder'],
                    'gone_to_powder_old' => $poItem['gone_to_powder_old']
                ]
            ])
        ];
    }
    
    public function sendEmail($msg)
    {
        $templateId = '10';
        $fromEmail = 'web.sales@happy-trail.com';
        $fromName = 'Happy Trails';
        $toEmail = 'cralls@vectorns.com';
        
        try {
            $templateVars = [
                'msg' => $msg
            ];
            
            $storeId = $this->storeManager->getStore()->getId();
            
            $from = ['email' => $fromEmail, 'name' => $fromName];
            $this->inlineTranslation->suspend();
            
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $templateOptions = [
                'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                'store' => $storeId
            ];
            $transport = $this->transportBuilder->setTemplateIdentifier($templateId, $storeScope)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars($templateVars)
            ->setFrom($from)
            ->addTo($toEmail)
            ->getTransport();
            $transport->sendMessage();
            $this->inlineTranslation->resume();
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }
    
    public function sendTrOrder($order, $thirdParty = false) {
        // TODO CHeck if order is in processing state
        
        //get Order All Item
        $itemCollection = $order->getItemsCollection();
        $shippingAddress = $order->getShippingAddress();
        $shippingStreet = $shippingAddress->getStreet();
        $billingAddress = $order->getBillingAddress();
        $billingStreet = $billingAddress->getStreet();
        
        // If billing doesn't match RED FLAG
        if(!$thirdParty && $billingStreet[0] != $shippingStreet[0] && strpos($order->getIncrementId(), '-') === false) {
            $order->setState('payment_review');
            $order->setStatus('fraud');
            $order->save();
            $this->sendEmail('MISMATCHED ADDRESSES!! - Suspected Fraud on Order '.$order->getIncrementId().' with a fraud score of '.$order->getWeltpixelFraudScore());
            return false;
        }
        
        $region = $this->regionFactory->create()->load($shippingAddress->getRegionId())->getCode();
        
        
        $requestData = [];
        $requestData['apikey'] = 'KB0OQRV3JZWA7TVBYUVVK3TEVNE1';
        $requestData['cust'] = '603301';
        $requestData['output'] = 'JSON';
        $requestData['type'] = "ORD";
        $requestData['name'] = $shippingAddress->getFirstName()." ".$shippingAddress->getLastName();
        $requestData['address1'] = $shippingStreet[0];
        if(isset($shippingStreet[1])) $requestData['address2'] = $shippingStreet[1];
        $requestData['city'] = $shippingAddress->getCity();
        $requestData['state'] = $region;
        $requestData['zip'] = $shippingAddress->getPostCode();
        $requestData['po'] = $order->getIncrementId();
        $requestData['allowduplicatepo'] = 'NO';
        $requestData['carrier'] = 'UG';
        $requestData['splitlines'] = 'YES';
        $requestData['submit'] = 'YES';
        //$requestData['location'] = '001'; //TODO Check if ship method is pickup in store and enable this for dropship to store
        
        $i=1;
        $o=0;
        $npu = false;
        $dropShippedItems = array();
        foreach($itemCollection as $item) {
            if($item->getDropshipped() == 1) continue;
            $suppliers = $this->supplierCollection->addFieldToFilter('product_id', array('eq'=>$item->getProductId()));
            $this->supplierCollection->load();
            $productSupplierSku = '';
            if(count($suppliers) > 0) {
                foreach($suppliers as $supplier) {
                    if($supplier->getSupplierId() == '170') {
                        $productSupplierSku = $supplier->getProductSupplierSku();
                        break;
                    } else {
                        if($item->getProductType() != 'simple') continue;
                        $npu = true;
                    }
                }
            } else {
                if($item->getProductType() != 'simple') continue;
                $npu = true;
            }
            
            // If no sku set clear where condition and continue
            if($productSupplierSku == '') {
                $suppliers->clear()->getSelect()->reset(\Zend_Db_Select::WHERE);
                continue;
            }
            
            $requestData['line'][] = $productSupplierSku.",".number_format($item->getQtyOrdered(), 0);
            $dropShippedItems[] = $item;
            //$requestData['line_items'][]['memo'] = "";
            $i++;
            $o++;
            $suppliers->clear()->getSelect()->reset(\Zend_Db_Select::WHERE);
        }
        
        // No tucker products so we won't send the order to them
        if($o == 0) return false;
        
        // Temporarily holding all credit card orders for manual fraud check.
        $paymentMethod = $order->getPayment()->getMethodInstance()->getTitle();
        if(!strpos($paymentMethod, 'ayPal')) {
            $this->sendEmail('TR on '.$order->getIncrementId().' with payment method '.$paymentMethod);
            $order->setState('payment_review');
            $order->setStatus('fraud');
            $order->save();
            return true;
        }
        
        /*****
         * A way to hold all PU orders and notify to add to manual PO
         *****/
        //mail('cralls@vectorns.com', 'Dropship Order for '.$order->getIncrementId(), 'Add to WMCA-1000018972');
        //return true;
        
        error_log(date('Y-m-d H:i:s')." - REQUEST DATA ".print_r($requestData, 1)."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/tr-orders.log');
        
        $stageUrl = "https://api.tucker.com/bin/trws?";
        $l = 0;
        foreach($requestData as $key => $getData) {
            if($key == 'line') {
                foreach($getData as $lineItem) {
                    $stageUrl .= "&line=".$lineItem;
                }
                continue;
            }
            if($key == 'apikey') {
                $stageUrl .= $key."=".$getData;
            } else {
                $stageUrl .= "&".$key."=".$getData;
            }
        }
        
        $stageUrl = str_replace(" ", '%20', $stageUrl);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $stageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $result = curl_exec($ch);
        curl_close($ch);
        
        // Log result
        error_log(date('Y-m-d H:i:s')." - ".print_r($result, 1)."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/tr-orders.log');
        
        $decodedResult = json_decode($result);
        
        if($decodedResult->ORD->status != 'SUCCESSFUL') {
            $errorMsg = $decodedResult->ORD->errormsg." - ";
            foreach($decodedResult->ORD->orderinput->orderline as $orderline) {
                $errorMsg .= $orderline->errormsg." ";
            }
            $this->sendEmail('TR Dropship Order Failed for '.$order->getIncrementId().' with message '.$errorMsg);
            $order->addStatusHistoryComment('Sending order to TR Failed. '.$errorMsg);
            $order->save();
            return false;
        } else {
            // Set order status
            $orderStatus = 'processing_tr';
            if($npu) {
                $orderStatus = 'processing_tr_';
            }
            $order->setStatus($orderStatus);
            
            // Update each order item as dropshipped
            foreach($dropShippedItems as $dropShippedItem) {
                $dropShippedItem->setDropshipped(1);
                $dropShippedItem->save();
            }
            $order->save();
            //$this->messageManager->addSuccess(__('Order sent to Tucker Rocky successfully'));
        }
        return true;
    }
    
    public function sendPuOrder($order, $thirdParty = false) {
        // TODO CHeck if order is in processing state
        
        //get Order All Item
        $itemCollection = $order->getItemsCollection();
        $shippingAddress = $order->getShippingAddress();
        $shippingStreet = $shippingAddress->getStreet();
        $billingAddress = $order->getBillingAddress();
        $billingStreet = $billingAddress->getStreet();
        
        // If billing doesn't match RED FLAG
        if(!$thirdParty && $billingStreet[0] != $shippingStreet[0] && strpos($order->getIncrementId(), '-') === false) {
            $order->setState('payment_review');
            $order->setStatus('fraud');
            $order->save();
            $this->sendEmail('MISMATCHED ADDRESSES!! - Suspected Fraud on Order '.$order->getIncrementId().' with a fraud score of '.$order->getWeltpixelFraudScore());
            return false;
        }
        
        $region = $this->regionFactory->create()->load($shippingAddress->getRegionId())->getCode();
        
        $requestData = array();
        $requestData['dealer_number'] = 'HAP019';
        $requestData['order_type'] = "DS";
        $requestData['purchase_order_number'] = $order->getIncrementId();
        $requestData['shipping_method'] = "ground";
        $requestData['validate_price'] = 0;
        $requestData['cancellation_policy'] = "back_order";
        $requestData['ship_to_address']['name'] = $shippingAddress->getFirstName()." ".$shippingAddress->getLastName();
        $requestData['ship_to_address']['address_line_1'] = $shippingStreet[0];
        if(isset($shippingStreet[1])) $requestData['ship_to_address']['address_line_2'] = $shippingStreet[1];
        $requestData['ship_to_address']['city'] = $shippingAddress->getCity();
        $requestData['ship_to_address']['state'] = $region;
        $requestData['ship_to_address']['postal_code'] = $shippingAddress->getPostCode();
        $requestData['ship_to_address']['country'] = "US";
        $i=1;
        $o=0;
        $npu = false;
        $dropShippedItems = array();
        foreach($itemCollection as $item) {
            if($item->getDropshipped() == 1) continue;
            //if($item->getProductId() == 3389) continue;
            $suppliers = $this->supplierCollection->addFieldToFilter('product_id', array('eq'=>$item->getProductId()));
            $this->supplierCollection->load();
            $productSupplierSku = '';
            if(count($suppliers) > 0) {
                foreach($suppliers as $supplier) {
                    if($supplier->getSupplierId() == '69') {
                        $productSupplierSku = $supplier->getProductSupplierSku();
                        break;
                    } else {
                        if($item->getProductType() != 'simple') continue;
                        $npu = true;
                    }
                }
            } else {
                if($item->getProductType() != 'simple') continue;
                $npu = true;
            }
            
            // If no sku set clear where condition and continue
            if($productSupplierSku == '') {
                $suppliers->clear()->getSelect()->reset(\Zend_Db_Select::WHERE);
                continue;
            }
            
            $requestData['line_items'][$o]['line_number'] = $i;
            $requestData['line_items'][$o]['part_number'] = $productSupplierSku;
            $requestData['line_items'][$o]['quantity'] = number_format($item->getQtyOrdered(), 0);
            $requestData['line_items'][$o]['price'] = number_format($item->getPrice(), 2) * number_format($item->getQtyOrdered(), 0);
            $requestData['line_items'][$o]['currency'] = "USD";
            $dropShippedItems[] = $item;
            //$requestData['line_items'][]['memo'] = "";
            $i++;
            $o++;
            $suppliers->clear()->getSelect()->reset(\Zend_Db_Select::WHERE);
        }
        
        // No parts unlimited products so we won't send the order to them
        if($o == 0) return false;
        
        // Temporarily holding all credit card orders for manual fraud check.
        /*$paymentMethod = $order->getPayment()->getMethodInstance()->getTitle();
        $this->sendEmail('PU on '.$order->getIncrementId().' with payment method '.$paymentMethod);
        if(!strpos($paymentMethod, 'ayPal')) {
            $this->sendEmail('PU on '.$order->getIncrementId().' with payment method '.$paymentMethod);
            $order->setState('payment_review');
            $order->setStatus('fraud');
            $order->save();
            return true;
        }*/
        
        /*****
         * A way to hold all PU orders and notify to add to manual PO
         *****/
        //mail('cralls@vectorns.com', 'Dropship Order for '.$order->getIncrementId(), 'Add to WMCA-1000018972');
        //return true;
        
        error_log(date('Y-m-d H:i:s')." - REQUEST DATA ".print_r($requestData, 1)."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/pu-orders.log');
        
        $stageUrl = "https://api.parts-unlimited.com/api/orders/dropship";
        $ch = curl_init($stageUrl);
        $payload = json_encode($requestData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json','api-key:8KT9MXT-1PG40D7-JCBA64X-YQTMCHB')); // Staging
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json','api-key:TGZHK1S-NTW4KBQ-M4XX36N-0FDFTY5'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        
        // Log result
        error_log(date('Y-m-d H:i:s')." - ".print_r($result, 1)."\r\n", 3, '/home/'.get_current_user().'/public_html/var/log/pu-orders.log');
        
        $decodedResult = json_decode($result);
        if($decodedResult->status_code != '200' && $decodedResult->status_code != '202') {
            $this->sendEmail('PU Dropship Order Failed for '.$order->getIncrementId().' with message '. $payload."\n\n".$result);
            $order->addStatusHistoryComment($decodedResult->status_message);
            $order->save();
            return false;
        } else {
            // Set order status
            $orderStatus = 'processing_pu';
            if($npu) {
                $orderStatus = 'processing_pu_';
            }
            $order->setStatus($orderStatus);
            
            // Update each order item as dropshipped
            foreach($dropShippedItems as $dropShippedItem) {
                $dropShippedItem->setDropshipped(1);
                $dropShippedItem->save();
            }
            $order->save();
        }
        return true;
    }
    
    public function sendOrder($incrId)
    {
        $orderInfo = $this->order->loadByIncrementId($incrId);
        $orderId = $orderInfo ->getId();
        $order = $this->order->load($orderId);

        $result = $this->sendPuOrder($order, true);
        if(!$result) {
            $this->sendTrOrder($order, true);
        }
    }
}
