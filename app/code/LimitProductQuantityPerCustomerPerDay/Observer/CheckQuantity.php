<?php

namespace NSTech\LimitProductQuantityPerCustomerPerDay\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Checkout\Model\Cart;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Catalog\Model\Product;
use \Magento\Customer\Model\Session as customerSession;
class CheckQuantity implements \Magento\Framework\Event\ObserverInterface
{

    protected $cart;
    protected $messageManager;
    protected $redirect;
    protected $request;
    protected $product;
    protected $customerSession;
    protected $_orderCollectionFactory;
    
    protected $orderedItems;
    protected $datetime;
    protected $_resource;
    protected $_productloader;  

    public function __construct(
        RedirectInterface $redirect,
        Cart $cart,
        ManagerInterface $messageManager,
        RequestInterface $request,
        Product $product,
       customerSession $session,
       \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
       \Magento\Framework\Stdlib\DateTime\DateTime $datetime,
       \Magento\Framework\App\ResourceConnection $resource,
       \Magento\Catalog\Model\ProductFactory $_productloader
    )
    {
    $this->redirect = $redirect;
    $this->cart = $cart;
    $this->messageManager = $messageManager;
    $this->request = $request;
    $this->product = $product;
    $this->customerSession = $session;
    $this->_orderCollectionFactory = $orderCollectionFactory;
    $this->datetime= $datetime;
    $this->_resource = $resource;
    $this->_productloader = $_productloader;
 }

 protected function getConnection()
 {
     
     return $this->_resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
 }

public function getOrdersByCustomerId($customerId,$productIds)
{
   $now = $this->datetime->timestamp();
  $start = gmdate("Y-m-d H:i:s", strtotime(date('Y-m-d' . ' 00:00:00', $now))) ;
  $end = gmdate("Y-m-d H:i:s", strtotime(date('Y-m-d' . ' 23:59:59', $now))) ;

 
        $orders = $this->_orderCollectionFactory->create()
        ->addFieldToSelect('*')
     
       
      ->addFieldToFilter(
        'customer_id',
        $customerId
    )->addFieldToFilter(
      'main_table.created_at', 
      array('from' => $start, 'to' => $end)
    );
    $orders->getSelect()
    ->join(
      'sales_order_item',
      'main_table.entity_id = sales_order_item.order_id'
  )->where( 'sales_order_item.product_id in (?)', $productIds );
 
     
    
    return $orders;
}
public function getLoadProduct($id)
{
    return $this->_productloader->create()->load($id);
}

	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		
        $postValues = $this->request->getPostValue();
         $cartItemsCount = $this->cart->getQuote()->getItemsCount();
        $items= $this->cart->getQuote()->getAllVisibleItems();
        $productIds= array();
        foreach($items as $item) {
        $productIds[] = $item->getProductId();
        }
      
      if($this->customerSession->isLoggedIn()) {
        $orders=  $this->getOrdersByCustomerId($this->customerSession->getCustomer()->getId(),$productIds);

      }
       foreach($items as $item) {
       $currentProduct = $this->getLoadProduct($item->getProductId());

        $currentEnable = false;
        if($currentProduct->getCustomAttribute('max_quota_per_customer_per_day_enable')!==null ){

          $currentEnable =$currentProduct->getCustomAttribute('max_quota_per_customer_per_day_enable')->getValue();
         
         }
         
        if(!$currentEnable){
            continue;
        }

        
       $availableQuota = 999999;
       if($currentProduct->getCustomAttribute('max_quota_per_customer_per_day')!==null ){

        $availableQuota= intval($currentProduct->getCustomAttribute('max_quota_per_customer_per_day')->getValue());

       }
       
       $remainingQuota = $availableQuota;
       if($orders!=null)
       foreach ($orders as $order){ 

        foreach($order->getAllItems() as $orderedItem) {
          if($orderedItem->getProductId() == $item->getProductId())
          $remainingQuota-= $orderedItem->getQtyOrdered() - $orderedItem->getQtyCanceled();
          
        }

       } 
      


       if($remainingQuota<$item->getQty()){
        $this->messageManager->addErrorMessage(__($item->getName())."： only allow to purchase $availableQuota per day, remaining quota is $remainingQuota");
        $item->setQty($remainingQuota);
       }
       if($remainingQuota==0){
        $observer->getRequest()->setParam('product', false);
       $observer->getRequest()->setParam('return_url', false);
         
       }
     }

	}
}