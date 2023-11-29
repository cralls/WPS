<?php
namespace VNS\Fitment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Catalog\Model\ProductRepository;
use Psr\Log\LoggerInterface;
Use Magento\Framework\App\ResourceConnection;
use VNS\Fitment\Logger\FitmentLogger;

class GenerateFitmentData extends Command
{
    protected $state;
    protected $productRepository;
    protected $logger;
    
    public function __construct(
        State $state,
        ProductRepository $productRepository,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        FitmentLogger $fitmentLogger
        ) {
            $this->state = $state;
            $this->productRepository = $productRepository;
            $this->logger = $fitmentLogger;
            $this->resourceConnection = $resourceConnection;
            parent::__construct();
    }
    
    protected function configure()
    {
        $this->setName('vns:fitment')
        ->setDescription('Generate fitment data for products');
        parent::configure();
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        
        $connection = $this->resourceConnection->getConnection();
        $mapTable = $this->resourceConnection->getTableName('amasty_finder_map');
        $valueTable = $this->resourceConnection->getTableName('amasty_finder_value');
        
        $distinctSkus = $connection->fetchCol("SELECT DISTINCT sku FROM $mapTable");
        
        $latestSku = '429129';
        $processFlag = false;
        
        foreach ($distinctSkus as $sku) {
            
            if($sku == '1131-2283') {
                $this->logger->info("Found sku ".$sku."\r\n");
                $processFlag = true;
            }
            
            if(!$processFlag) {
                continue;
            }
            
            $fitmentData = [];
            
            $valueIds = $connection->fetchCol("SELECT value_id FROM $mapTable WHERE sku = :sku", ['sku' => $sku]);
            foreach ($valueIds as $valueId) {
                $fitmentRow = [];
                while ($valueId) {
                    $row = $connection->fetchRow("SELECT parent_id, name FROM $valueTable WHERE value_id = :valueId", ['valueId' => $valueId]);
                    $fitmentRow[] = $row['name'];
                    $valueId = $row['parent_id'];
                }
                
                if(!isset($fitmentRow[0]) || !isset($fitmentRow[1]) || !isset($fitmentRow[2])) continue;
                
                // Reverse to get Year, Make, Model in correct order
                $fitmentRow = array_reverse($fitmentRow);
                $year = $fitmentRow[0]; // Assuming 0 is Year
                $make = $fitmentRow[1]; // Assuming 1 is Make
                $model = $fitmentRow[2]; // Assuming 2 is Model
                
                $fitmentData[$make][$model][] = $year;
            }
            
            // Sort by Make and Model
            ksort($fitmentData);
            foreach ($fitmentData as $make => &$models) {
                ksort($models);
            }
            
            // Generate HTML table
            $htmlTable = '<table class="fitment-table"><tr><th>Year</th><th>Make</th><th>Model</th></tr>';
            foreach ($fitmentData as $make => $models) {
                foreach ($models as $model => $years) {
                    sort($years);
                    $yearRange = reset($years) . '-' . end($years);
                    $htmlTable .= "<tr><td>{$yearRange}</td><td>{$make}</td><td>{$model}</td></tr>";
                }
            }
            $htmlTable .= '</table>';
            
            // Save to product
            try {
                $product = $this->productRepository->get($sku);
                $product->setCustomAttribute('fitment', $htmlTable);
                $this->productRepository->save($product);
                
                echo "Fitment data generated for SKU: $sku\r\n";
                $this->logger->info("Fitment data generated for SKU: $sku");
            } catch (\Exception $e) {
                //echo "Error processing SKU $sku: " . $e->getMessage()."\r\n";
                $this->logger->info("Error processing SKU $sku: " . $e->getMessage());
            }
        }
        
        $this->logger->info("Fitment data generation complete.");
    }
    
    
    
}
