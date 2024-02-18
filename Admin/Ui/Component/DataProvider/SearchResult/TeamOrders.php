<?php
namespace VNS\Admin\Ui\Component\DataProvider\SearchResult;

class TeamOrders extends \Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult
{
    
    protected function _initSelect()
    {
        parent::_initSelect();
		
		$this->getSelect()->joinLeft(
                    ['catalog_category_entity_varchar' => $this->getTable('catalog_category_entity_varchar')],
                    'main_table.team_portal = catalog_category_entity_varchar.entity_id',
                    ['value']
                );
				
		/*$this->getSelect()->joinLeft(
                    ['catalog_product_entity_varchar' => $this->getTable('catalog_product_entity_varchar')],
                    'main_table.entity_id = catalog_product_entity_varchar.entity_id',
                    ['value']
                );
		$this->getSelect()->joinLeft(
                    ['catalog_product_entity_decimal' => $this->getTable('catalog_product_entity_decimal')],
                    'main_table.entity_id = catalog_product_entity_decimal.entity_id',
                    ['value']
                );
			
		$this->getSelect()
            ->columns('catalog_product_entity_decimal.value AS price');
		
		$this->addFieldToFilter('catalog_product_entity_decimal.attribute_id', ['eq'=>75]);
		$this->addFieldToFilter('catalog_product_entity_varchar.attribute_id', ['eq'=>71]);*/
		
		/*$this->getSelect()
            ->columns('catalog_category_entity_varchar.value AS team_name');*/
			
		$this->addFieldToFilter('catalog_category_entity_varchar.attribute_id', ['eq'=>33]);
		$this->addFieldToFilter('main_table.team_portal', ['neq'=>null]);
        return $this;
    }
}