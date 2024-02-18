<?php

namespace VNS\Admin\Controller\Adminhtml\Orders;

use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Dompdf\Dompdf;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

class PrintOrders extends \Magento\Backend\App\Action
{
	
    protected $resultPageFactory = false;
    
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Catalog\Model\Product $product,
        Filter $filter,
        ResultFactory $result,
		CollectionFactory $collectionFactory
        )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->product = $product;
        $this->result = $result;
        $this->filter = $filter;
		$this->collectionFactory = $collectionFactory;
    }
	
	public function execute()
	{
		$orderCollection = $this->filter->getCollection($this->collectionFactory->create());
        
        $teamOrders = array ();
		$teamOrders [0] ['orderId'] = 'ORDER NUMBER';
		$teamOrders [0] ['purchase_date'] = 'PURCHASE DATE';
		$teamOrders [0] ['name'] = 'CUSTOMER';
		$teamOrders [0] ['description'] = 'DESCRIPTION';
		$teamOrders [0] ['part_number'] = 'PART NUMBER';
		$teamOrders [0] ['qty'] = 'QUANTITY';
		$teamOrders [0] ['price'] = 'PRICE';
		$teamOrders [0] ['email'] = 'EMAIL';

		foreach($orderCollection as $order) {
			$i = $order->getIncrementId();
			$items = $order->getAllVisibleItems ();
			$it = 0;
			$billingAddress = $order->getBillingAddress();
			foreach ( $items as $itemId => $item ) {
				$id = $item->getProductId ();
				$options = $item->getProductOptions ();
				$sku = $item->getSku ();
				if ($it > 0) {
					$teamOrders [$i . "-" . $it] ['orderId'] = '';
					$teamOrders [$i . "-" . $it] ['purchase_date'] = '';
					$teamOrders [$i . "-" . $it] ['name'] = '';
					$teamOrders [$i . "-" . $it] ['description'] = $item->getName ();
					$teamOrders [$i . "-" . $it] ['part_number'] = $sku;
					$teamOrders [$i . "-" . $it] ['qty'] = number_format ( $item->getData ( 'qty_ordered' ), 0 );
					$teamOrders [$i . "-" . $it] ['price'] = number_format ( $item->getPrice (), 2 );
					$teamOrders [$i . "-" . $it] ['email'] = $order->getCustomerEmail ();
					if(isset($options['attributes_info'])) {
						foreach ( $options ['attributes_info'] as $attribute ) {
							$it ++;
							$teamOrders [$i . "-" . $it] ['orderId'] = '';
							$teamOrders [$i . "-" . $it] ['purchase_date'] = '';
							$teamOrders [$i . "-" . $it] ['name'] = '';
							$teamOrders [$i . "-" . $it] ['description'] = $attribute ['label'] . ": " . $attribute ['value'];
						}
					}
					if(isset($options['options'])) {
						foreach ( $options ['options'] as $attribute ) {
							$it ++;
							$teamOrders [$i . "-" . $it] ['orderId'] = '';
							$teamOrders [$i . "-" . $it] ['purchase_date'] = '';
							$teamOrders [$i . "-" . $it] ['name'] = '';
							$teamOrders [$i . "-" . $it] ['description'] = $attribute ['label'] . ": " . $attribute ['value'];
						}
					}
				} else {
					$teamOrders [$i . "-" . $it] ['orderId'] = $order->getIncrementId();
					$teamOrders [$i . "-" . $it] ['purchase_date'] = $order->getCreatedAt ();
					$teamOrders [$i . "-" . $it] ['name'] = $billingAddress->getFirstName()." ".$billingAddress->getLastName();
					$teamOrders [$i . "-" . $it] ['description'] = $item->getName ();
					$teamOrders [$i . "-" . $it] ['part_number'] = $sku;
					$teamOrders [$i . "-" . $it] ['qty'] = number_format ( $item->getData ( 'qty_ordered' ), 0 );
					$teamOrders [$i . "-" . $it] ['price'] = number_format ( $item->getPrice (), 2 );
					$teamOrders [$i . "-" . $it] ['email'] = $order->getCustomerEmail ();
					if(isset($options['attributes_info'])) {
						foreach ( $options ['attributes_info'] as $attribute ) {
							$it ++;
							$teamOrders [$i . "-" . $it] ['orderId'] = '';
							$teamOrders [$i . "-" . $it] ['purchase_date'] = '';
							$teamOrders [$i . "-" . $it] ['name'] = '';
							$teamOrders [$i . "-" . $it] ['description'] = $attribute ['label'] . ": " . $attribute ['value'];
						}
					}
					if(isset($options['options'])) {
						foreach ( $options ['options'] as $attribute ) {
							$it ++;
							$teamOrders [$i . "-" . $it] ['orderId'] = '';
							$teamOrders [$i . "-" . $it] ['purchase_date'] = '';
							$teamOrders [$i . "-" . $it] ['name'] = '';
							$teamOrders [$i . "-" . $it] ['description'] = $attribute ['label'] . ": " . $attribute ['value'];
						}
					}
				}
				$it ++;
			}
			$i ++;
		}
		
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=data.csv');
		
		$file = fopen ( "php://output", "w" );
		
		foreach ( $teamOrders as $line ) {
			fputcsv ( $file, $line );
		}
		
		//fseek($file, 0);
		//header('Content-Type: text/csv; charset=utf-8');
		//header('Content-Disposition: attachment; filename=data.csv');
		//fpassthru($file);
        
        $resultRedirect = $this->result->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        return $resultRedirect;
	}
}