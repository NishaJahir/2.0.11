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

namespace Novalnet\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Services\PaymentService;

/**
 * Class TransactionService
 *
 * @package Novalnet\Services
 */
class TransactionService
{
    use Loggable;
	
		

    /**
     * Save data in transaction table
     *
     * @param $transactionData
     */
    public function saveTransaction($transactionData)
    {
        try {
            $database = pluginApp(DataBase::class);
            $transaction = pluginApp(TransactionLog::class);
            $transaction->orderNo             = $transactionData['order_no'];
            $transaction->amount              = $transactionData['amount'];
            $transaction->callbackAmount      = $transactionData['callback_amount'];
            $transaction->referenceTid        = $transactionData['ref_tid'];
            $transaction->transactionDatetime = date('Y-m-d H:i:s');
            $transaction->tid                 = $transactionData['tid'];
            $transaction->paymentName         = $transactionData['payment_name'];
            $transaction->additionalInfo      = !empty($transactionData['additional_info']) ? $transactionData['additional_info'] : '0';
            
            $database->save($transaction);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Callback table insert failed!.', $e);
        }
    }

    /**
     * Retrieve transaction log table data
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return array
     */
    public function getTransactionData($key, $value)
    {
        $database = pluginApp(DataBase::class);
        $order    = $database->query(TransactionLog::class)->where($key, '=', $value)->get();
        return $order;
    }
    
     public function updateTransactionDatas($key, $value, $invoiceDetails)
    {
        $database = pluginApp(DataBase::class);
        $order    = $database->query(TransactionLog::class)->where($key, '=', $value)->get();
        $update_info = $order[0];
        $additional_info = json_decode($update_info->additionalInfo, true);
        $update_additional_info = [
	    'due_date'          => '2020-04-05',
	    'invoice_type'      => 'Invoice',
	    'invoice_account_holder' => 'Novalnet AG'
        ];
        $additional_info = array_merge($additional_info, $update_additional_info);
        $update_info->additionalInfo = json_encode($additional_info);
        $this->getLogger(__METHOD__)->error('info', $update_info);
        $database->save($update_info);

        return $update_info;
    }
}
