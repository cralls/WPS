<?php

namespace VNS\Admin\Controller\Adminhtml\Orders;

use Magento\Backend\App\Action\Context;

class Index extends \Magento\Backend\App\Action
{
	
    protected $resultPageFactory = false;
    
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
        )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }
	
	public function execute()
	{
	    $resultPage = $this->resultPageFactory->create();
	    $resultPage->getConfig()->getTitle()->prepend((__('Team Portal Orders')));
	    
	    return $resultPage;
	}
}