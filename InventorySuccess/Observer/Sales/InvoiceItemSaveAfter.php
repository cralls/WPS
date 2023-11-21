<?php
/**
 * Copyright © 2019 Magestore. All rights reserved.
 * See COPYING.txt for license details.
 *
 */

namespace Magestore\InventorySuccess\Observer\Sales;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magestore\InventorySuccess\Api\Data\Warehouse\ProductInterface as WarehouseProductInterface;
use Magestore\InventorySuccess\Api\Db\QueryProcessorInterface;
use Magestore\InventorySuccess\Model\OrderProcess\StockMovementActivity\SalesVirtual as StockActivitySalesVirtual;
use Magestore\InventorySuccess\Model\ResourceModel\Warehouse\Product as WarehouseProductResource;


/**
 * Class InvoiceItemSaveAfter
 * @package Magestore\InventorySuccess\Observer\Sales
 */
class InvoiceItemSaveAfter extends \Magestore\InventorySuccess\Model\OrderProcess\OrderProcess implements ObserverInterface
{
    /**
     * Process Invoice Save After Event
     *
     * @param EventObserver $observer
     * @return $this
     */
    public function execute(EventObserver $observer)
    {
        $invoiceItem = $observer->getEvent()->getInvoiceItem();
        $orderItem = $invoiceItem->getOrderItem();
        if ($orderItem->getIsVirtual()) {
            $this->queryProcess->start($this->process);
            $skipWarehouse = false;
            $this->eventManager->dispatch('inventorysuccess_before_subtract_qty_to_ship_when_create_shipment_warehouse', [
                'skip_warehouse' => &$skipWarehouse
            ]);
            /* skip subtract qty_to_ship in ordered warehouse by shipped qty */
            if(!$skipWarehouse) {
                /* subtract qty_to_ship in ordered Warehouse by shipped qty*/
                // only subtract qty to ship when config "Decrease Stock When Order is Placed" is Yes
                if($this->inventoryHelper->getStoreConfig('cataloginventory/options/can_subtract')) {
                    $this->_subtractQtyToShipInOrderWarehouse($invoiceItem);
                }
                $this->queryProcess->process($this->process);
            }
            $products = [$invoiceItem->getProductId() => $invoiceItem->getQty()];
            $issueWarehouseId = $orderItem->getWarehouseId();
            $this->stockChange->issue($issueWarehouseId, $products, StockActivitySalesVirtual::STOCK_MOVEMENT_ACTION_CODE, $invoiceItem->getInvoice()->getId(), true);
            if (!$this->isManageStock($orderItem)) {
                $this->resetAllStockItems($invoiceItem->getSku());
            }
        }
    }

    /**
     * Get warehouse to ship item
     *
     * @param string $sku
     * @return boolean
     */
    protected function resetAllStockItems($sku)
    {
        try {
            $product = $this->productRepository->get($sku);
            if ($product->getTypeId() == \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL) {
                $this->queryProcess->start();
                $query = [
                    'type' => QueryProcessorInterface::QUERY_TYPE_UPDATE,
                    'values' => ['qty' => 0, 'total_qty' => 0],
                    'condition' => ['product_id=?' => $product->getId()],
                    'table' => $this->warehouseStockRegistry->getResource()->getTable(WarehouseProductResource::MAIN_TABLE)
                ];
                $this->queryProcess->addQuery($query);
                $this->queryProcess->process();
            }
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * subtract qty_to_ship of product in ordered warehouse
     *
     * @param \Magento\Sales\Model\Order\Invoice\Item $item
     */
    protected function _subtractQtyToShipInOrderWarehouse($item)
    {
        $orderItem = $item->getOrderItem();

        if (!$this->isManageStock($orderItem))
            return $this;

        $dataEvent = new \Magento\Framework\DataObject(['is_increase_qty' => true]);
        $this->eventManager->dispatch('subtract_qty_to_ship_in_ordered_warehouse_before', ['data_event' => $dataEvent]);

        if($dataEvent->getData('is_increase_qty')) {
            $orderWarehouseId = $this->getOrderWarehouse($orderItem->getItemId());
            $qtyChanges = [WarehouseProductInterface::QTY_TO_SHIP => -$this->_getInvoicedQty($item)];
            $this->_updateQtyProcess($item, $orderItem, $orderWarehouseId, $qtyChanges);
        } else {
            // decrese qty to ship from ship warehouse when ship from fulfillment
            $shipmentWarehouseId = $item->getOrderItem()->getWarehouseId();
            $qtyChanges = [WarehouseProductInterface::QTY_TO_SHIP => -$this->_getInvoicedQty($item)];
            $this->_updateQtyProcess($item, $orderItem, $shipmentWarehouseId, $qtyChanges);
        }
    }

    /**
     * Get invoiced qty
     *
     * @param \Magento\Sales\Model\Order\Invoice\Item $item
     * @return float
     */
    protected function _getInvoicedQty($item)
    {
        return $item->getQty();
    }

    /**
     * @param \Magento\Sales\Model\Order\Invoice\Item $item
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @param int $orderWarehouseId
     * @param array $qtyChanges
     */
    protected function _updateQtyProcess($item, $orderItem, $orderWarehouseId, $qtyChanges){
        $dataEvent = new \Magento\Framework\DataObject(['is_increase_qty' => true]);
        $this->eventManager->dispatch('subtract_qty_to_ship_in_ordered_warehouse_before', ['data_event' => $dataEvent]);

        if($dataEvent->getData('is_increase_qty')) {
            /* increase available_qty in ordered warehouse  */
            $queries = $this->warehouseStockRegistry
                ->prepareChangeProductQty($orderWarehouseId, $orderItem->getProductId(), $qtyChanges);
            foreach ($queries as $query)
                $this->queryProcess->addQuery($query, $this->process);
        } else {
            // when run with fulfill
            // increase available qty on shipped warehouse
            $queries = $this->warehouseStockRegistry
                ->prepareChangeProductQty($item->getOrderItem()->getWarehouseId(), $orderItem->getProductId(), $qtyChanges);
            foreach ($queries as $query)
                $this->queryProcess->addQuery($query, $this->process);
        }

        /* increase available_qty in global stock */
        $queries = $this->warehouseStockRegistry
            ->prepareChangeProductQty(WarehouseProductInterface::DEFAULT_SCOPE_ID, $orderItem->getProductId(), $qtyChanges);
        foreach ($queries as $query)
            $this->queryProcess->addQuery($query, $this->process);
    }
}