<?php

namespace VNS\Admin\Controller\Adminhtml\Setshipping;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

class Index extends \Magento\Backend\App\Action
{
	
	public function __construct(
	    Context $context,
	    \Magento\Catalog\Model\ProductRepository $productRepository,
	    \Magento\Framework\App\RequestInterface $request,
	    ResultFactory $resultFactory,
	    \Magento\Framework\App\Request\Http $httpRequest,
	    \Magento\Framework\Message\ManagerInterface $messageManager,
	    Filter $filter,	    
	    CollectionFactory $collectionFactory
	)
	{
		parent::__construct($context);
		$this->productRepository = $productRepository;
		$this->request = $request;
		$this->resultFactory = $resultFactory;
		$this->httpRequest = $httpRequest;
		$this->messageManager = $messageManager;	
		$this->filter = $filter;
		$this->collectionFactory = $collectionFactory;
	}
	
	public function execute()
	{
	    $orderCollection = $this->filter->getCollection($this->collectionFactory->create());
	    
	    foreach($orderCollection as $order) {
    	    /*$post = $this->getRequest()->getPostValue();
    	    $id = $this->request->getParam('id');
    	    $order = $this->order->load($id);*/
    	    $order->setShippingMethod('ups_03');
    	    $order->save();
	    }
	    
	    $this->messageManager->addSuccess(__("Orders Successfuly Set to UPS"));
	    $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
	    $resultRedirect->setUrl($this->_redirect->getRefererUrl());
	    return $resultRedirect;
	}
}