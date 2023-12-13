<?php

namespace VNS\Custom\Controller\Adminhtml\Resend;

use Magento\Framework\Controller\ResultFactory;

class Index extends \Magento\Backend\App\Action
{
	protected $resultPageFactory = false;
	protected $order;
	
	public function __construct(
	    \Magento\Sales\Model\Order $order,
	    \Magento\Directory\Model\RegionFactory $regionFactory,
	    \Magento\Catalog\Model\ProductRepository $productRepository,
	    \Magestore\SupplierSuccess\Model\ResourceModel\Product\Supplier\Collection $supplierCollection,
		\Magento\Backend\App\Action\Context $context,
		\Magento\Framework\View\Result\PageFactory $resultPageFactory,
	    \Magento\Framework\Message\ManagerInterface $messageManager,
	    \Magento\Framework\App\RequestInterface $request
	)
	{
		parent::__construct($context);
		$this->resultPageFactory = $resultPageFactory;
		$this->order = $order;
		$this->regionFactory = $regionFactory;
		$this->productRepository = $productRepository;
		$this->supplierCollection = $supplierCollection;
		$this->messageManager = $messageManager;
		$this->request = $request;
	}
	
	public function execute()
	{
	    $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
	    $resultRedirect->setUrl($this->_redirect->getRefererUrl());
	    
	    $order = $this->order->load($this->request->getParam('id'));
	    
	    if($order->getStatus() == 'pending') return $resultRedirect;
	    
	    $result = $this->resendPu($order);
	    if(!$result) {
	       $this->resendTr($order);
	    }
	    
	    // Redirect to referer
	    return $resultRedirect;
	}
	
