<?php

namespace VNS\Admin\Controller\Adminhtml\Orders;

use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Dompdf\Dompdf;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use VNS\Admin\Picqer\Barcode\BarcodeGeneratorPNG;

class PrintLabels extends \Magento\Backend\App\Action
{
	
    protected $resultPageFactory = false;
    
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Catalog\Model\Product $product,
        Filter $filter,
        ResultFactory $result,
		CollectionFactory $collectionFactory,
		Dompdf $dompdf,
        BarcodeGeneratorPNG $barcodeGenerator
        )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->product = $product;
        $this->result = $result;
        $this->filter = $filter;
		$this->dompdf = $dompdf;
		$this->collectionFactory = $collectionFactory;
		$this->barcodeGenerator = $barcodeGenerator;
    }
	
	public function execute()
	{
        $orderCollection = $this->filter->getCollection($this->collectionFactory->create());
        
        $htmlData = '<style type="text/css">
							div { font-family: Arial; font-size: 10px; }
                            td { padding: 5px 10px; border: 1px solid black; }
							.ldiv { border: 1px solid black; display: inline-block; padding: 3px; width: 88px; height: 32px; vertical-align: top; }
							.rdiv { border: 1px solid black; display: inline-block; padding: 3px; width: 144px; height: 32px; vertical-align: top; }
                            .lrdiv { width: 232px; }
                            .rdiv span {}
							.wdiv { width: 236px; font-size: 14px; font-weight: bold; text-align: center; border: 1px solid black; padding: 5px; }
							.bbdiv { display: inline-block; border: 1px solid black; height: 100px; }
							.bdiv { display: inline-block; border: 1px solid black; text-align: center; padding-left: 25px; width: 221px;  }
							.idiv { display: inline-block; padding: 5px; width: 90px; text-align: center; margin-top: 3px; font-size: 14px; }
							.ddiv { display: inline-block; border: 2px solid black; width: 100px; height: 50px; font-size: 28px; text-align: center; margin-bottom: 5px; }
							.img { max-width: 180px; }
                            .imageDiv { display: inline-block; max-height: 150px; width: 80px; padding-left: 2px; }
                            .page {page-break-after: always;}
                            .rotate {transform: rotate(90deg);}
                        </style>'; 
        $heartImage = '<img src="https://www.rocketsciencesports.com/media/wysiwyg/rss-heart.jpg" class="img"/><br>';
        
        //$htmlData .= "<div class='page'>";
		//$htmlData .= "<div style='width: 700px;'>";			
		
		$it = 0;
		$p = 0;
		
		foreach($orderCollection as $order) {
			$items = $order->getAllVisibleItems ();
			foreach ( $items as $itemId => $item ) {
			    
			    $product = $this->product->load($item->getProductId());
			    
				$pid = $product->getSku();
				$barcodeFilePath = getcwd() . '/barcodes/image' . $pid . '.png';
				if (!file_exists($barcodeFilePath)) {
				    // Generate the barcode and save it to a file
				    $barcodeData = $this->barcodeGenerator->getBarcode($item->getSku(), $this->barcodeGenerator::TYPE_CODE_128);
				    file_put_contents($barcodeFilePath, $barcodeData);
				}
				
				$barcode = 'https://' . $_SERVER['HTTP_HOST'] . '/barcodes/image' . $pid . '.png';
				
				$options = $item->getProductOptions ();
				if($p == 8) {
				    $p = 0;
				    $it = 0;
				    //$htmlData .= "</div></div><div class='page'><div style='width: 700px;'>";
				} elseif($it == 2) {
						$it = 0;
						//$htmlData .= "</div><div style='width: 700px;'>";
				}
				
				
				
				$size = '&nbsp;';
				$gender = '&nbsp;';
				if(isset($options['attributes_info'])) {
					foreach ( $options ['attributes_info'] as $attribute ) {
					    if(strpos(strtolower($attribute ['label']), "size") !== false) $size = isset($attribute ['value']) ? $attribute ['value'] : '&nbsp;';
					    if(strpos(strtolower($attribute ['label']), "gender") !== false) $gender = isset($attribute ['value']) ? $attribute ['value'] : '&nbsp;';
					}
				}
				if(isset($options['options'])) {
					foreach ( $options ['options'] as $attribute ) {
					    if(strpos(strtolower($attribute ['label']), "size") !== false) $size = isset($attribute ['value']) ? $attribute ['value'] : '&nbsp;';
					    if(strpos(strtolower($attribute ['label']), "gender") !== false) $gender = isset($attribute ['value']) ? $attribute ['value'] : '&nbsp;';
					}
				}
				
				$extraStyle = strlen($size) > 7 ? 'style="font-size: 18px;"' : '';
				
				$genders = [5567=>'Men',5568=>'Women',5569=>'Boys',5570=>'Girls',5571=>'Unisex'];
				$gender = $product->getGender() != '' ? $genders[$product->getGender()] : '&nbsp;';
				
				// Left Data
				$billingAddress = $order->getBillingAddress();
				
				$htmlData .= "<div class='page'>";
				
				$htmlData .= "<div class='rotate'>";
				$htmlData .= "<div style='width: 460px; margin-bottom: 10px;'>";
				
				// Images
				$htmlData .= "<div style='float: right; text-align: center; width: 200px; height: 265px;'><img src='http://".$_SERVER['HTTP_HOST']."/barcodes/rss-logo.png' style='width: 182px; margin-top: 10px;'><br>";
				$htmlData .= "<img src='http://".$_SERVER['HTTP_HOST']."/media/catalog/product".$product->getImage()."' class='img'></div>";
				
				$htmlData .= "<div class='lrdiv'><div class='ldiv'><span>ORDER NUMBER:</span></div><div class='rdiv'><span style='font-weight: bold; font-size: 14px;'>".$order->getIncrementId()."</span></div></div>";
				$htmlData .= "<div class='lrdiv'><div class='ldiv'><span>PART NUMBER:<span></div><div class='rdiv'><span style='font-weight: bold; font-size: 14px;'>".$product->getSku()."</span></div></div>";
				$htmlData .= "<div class='lrdiv'><div class='ldiv'><span>DESCRIPTION:</span></div><div class='rdiv'><span>".$item->getName()."</span></div></div>";
				$htmlData .= "<div class='wdiv'>".$billingAddress->getFirstName()." ".$billingAddress->getLastName()."</div>";
				$htmlData .= "<div class='bdiv'><div class='idiv'>GENDER</div><div class='idiv'>SIZE</div><br><div class='ddiv'>".$gender."</div><div class='ddiv' ".$extraStyle.">".$size."</div></div>";
				$htmlData .= "<div class='wdiv' style='border: 0px;'><img src='".$barcode."' style='height: 40px; max-width: 240px;'/></div>";
				$htmlData .= "<div class='wdiv' style='border: 0px;'>".$product->getSrNumber()."</div>";
				$htmlData .= "</div>";
				
				//$htmlData .= "<div style='display: inline-block; text-align: center;'><img src='https://www.rocketsciencesports.com/barcodes/rss-logo.png' style='width: 100px;'></div>";
                //$htmlData .= "<img src='https://www.rocketsciencesports.com/media/catalog/product".$product->getImage()."' class='img'></div>";
				
				$htmlData .= "</div>";
				$htmlData .= "</div>";
				$it++;
				$p++;
			}
		}
			
		//$htmlData .= "</div></div>";
        
		//echo $htmlData; die();
		
		$customPaper = array(0,0,384,576);
		$this->dompdf->setPaper('A6', 'portrait');
        $this->dompdf->set_option('isRemoteEnabled', 'true');
        $this->dompdf->loadHtml($htmlData);
        $this->dompdf->render();
		
		//$output = $dompdf->output();
		//file_put_contents(getcwd().'/labels/'.date('Y-m-d.H.i.s').'-labels.pdf', $output);
		
        $this->dompdf->stream();
        
        
        //$resultRedirect = $this->result->create(ResultFactory::TYPE_REDIRECT);
        //$resultRedirect->setUrl($this->_redirect->getRefererUrl());
        //return $resultRedirect;
	}
}
