<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
 
namespace Novalnet\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Order\Models\OrderType;

/**
 * Class RefundEventProcedure
 */
class RefundEventProcedure
{
	use Loggable;
	
	/**
	 *
	 * @var PaymentHelper
	 */
	private $paymentHelper;
	
	/**
	 *
	 * @var PaymentService
	 */
	private $paymentService;
	
	/**
	 * @var transaction
	 */
	private $transaction;
	
	/**
	 * Constructor.
	 *
	 * @param PaymentHelper $paymentHelper
	 * @param PaymentService $paymentService
	 */
	 
    public function __construct( PaymentHelper $paymentHelper, TransactionService $tranactionService,
								 PaymentService $paymentService)
    {
        $this->paymentHelper   = $paymentHelper;
	    $this->paymentService  = $paymentService;
	    $this->transaction     = $tranactionService;
	}	
	
    /**
     * @param EventProceduresTriggered $eventTriggered
     * 
     */
    public function run(
        EventProceduresTriggered $eventTriggered
    ) {
        /* @var $order Order */
	 
	   $order = $eventTriggered->getOrder(); 
	   
	 $this->getLogger(__METHOD__)->error('order', $order);
	    // Checking order type
	   if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) {
		$originOrders = $order->orderReferences; 
		foreach ($order->orderReferences as $orderReference) {
			$parent_order_id = $orderReference->originOrderId;
		        $child_order_id = $orderReference->orderId;
			$order->id = $parent_order_id;
		}
	   } 
	   
           $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
	   $paymentDetails = $payments->getPaymentsByOrderId($order->id);
	   $this->getLogger(__METHOD__)->error('payment', $paymentDetails);
	   $orderAmount = (float) $order->amounts[0]->invoiceTotal;
	   $parent_order_amount = (float) $paymentDetails[0]->amount;
	    
	    $parentOrders = $this->transaction->getTransactionData('orderNo', $order->id);
	    foreach($parentOrders as $parentOrder) {
		    $updated_parent_order_amount = (float) ($parentOrder->amount / 100);
		 if ($order->typeId == OrderType::TYPE_CREDIT_NOTE &&  $updated_parent_order_amount >= $orderAmount) {   
		    $partial_refund_amount =  $updated_parent_order_amount -  $orderAmount;
	    }
	    
		    $this->getLogger(__METHOD__)->error('first', $updated_parent_order_amount);
	              $this->getLogger(__METHOD__)->error('sec', $partial_refund_amount);
		    $this->getLogger(__METHOD__)->error('third', $orderAmount);
	    
	   $paymentKey = $paymentDetails[0]->method->paymentKey;
	   $key = $this->paymentService->getkeyByPaymentKey($paymentKey);
	   
	    foreach ($paymentDetails[0]->properties as $paymentStatus)
		{
		    if($paymentStatus->typeId == 30)
		  {
			$status = $paymentStatus->value;
		  }	
		}
	    if ($status == 100)   
	    { 
		    
			try {
				$paymentRequestData = [
					'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
					'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
					'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
					'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
					'key'            => $key, 
					'refund_request' => 1, 
					'tid'            => $parentOrders[0]->tid, 
					'refund_param'  =>  (float) $orderAmount * 100,
					'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
					'lang'           => 'de'   
					 ];
					
			    $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
				$responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');
				 $this->getLogger(__METHOD__)->error('response', $responseData);
                                  
				
				
				if ($responseData['status'] == '100') {

				
					$transactionComments = '';
					if (!empty($responseData['tid'])) {
						$transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message_new_tid', $paymentRequestData['lang']), $parentOrder[0]->tid, (float) $orderAmount, $responseData['tid']);
					 } else {
						$transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message', $paymentRequestData['lang']), $parentOrder[0]->tid, (float) $orderAmount);
					 }
					
					$paymentData['tid'] = !empty($responseData['tid']) ? $responseData['tid'] : $parentOrder[0]->tid;
					$paymentData['tid_status'] = $responseData['tid_status'];
					$paymentData['remaining_paid_amount'] = (float) $partial_refund_amount;
					$paymentData['child_order_id'] = $child_order_id;
					$paymentData['parent_order_id'] = $order->id;
					$paymentData['parent_tid'] = $parentOrder[0]->tid;
					$paymentData['parent_order_amount'] = (float) $parent_order_amount;
					$paymentData['payment_name'] = strtolower($paymentKey);
					
					
if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) {
	$child_order = true;
	 $this->saveTransactionLog($paymentRequestData, $paymentData, $child_order);
	 $this->paymentHelper->createRefundPayment($paymentDetails, $paymentData, $transactionComments);
	 $this->paymentHelper->getNewPaymentStatus($paymentDetails, $parent_order_amount, $orderAmount, $parent_order_id);
} else {
	
	$paymentData['currency']    = $paymentDetails[0]->currency;
	$paymentData['paid_amount'] = (float) $orderAmount;
	$paymentData['tid']         = !empty($responseData['tid']) ? $responseData['tid'] : $parentOrder[0]->tid;
	$paymentData['order_no']    = $order->id;
	$paymentData['type']        = 'debit';
	$paymentData['mop']         = $paymentDetails[0]->mopId;
	$paymentData['booking_text'] = $transactionComments;  
	$this->paymentHelper->updatePayments($paymentData['tid'], $responseData['tid_status'], $order->id, '');
	$this->paymentHelper->createPlentyPayment($paymentData);
}

				} else {
					$error = $this->paymentHelper->getNovalnetStatusText($responseData);
					$this->getLogger(__METHOD__)->error('Novalnet::doRefundError', $error);
				}
			} catch (\Exception $e) {
						$this->getLogger(__METHOD__)->error('Novalnet::doRefund', $e);
					}	
	    }
    }
	
   public function saveTransactionLog($paymentRequestData,$paymentData, $child_order=false)
    {
       
        $insertTransactionLog = [
		'callback_amount' => $paymentRequestData['refund_param'],
		 'amount'     => ($child_order == 'true') ? (float) $paymentData['remaining_paid_amount'] * 100 : (float) $paymentData['parent_order_amount'] * 100,
        	'tid'            => $paymentRequestData['tid'],
        	'ref_tid'         => $paymentData['tid'],
       		'order_no'        => $paymentData['parent_order_id'],
		'payment_name'	  => $paymentData['payment_name']
		];
	   

        $this->transaction->saveTransaction($insertTransactionLog);
	    
	 $this->getLogger(__METHOD__)->error('tryyrrrrrrrrrrr', $insertTransactionLog);
    }
   
   
}
