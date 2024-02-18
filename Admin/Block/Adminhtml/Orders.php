<?php
namespace VNS\Admin\Block\Adminhtml;

class Orders extends \Magento\Backend\Block\Widget\Grid\Container
{

	protected function _construct()
	{
		$this->_controller = 'adminhtml_orders';
		$this->_blockGroup = 'VNS_Admin';
		$this->_headerText = __('Team Portals');
		parent::_construct();
		$this->buttonList->remove('add');
	}
}