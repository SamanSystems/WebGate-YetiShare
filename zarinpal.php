<?php
// Masoud Amini
// masoudamini.ir
echo'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <link rel="shortcut icon" href="favicon.ico">
        <title>پرداخت با درگاه زرین پال- نتیجه پرداخت</title>
<!-- Latest compiled and minified CSS -->
<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">

<!-- Optional theme -->
<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap-theme.min.css">

<!-- Latest compiled and minified JavaScript -->
<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.1/js/bootstrap.min.js"></script>
<body style="font-family:Tahoma;font-size: 12px;color: #333;display: block;width: 650px;text-align: center;direction: rtl;margin:0 auto 0 auto;padding:0 auto 0 auto;">
<center>
<br> </br><br> </br><br> </br><br> </br>
';
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

// global includes
require_once('./includes/master.inc.php');


	$Authority = $_GET['Authority'];
	$pluginConfig   = pluginHelper::pluginSpecificConfiguration('zarinpal');
	$pluginSettings = $pluginConfig['data']['plugin_settings'];
	
	

		$pluginSettingsArr = json_decode($pluginSettings, true);
		$zarinpal_Merchantid       = $pluginSettingsArr['zarinpal_Merchantid'];
	

// make sure payment has completed and it's for the correct zarinpal account
	if($_GET['Status'] == 'OK'){
								
		// URL also Can be https://ir.zarinpal.com/pg/services/WebGate/wsdl
		$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8')); 
		$paymentTracker = $_REQUEST['custom'];
		$order          = OrderPeer::loadByPaymentTracker($paymentTracker);
		//print_r($order);
		$result = $client->PaymentVerification(
						  	array(
									'MerchantID'	 => $zarinpal_Merchantid,
									'Authority' 	 => $Authority,
									'Amount'	 => $order->amount
								)
		);

		if($result->Status == 100){

			echo'
			<div class="alert alert-success">پرداخت شما با موفقیت انجام شد ؛شماره تراکنش شما : '. $result->RefID . '
</br>
		  برای بازگشت به صفحه اصلی سایت
			  <a href="./" class="alert-link">اینجا</a>
			  کلیک نمایید
			</div>';
			
			// load order using custom payment tracker hash
    
    if ($order)
    {
        $extendedDays  = $order->days;
        $userId        = $order->user_id;
        $upgradeUserId = $order->upgrade_user_id;
        $orderId       = $order->id;

        // log in payment_log
        $zarinpal_vars = "";
        foreach ($_REQUEST AS $k => $v)
        {
            $zarinpal_vars .= $k . " => " . $v . "\n";
        }
        $dbInsert                = new DBObject("payment_log", array("user_id", "date_created", "amount",
            "currency_code", "from_email", "to_email", "description",
            "request_log")
        );
        $dbInsert->user_id       = $userId;
        $dbInsert->date_created  = date("Y-m-d H:i:s", time());
        $dbInsert->amount        = $_REQUEST['custom'];
        $dbInsert->currency_code = $_REQUEST['mc_currency'];
        $dbInsert->from_email    = $_REQUEST['Authority'];
        $dbInsert->to_email      = $_REQUEST['Status'];
        $dbInsert->description   = $extendedDays . ' days extension';
        $dbInsert->request_log   = $zarinpal_vars;
        $dbInsert->insert();

        // make sure the amount paid matched what we expect


        // make sure the order is pending
        if ($order->order_status == 'completed')
        {
            // order has already been completed
            die('<div class="alert alert-warning">این پرداخت قبلا انجام شده است ؛ برای بازگشت به صفحه اصلی سایت 
			  <a href="./" class="alert-link">اینجا</a>
			  کلیک نمایید
			</div>');
        }

        // update order status to paid
        $dbUpdate               = new DBObject("premium_order", array("order_status"), 'id');
        $dbUpdate->order_status = 'completed';
        $dbUpdate->id           = $orderId;
        $effectedRows           = $dbUpdate->update();
        if ($effectedRows === false)
        {
            // failed to update order
            die('--2');
        }

        // extend/upgrade user
        $user          = UserPeer::loadUserById($userId);
        $newExpiryDate = strtotime('+' . $order->days . ' days');
        if (($user->level == 'paid user') || ($user->level == 'admin'))
        {
            // add onto existing period
            $existingExpiryDate = strtotime($user->paidExpiryDate);

            // if less than today just revert to now
            if ($existingExpiryDate < time())
            {
                $existingExpiryDate = time();
            }

            $newExpiryDate = (int) $existingExpiryDate + (int) ($order->days * (60 * 60 * 24));
        }

        $newUserType = 'paid user';
        if ($user->level == 'admin')
        {
            $newUserType = 'admin';
        }

        // update user account to premium
        $dbUpdate                 = new DBObject("users", array("level", "lastPayment", "paidExpiryDate"), 'id');
        $dbUpdate->level          = $newUserType;
        $dbUpdate->lastPayment    = date("Y-m-d H:i:s", time());
        $dbUpdate->paidExpiryDate = date("Y-m-d H:i:s", $newExpiryDate);
        $dbUpdate->id             = $userId;
        $effectedRows             = $dbUpdate->update();
        if ($effectedRows === false)
        {
            // failed to update user
            die('--3');
        }

        // append any plugin includes
        pluginHelper::includeAppends('payment_ipn_zarinpal.php');
    }
		} else {
			echo '
			<div class="alert alert-danger">پرداخت نا موفق ؛ کد خطا : '. $result->Status .'  ؛ برای بازگشت به صفحه اصلی سایت 
			  <a href="./" class="alert-link">اینجا</a>
			  کلیک نمایید
			</div>';
			
		}

	} else {
		echo '<div class="alert alert-danger">تراکنش توسط کاربر لغو شده است ؛ برای بازگشت به صفحه اصلی سایت 
			  <a href="./" class="alert-link">اینجا</a>
			  کلیک نمایید
			</div>';
	}
