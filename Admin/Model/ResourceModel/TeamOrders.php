<?php

namespace VNS\Admin\Model\ResourceModel;

/**
 * Class Sendtopowder
 * @package VNS\Admin\Model\ResourceModel
 */
class TeamOrders extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    const TABLE_PRODUCT = 'sales_order';

    /**
     * Model Initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::TABLE_PRODUCT, 'entity_id');
    }
}