	function resendPu($order, $resend = false) {
	    // TODO CHeck if order is in processing state
	    
	    //get Order All Item
	    $itemCollection = $order->getItemsCollection();
	    $shippingAddress = $order->getShippingAddress();
	    $shippingStreet = $shippingAddress->getStreet();
	    $region = $this->regionFactory->create()->load($shippingAddress->getRegionId())->getCode();
	    
	    
	    /***************************************** DON'T FORGET TO ONLY SEND NEEDED PRODUCTS *********************/
	    if (in_array($order->getIncrementId(), [1000035566])) {
	        $orderNumber = $order->getIncrementId().'_2';
	    } else {
	        $orderNumber = $order->getIncrementId();
	    }
	    /***************************************** DON'T FORGET TO ONLY SEND NEEDED PRODUCTS *********************/
	    
	    $requestData = array();
	    $requestData['dealer_number'] = 'HAP019';
	    $requestData['order_type'] = "DS";
	    $requestData['purchase_order_number'] = $orderNumber;
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
	    $pu = false;
	    $npu = false;
	    $dropShippedItems = array();
	    foreach($itemCollection as $item) {
	        if($item->getDropshipped() == 1) continue;
	        if($item->getProductType() == 'bundle') continue;
	        $suppliers = $this->supplierCollection->addFieldToFilter('product_id', array('eq'=>$item->getProductId()));
	        $this->supplierCollection->load();
	        $productSupplierSku = '';
	        if(count($suppliers) > 0) {
	            foreach($suppliers as $supplier) {
	                if($supplier->getSupplierId() == '69') {
	                    $productSupplierSku = $supplier->getProductSupplierSku();
	                    $pu = true;
	                    break;
	                } else {
	                    if($item->getProductType() == 'bundle') continue;
	                    $npu = true;
	                }
	            }
	        } else {
	            $npu = true;
	        }
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
	    
	    // Log result
	    error_log(date('Y-m-d H:i:s')." - RESEND REQUEST ".print_r($requestData, 1)."\r\n", 3, '/home/happytrail/public_html/var/log/pu-orders.log');
	    
	    $stageUrl = "https://api.parts-unlimited.com/api/orders/dropship";
	    $ch = curl_init($stageUrl);
	    $payload = json_encode($requestData);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	    //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json','api-key:8KT9MXT-1PG40D7-JCBA64X-YQTMCHB')); // Staging
	    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json','api-key:TGZHK1S-NTW4KBQ-M4XX36N-0FDFTY5'));
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $result = curl_exec($ch);
	    $ch_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	    curl_close($ch);
	    
	    // Log result
	    error_log(date('Y-m-d H:i:s')." - RESEND RESPONSE ".print_r($result, 1)."\r\n", 3, '/home/happytrail/public_html/var/log/pu-orders.log');
	    
	    $decodedResult = json_decode($result);
	    
	    // Send message back to resend script
	    if($resend) {
	        //return "Status was ".$decodedResult->status_message;
	        return "Status for code ".$ch_code." was ".$result;
	    }
	    
	    if(isset($decodedResult->mainframeErrors)) {
	        $this->messageManager->addError('Sending order to PU Failed: '.$decodedResult->mainframeErrors[0]->text);
	        return false;
	    } elseif($decodedResult->status_code != '200' && $decodedResult->status_code != '202') {
	        $this->messageManager->addError('Sending order to PU Failed. '.$decodedResult->status_message);
	        mail('cralls@vectorns.com', 'Dropship Order Failed for '.$order->getIncrementId(), $payload."\n\n".$result);
	        return false;
	    } else {
	        // Set order status
	        if($pu && $npu) {
	            $orderStatus = 'processing_pu_';
	        } elseif($pu) {
	            $orderStatus = 'processing_pu';
	        }
	        $order->setStatus($orderStatus);
	        
	        // Update each order item as dropshipped
	        foreach($dropShippedItems as $dropShippedItem) {
	            $dropShippedItem->setDropshipped(1);
	            $dropShippedItem->save();
	        }
	        $order->save();
	        $this->messageManager->addSuccess(__('Order sent to PU successfully'));
	    }
	    return true;
	}

	public function resendTr($order, $thirdParty = false) {
	    // TODO CHeck if order is in processing state
	    
	    //get Order All Item
	    $itemCollection = $order->getItemsCollection();
	    $shippingAddress = $order->getShippingAddress();
	    $shippingStreet = $shippingAddress->getStreet();
	    $billingAddress = $order->getBillingAddress();
	    $billingStreet = $billingAddress->getStreet();
	    
	    
	    $region = $this->regionFactory->create()->load($shippingAddress->getRegionId())->getCode();
	  

	    // NO DUPLICATE PO's 
	    $orderId = $order->getIncrementId() == '1000030761' ? '1000030761_2' : $order->getIncrementId(); 
	    
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
	    $requestData['po'] = $orderId;
	    $requestData['allowduplicatepo'] = 'NO';
	    $requestData['carrier'] = 'UG';
	    $requestData['splitlines'] = 'YES';
	    $requestData['submit'] = 'YES';
	    //$requestData['location'] = '001';
	    
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
	                    if($item->getProductType() == 'bundle') continue;
	                    $npu = true;
	                }
	            }
	        } else {
	            if($item->getProductType() == 'bundle') continue;
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
	    
	    /*****
	     * A way to hold all PU orders and notify to add to manual PO
	     *****/
	    //mail('cralls@vectorns.com', 'Dropship Order for '.$order->getIncrementId(), 'Add to WMCA-1000018972');
	    //return true;
	    
	    error_log(date('Y-m-d H:i:s')." - REQUEST DATA ".print_r($requestData, 1)."\r\n", 3, '/home/happytrail/public_html/var/log/tr-orders.log');
	    
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
	    error_log(date('Y-m-d H:i:s')." - ".print_r($result, 1)."\r\n", 3, '/home/happytrail/public_html/var/log/tr-orders.log');
	    
	    $decodedResult = json_decode($result);
	    
	    if($decodedResult->ORD->status != 'SUCCESSFUL') {
	        $errorMsg = $decodedResult->ORD->errormsg." - ";
	        foreach($decodedResult->ORD->orderinput->orderline as $orderline) {
	            $errorMsg .= $orderline->errormsg." ";
	        }
	        mail('cralls@vectorns.com', 'TR Dropship Order Failed for '.$order->getIncrementId(), $errorMsg);
	        $this->messageManager->addError('Sending order to TR Failed. '.$errorMsg);
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
	        $this->messageManager->addSuccess(__('Order sent to Tucker Rocky successfully'));
	    }
	    return true;
	}

}
