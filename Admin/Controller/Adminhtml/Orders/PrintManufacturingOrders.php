<?php

namespace VNS\Admin\Controller\Adminhtml\Orders;

use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Dompdf\Dompdf;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

class PrintManufacturingOrders extends \Magento\Backend\App\Action
{
	
    protected $resultPageFactory = false;
    
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Catalog\Model\Product $product,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        Filter $filter,
        ResultFactory $result,
		CollectionFactory $collectionFactory
        )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->product = $product;
        $this->productRepository = $productRepository;
        $this->result = $result;
        $this->filter = $filter;
		$this->collectionFactory = $collectionFactory;
    }
	
    public function execute()
    {
        ini_set('error_reporting', E_ALL);
        
        ob_start();
        
        $orderCollection = $this->filter->getCollection($this->collectionFactory->create());
        
        $teamOrders = array();        
        $customOptionsHeaders = [];
        foreach ($orderCollection as $order) {
            $i = $order->getIncrementId();
            $items = $order->getAllVisibleItems();
            $it = 0;
            $billingAddress = $order->getBillingAddress();
            foreach ($items as $itemId => $item) {
                $customName = '';
                $productId = $item->getProductId();
                
                try {
                    $product = $this->productRepository->getById($productId);
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    // If product does not exist, skip to next item
                    continue;
                }
                
                
                $options = $item->getProductOptions();
                $sku = $item->getSku();
                
                $teamOrders[$i . "-" . $it]['po'] = $order->getIncrementId();
                $teamOrders[$i . "-" . $it]['billing_name'] = $billingAddress->getFirstName() . " " . $billingAddress->getLastName();
                $teamOrders[$i . "-" . $it]['item_name'] = $item->getName();
                $teamOrders[$i . "-" . $it]['size'] = '';
                
                if(isset($options['attributes_info'])) {
                    foreach ( $options ['attributes_info'] as $attribute ) {
                        if(strpos(strtolower($attribute ['label']), "size") !== false) $teamOrders [$i . "-" . $it] ['size'] = $attribute ['value'];
                        if(strpos(strtolower($attribute ['label']), "name") !== false) $customName = $attribute ['value'];
                    }
                }
                if(isset($options['options'])) {
                    foreach ( $options ['options'] as $attribute ) {
                        if(strpos(strtolower($attribute ['label']), "size") !== false) $teamOrders [$i . "-" . $it] ['size'] = $attribute ['value'];
                        if(strpos(strtolower($attribute ['label']), "name") !== false) $customName = $attribute ['value'];
                    }
                }
                
                $teamOrders[$i . "-" . $it]['sku'] = $sku;
                $teamOrders[$i . "-" . $it]['qty'] = number_format($item->getData('qty_ordered'), 0);
                //$teamOrders[$i . "-" . $it]['custom_name'] = $customName;
                
                // Get customizable options from product
                $customOptions = $this->getCustomizableOptions($item, $i . "-" . $it);
                //$teamOrders = array_merge($teamOrders[$i . "-" . $it], $customOptions);
                
                // Add customizable options to header and teamOrders
                foreach ($customOptions as $key => $optionName) {
                    if (!in_array($key, $customOptionsHeaders)) {
                        $customOptionsHeaders[$key] = $key;
                    }
                    $teamOrders[$i . "-" . $it][$key] = $optionName;
                }
                $it++;
            }
            $i++;
        }
        
        ob_end_clean();
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=data.csv');
        
        $file = fopen ( "php://output", "w" );
        
        array_multisort( array_column($teamOrders, "item_name"), SORT_ASC, $teamOrders );
        
        $teamOrdersHead = [];
        $teamOrdersHead [0] ['po'] = 'PO#';
        $teamOrdersHead [0] ['billing_name'] = 'Billing Name';
        $teamOrdersHead [0] ['item_name'] = 'Item Name';
        $teamOrdersHead [0] ['sku'] = 'SKU';
        $teamOrdersHead [0] ['size'] = 'Size';
        $teamOrdersHead [0] ['qty'] = 'QTY';
        //$teamOrdersHead [0] ['custom_name'] = 'Custom Name';
        
        // Add $customOptionsHeaders to teamOrdersHead
        foreach($customOptionsHeaders as $key => $customOptionsHeader) {
            $teamOrdersHead [0] [$key] = $key;
        }
        //ksort($teamOrdersHead[0]);
        
        //Loop through teamOrders and make sure each order has all the values for each customOptionHeader
        /*foreach($teamOrders as $key => $teamOrder) {
            $missingKeys = array_diff_key($customOptionsHeaders, $teamOrder);
            foreach($missingKeys as $missingKey) {
                $teamOrders[$key][$missingKey] = '';
            }
            //ksort($teamOrders[$key]); // Sort by key so all columns align in output
        }*/
        
        $headerKeys = array_keys($teamOrdersHead[0]);
        
        // Sort $teamOrders according to $headerKeys
        foreach ($teamOrders as &$order) {
            $sortedOrder = [];
            foreach ($headerKeys as $key) {
                // Check if the key exists in the current order, if not set it to an empty string or a default value
                $sortedOrder[$key] = array_key_exists($key, $order) ? $order[$key] : '';
            }
            $order = $sortedOrder;
        }
        unset($order);
        
        //print_r($teamOrders); die();
        
        // Add final header to output
        $teamOrders = array_merge($teamOrdersHead, $teamOrders);
        
        foreach ($teamOrders as $line) {
            fputcsv($file, $line);
        }
        
        fclose($file);
        
        //$resultRedirect = $this->result->create(ResultFactory::TYPE_REDIRECT);
        //$resultRedirect->setUrl($this->_redirect->getRefererUrl());
        //return $resultRedirect;
    }
    
    protected function getCustomizableOptions($item, $key)
    {
        $options = [];
        $productOptions = $item->getProductOptions();
        
        if (isset($productOptions['options'])) {
            foreach ($productOptions['options'] as $option) {
                $options[strtolower(str_replace(" ", "_", trim($option['label'])))] = $option['value'];
            }
        }
        
        return $options;
    }
    
    public function oldExecute()
    {
        $orderCollection = $this->filter->getCollection($this->collectionFactory->create());
        
        $teamOrders = array ();
        
        foreach($orderCollection as $order) {
            $i = $order->getIncrementId();
            $items = $order->getAllVisibleItems ();
            $it = 0;
            $billingAddress = $order->getBillingAddress();
            foreach ( $items as $itemId => $item ) {
                $customName = '';
                $id = $item->getProductId ();
                $options = $item->getProductOptions ();
                $sku = $item->getSku ();
                
                $teamOrders [$i . "-" . $it] ['po'] = $order->getIncrementId();
                $teamOrders [$i . "-" . $it] ['billing_name'] = $billingAddress->getFirstName()." ".$billingAddress->getLastName();
                $teamOrders [$i . "-" . $it] ['item_name'] = $item->getName ();
                $teamOrders [$i . "-" . $it] ['size'] = '';
                
                if(isset($options['attributes_info'])) {
                    foreach ( $options ['attributes_info'] as $attribute ) {
                        if(strpos(strtolower($attribute ['label']), "size") !== false) $teamOrders [$i . "-" . $it] ['size'] = $attribute ['value'];
                        if(strpos(strtolower($attribute ['label']), "name") !== false) $customName = $attribute ['value'];
                    }
                }
                if(isset($options['options'])) {
                    foreach ( $options ['options'] as $attribute ) {
                        if(strpos(strtolower($attribute ['label']), "size") !== false) $teamOrders [$i . "-" . $it] ['size'] = $attribute ['value'];
                        if(strpos(strtolower($attribute ['label']), "name") !== false) $customName = $attribute ['value'];
                    }
                }
                
                $teamOrders [$i . "-" . $it] ['sku'] = $sku;
                $teamOrders [$i . "-" . $it] ['qty'] = number_format ( $item->getData ( 'qty_ordered' ), 0 );
                $teamOrders [$i . "-" . $it] ['custom_name'] = $customName;
                $it ++;
            }
            $i ++;
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=data.csv');
        
        $file = fopen ( "php://output", "w" );
        
        array_multisort( array_column($teamOrders, "item_name"), SORT_ASC, $teamOrders );
        
        $teamOrdersHead [0] ['po'] = 'PO#';
        $teamOrdersHead [0] ['billing_name'] = 'Billing Name';
        $teamOrdersHead [0] ['item_name'] = 'Item Name';
        $teamOrdersHead [0] ['size'] = 'Size';
        $teamOrdersHead [0] ['sku'] = 'SKU';
        $teamOrdersHead [0] ['qty'] = 'QTY';
        $teamOrdersHead [0] ['custom_name'] = 'Custom Name';
        
        $teamOrders = array_merge($teamOrdersHead, $teamOrders);
        
        foreach ( $teamOrders as $line ) {
            fputcsv ( $file, $line );
        }
        
        $resultRedirect = $this->result->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        return $resultRedirect;
    }
    
}