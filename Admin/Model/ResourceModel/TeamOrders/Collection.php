<?php

namespace VNS\Admin\Model\ResourceModel\TeamOrders;

/**
 * Class Collection
 * @package VNS\Admin\Model\ResourceModel\TeamOrders
 */
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';
    
    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('\VNS\Admin\Model\TeamOrders', '\VNS\Admin\Model\ResourceModel\TeamOrders');
        parent::_construct();
    }
    
    /*public function __construct(
        \Magento\Framework\Data\Collection\EntityFactoryInterface $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null
        ) {
            $this->_eventManager = $eventManager;
            parent::__construct($entityFactory, $logger, $fetchStrategy, $connection);
            $this->_construct();
            $this->_resource = $resource;
            $this->setConnection($this->getResource()->getConnection());
            $this->_initSelect();
    }*/
}