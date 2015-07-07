<?php
/**
 * LICENSE NOTICE
 *
 * Protx Direct - 3D secure
 * Copyright (C) 2009  Screen Pages
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://www.gnu.org/licenses/.
 *
 * DESCRIPTION
 *
 * This class is used for opening the 3D secure pop up and handles the 3D secure response from Protx.
 *
 * @copyright Copyright (c) 2009 Screen Pages (http://www.screenpages.com)
 * @author    Screen Pages
 * @version   0.0.1
 * @module    Mage_ProtxDirect_PaymentController
 * @license   http://www.gnu.org/licenses/gpl-3.0.html
 */

class Mage_Cybersource_PaymentController extends Mage_Core_Controller_Front_Action
{
    const ACTION_AUTHORIZE          = 'authorize';
    const ACTION_AUTHORIZE_CAPTURE  = 'authorize_capture';

    const STATUS_UNKNOWN    = 'UNKNOWN';
    const STATUS_APPROVED   = 'APPROVED';
    const STATUS_ERROR      = 'ERROR';
    const STATUS_DECLINED   = 'DECLINED';
    const STATUS_VOID       = 'VOID';
    const STATUS_SUCCESS    = 'SUCCESS'; 

    
	private function clearSession()
	{
		Mage::getSingleton('checkout/session')->setAdditionalData('');
		Mage::getSingleton('checkout/session')->setSecure3d('');
		Mage::getSingleton('checkout/session')->setProcessStart('');
	}
	
	public function _setFailureMessage($sMessage)
	{
		$session = Mage::getSingleton('checkout/session');
		if (strlen($sMessage)==0) $sMessage="Unknown Error";
		$session->addError($sMessage);
		Mage::log('Cybersource debug : Received error message : '.$sMessage,null,'cyberlog.log');
		echo '<script language="Javascript" type="text/javascript">window.parent.location.href="'.$this->failureURL().'";</script>';
		$this->clearSession();
		exit;
	}

    protected function _expireAjax()
    {
        if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
            exit;
        }
    }
    /**
	 * Get singleton of Checkout Session Model
	 *
	 * @return Mage_Checkout_Model_Session
	 */
	protected function _getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}
    
     /**
     * Load quote and order objects from session
     */
    protected function _loadCheckoutObjects()
    {
            // load quote
        if ($quoteId = $this->_getCheckout()->getCybersourceQuoteId()) {
            $this->_getCheckout()->setQuoteId($quoteId);
            $this->_getCheckout()->getQuote()->setIsActive(true)->save();
        } else {
            Mage::throwException(Mage::helper('cybersource')->__('Checkout session is empty.'));
        }

            // load order
        		//$this->_order = Mage::getModel('sales/order');
            #$this->_payment = $this->_getCheckout()->getQuote()->getPayment();
            
            ##### SONASSI FIX FOR 1.4.2.0 MAGENTO #####
            
            $quote = Mage::getModel('sales/quote')->load($this->_getCheckout()->getCybersourceQuoteId());
            $this->_getCheckout()->getQuote()->setData($quote->getData());


            ##### SONASSI FIX FOR 1.4.2.0 MAGENTO #####
            
            /*if ($quote->isVirtual()) {
                $quote->getBillingAddress()->setPaymentMethod('cybersource_soap')->save();
            } else {
                $quote->getShippingAddress()->setPaymentMethod('cybersource_soap')->save();
            }
            
            $this->_getCheckout()->getQuote()->getPayment()->setData($quote->getPayment()->getData());
            $this->_getCheckout()->getQuote()->getBillingAddress()->setData($quote->getBillingAddress()->getData());
        		if (!$quote->isVirtual())
        		  $this->_getCheckout()->getQuote()->getShippingAddress()->setData($quote->getShippingAddress()->getData());
        		*/
        		$this->_payment = $quote->getPayment();
        		Mage::log('Cybersource debug : Retrieving quote information - quoteId : '.$quote->getId(),null,'cyberlog.log'); 

        		//$this->_order->load($this->_getCheckout()->getLastOrderId());
        
        //if (!$this->_order->getId()) {
        //    Mage::throwException(Mage::helper('cybersource')->__('An error occured during the payment process: Order not found.'));
       // }
    }
	protected function _renderHideIframeHtml()
	{
		echo '<script language="Javascript" type="text/javascript">';
    	echo 'function _getObject(nameStr) {';
		echo 'var ie  = (document.all);';
		echo 'var ns4 = document.layers? true : false;';
		echo 'var dom = document.getElementById && !document.all ? true : false;';
		echo 'if (dom) {';
	    echo 'return window.parent.document.getElementById(nameStr);';
		echo '} else if (ie) {';
	    echo 'return window.parent.document.all[nameStr];';
		echo '} else if (ns4) {';
	    echo 'return window.parent.document.layers[nameStr];';
		echo '}';
    	echo '}';
    	echo '_getObject(\'_oDropShadow\').style.display=\'none\';_getObject(\'_o3DSecureWindow\').style.display=\'none\';';
    	echo '</script>';
	}
	
    public function TestAction()
    {
    	echo "Hello World";
    }
    
    
    public function AuthResultAction()
    {

//        Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action'));
   		$this->_renderHideIframeHtml();
    	$tokenId=$this->getRequest()->getParam('tokenid');
    	$error = false;
    	Mage::log('Cybersource debug : reaches AuthResult action - tokenId : '.$tokenId.' - Session Token Id : '.@$_SESSION['TokenID'],null,'cyberlog.log');
    	if((!isset($_SESSION['TokenID'])) || ($_SESSION['TokenID']!=$tokenId))
    	{
    		$_SESSION['TokenID']=$tokenId;
    		Mage::log('Cybersource debug : Token ID isn\'t defined or different than the param - Check the payment status and saves the order',null,'cyberlog.log');
    		try
    		{
    			$session = $this->_getCheckout();
    			
    			$this->_loadCheckoutObjects();
    			// get essentiall parameters
    			$PaRes = $this->getRequest()->getParam('PaRes');
    			$encMD = $this->getRequest()->getParam('MD');
    			// do not proceed on empty parameters
    			if (empty($PaRes) || empty($encMD)) {
//    			    Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : No data returned', 'checkout_session' => $session->debug()));
    				$this->norouteAction();
    				return;
    			}
    			
    			$payment = $this->_payment->getMethodInstance();
    			$this->_response = $payment->validatePayerAuthentication($encMD,$PaRes,$this->_payment);
    			 
    			$response=$this->_response;

    			$additionalData=array();
    			$additionalData=Mage::getSingleton('checkout/session')->getAdditionalData();
    			$additionalData['paRes']=$PaRes;
    			Mage::getSingleton('checkout/session')->setAdditionalData($additionalData);

    			// Store the success values
    			$auth_success = array('0', '1');

    			// Store the incomplete error values
    			$auth_incomplete = array('6');

    			// Store the failure values
    			$auth_failure = array();
    			// Invalid PARes (All Cards)
    			$auth_failure[] = '-1';
    			// Authentication Failed (All Cards)
    			$auth_failure[] = '9';
          Mage::log('Cybersource debug : Cybersource response : '.$response->decision.' - Reason code : '.$response->reasonCode,null,'cyberlog.log');
//    			Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Checking Result', 'result' => $response, 'checkout_session' => $session->debug()));

    			// If the auth has "passed", but NOT if it was incomplete ($response->payerAuthValidateReply->eciRaw != '07')
    			if (   $response->decision == 'ACCEPT' && $response->reasonCode==100 &&( isset($response->payerAuthValidateReply->authenticationResult) && in_array($response->payerAuthValidateReply->authenticationResult, $auth_success)))
    			{
//    			    Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Auth Passed', 'checkout_session' => $session->debug()));
    			    
    				$status=Mage::getStoreConfig('payment/cybersource_soap/order_status');
    				$action=Mage::getStoreConfig('payment/cybersource_soap/payment_action');

    				if($action==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE)
    				{
//    					Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Capturing', 'checkout_session' => $session->debug()));
    				
    					//Capture
    					$capture_response = $payment->caputureAfterPayerAuth($encMD, $PaRes, $this->_payment);
    					
    					
    					if($capture_response->decision == 'ACCEPT' && $capture_response->reasonCode==100)
    					{
//    						Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Capture Successful, saving order', 'checkout_session' => $session->debug()));
    					
    						$this->_saveOrder($status);
    						$payment_inst=$this->_order->getPayment();
    						$payment_inst->setStatus(self::STATUS_APPROVED);
    						$payment_inst->setAdditionalData(serialize($additionalData))->save();

    					}else{
//    						Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Capture Failed', 'checkout_session' => $session->debug()));    					
    						
    						Mage::throwException(Mage::helper('cybersource')->__('An error during the whilst capturing the funds from your card: %s', $capture_response->reasonCode));
    					}

    				}else{
//    					Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Auth Successful, saving order', 'checkout_session' => $session->debug()));
    				
    					$this->_saveOrder($status);
    					$payment_inst=$this->_order->getPayment();
    					$payment_inst->setStatus(self::STATUS_APPROVED);
    					$payment_inst->setAdditionalData(serialize($additionalData))->save();
    				}
    				
//    				Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Redirecting to success page', 'checkout_session' => $session->debug()));
    				
    				$this->_SuccessRedirect();
    				Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    				Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    				Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');
    				return;
    			}elseif( $response->decision == 'REJECT' && $response->reasonCode==200 && ( isset($response->payerAuthValidateReply->authenticationResult) && in_array($response->payerAuthValidateReply->authenticationResult, $auth_success) ))
    			{
//    			    Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Auth Rejected', 'checkout_session' => $session->debug()));
    				$failavs_status=Mage::getStoreConfig('payment/cybersource_soap/fail_avs_order_status');
    				if($failavs_status!='nosave')
    				{
    					//Save order that faile AVS but passed 3d secure validation
    					$this->_saveOrder($failavs_status);
    					$payment_inst=$this->_order->getPayment();
    					$payment_inst->setStatus(self::STATUS_APPROVED);
    					$payment_inst->setAdditionalData(serialize($additionalData))->save();
    					$this->_SuccessRedirect();
    					Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    					Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    					Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');
    					return;
    				}else{
    					Mage::throwException(Mage::helper('cybersource')->__('We could not verify your address whilst processing you payment: %s', $response->payerAuthValidateReply->reasonCode));
    				}
    				 
    				// If the auth was incomplete
    			}elseif( isset($response->payerAuthValidateReply->authenticationResult) && in_array($response->payerAuthValidateReply->authenticationResult, $auth_incomplete)){
//    				Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Auth Incomplete', 'checkout_session' => $session->debug()));
    			    $fail3d_status=Mage::getStoreConfig('payment/cybersource_soap/fail_pa_order_status');
    				if($fail3d_status!='nosave')
    				{
    					//Save order that faile 3d secure validation
    					$this->_saveOrder($fail3d_status);
    					$payment_inst=$this->_order->getPayment();
    					$payment_inst->setAdditionalData(serialize($additionalData))->save();
    					$this->_SuccessRedirect();
    					Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    					Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    					Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');
    					return;
    				}else{
    					Mage::throwException(Mage::helper('cybersource')->__('An error during the credit card processing occured: %s', $response->payerAuthValidateReply->reasonCode));
    				}
    				//AVS Failure
    			}else{
//    			    Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Auth Error', 'checkout_session' => $session->debug()));
    				Mage::throwException(Mage::helper('cybersource')->__('An error during the credit card processing occured: %s', $response->payerAuthValidateReply->reasonCode));
    			}
    			 

    		}catch (Exception $e) {
    			$error = true;
    			$message = '';
    			Mage::logException($e);
    			Mage::log($e->getMessage());
    			if( isset($_SESSION['cybersource_message']) ) {
    				$message = $_SESSION['cybersource_message'];
    				unset($_SESSION['cybersource_message']);
    			} else {
    				$message = 'Cybersource Payer Validation was not successfull and checkout was cancelled. <br/>';
    				$message .= 'Please check your credit card details and try again.';
    			}
    			$this->_setFailureMessage($message);
    			unset($_SESSION["cybersource_currency"]);
    			unset($_SESSION["cybersource_total"]);
    			unset($_SESSION["cyberscoure_cardtype"]);
    			Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    			Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    			Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');
    			$mSession=Mage::getSingleton('core/session');
    			$mSession->unsetData('billing');
    			$mSession->unsetData('shipping');
    			$mSession->unsetData('purchase_totals');
    			Mage::getSingleton('checkout/session')->unsetData('additional_data');
    			Mage::getSingleton('catalog/session')->addError($message);

    		}
    	}
    	else 
    	{   
    		$error = true;
    		Mage::log('Cybersource debug : Token ID was defined - Magento raises an exception',null,'cyberlog.log');
    		$message = 'PLEASE NOTE: We HAVE taken your order successfully and the stock has been reserved for you, however there was an error connecting to your bank to confirm the payment, we will contact you very shortly (Between 9am and 5.30pm Monday-Friday) to confirm the details so we can despatch your order. Sorry for any inconvenience caused.';
    		// Send a notification
            $subject = "Shore.co.uk Magento Site - Error in Payment Callback";
            $now_time = date('Y-m-d H:i:s');
            $body = "There was an error in the payment gateway callback on Shore.co.uk (Server Time is currently {$now_time}).";
            $body .= "<br /><br /> Session Token = {$_SESSION['TokenID']} <br /> Request Token = {$tokenId}<br />";
            $to_email = Mage::getStoreConfig('trans_email/ident_custom2/email');
            $to_name = Mage::getStoreConfig('trans_email/ident_custom2/name');
            
            $mail = new Zend_Mail();
            $mail->setBodyText($body);
            $mail->setFrom('noreply@shore.co.uk', 'Shore.co.uk Payment Callback')
            ->addTo($to_email, $to_name)
            ->setSubject($subject);
            $mail->send();
            
//            Mage::dispatchEvent('splog_event_log', array('message' => 'Cybersource Payment Controller : Auth Result Action : Callback Error', 'info' => $body));
            
            $this->_setFailureMessage($message);
    	}
    	//$this->_renderHideIframeHtml();
    	return;

    }
    private function _getCurrentOrder()
    {
    	    $order_id = Mage::getSingleton('checkout/type_onepage')->saveOrderAfter3dSecure()->getLastOrderId();
                        // load order
        	$order = Mage::getModel('sales/order');
        	$order->loadByIncrementId($order_id);
        	return $order;
    }
    private function _saveOrder($status)
    {
        $checkout = Mage::getSingleton('checkout/type_onepage');    
        $checkout->saveOrderAfter3dSecure();
    	  $order_id = $checkout->getLastOrderId();
                        // load order
        	$this->_order = Mage::getModel('sales/order');
        	$this->_order->loadByIncrementId($order_id);
        	Mage::log('Cybersource debug : saves order - Order ID  : '.$order_id,null,'cyberlog.log');
            // log 3D-Secure information
            $authResult = "";
            $reasonCode = $this->_response->reasonCode;
            if ( isset($this->_response->payerAuthValidateReply->authenticationResult) ){
                $authResult = $this->_response->payerAuthValidateReply->authenticationResult;
            }
            $this->_order->setState(
                $status, 
                true,
               	$this->_getSuccessStatusMessage($authResult,$reasonCode),
               	true
            	);
            // set transaction ID
            $payment = $this->_payment;
                $payment->setLastTransId($this->_response->requestID)
                ->setCcTransId($this->_response->requestID);
                		
            // payment is okay. clear session values and show success page.
            Mage::getSingleton('checkout/session')->unsCybersourceQuoteId()
                ->unsCybersourceLastSuccessQuoteId()
                ->unsCybersourceRealOrderId();
            $payment->save();
			$this->_order->save();
            unset($_SESSION["cybersource_currency"]);
    		unset($_SESSION["cybersource_total"]);
    		unset($_SESSION["cyberscoure_cardtype"]);
    		return $this->_order;
    }
    
    public function _SuccessRedirect()
    {
        echo '<script language="Javascript" type="text/javascript">window.parent.location.href="'.$this->successURL().'";</script>';
		return;
    }
    
	protected function successURL()
    {
   		return Mage::getURL('checkout/onepage/success');
    }
    
    protected function failureURL()
    {
   		return Mage::getURL('checkout/cart');
    }
    
	public function cybersourcePayerAuthFormAction()
    {

    	$html= null;
    	$html.="<html>\n";
		$html.="<head>\n";
		$html.="<title>Payer Authorisation</title>\n";
		$html.="<script language=\"Javascript\" type=\"text/javascript\">\n";
		$html.="function OnLoadEvent() {\n";
		$html.="document.form.submit();\n";
		$html.="}\n";
		$html.="</script>\n";
		$html.="</head>\n";
		$html.="<body OnLoad=\"OnLoadEvent();\">\n";
		$html.="<form name=\"form\" action=\"".str_replace('\\','',str_replace(':::','://',$this->getRequest()->getParam('ACSURL')))."\" method=\"POST\">\n";
		$html.="<input type=\"hidden\" name=\"PaReq\" value=\"".str_replace(" ","+",str_replace('\\','',str_replace('\n','',$this->getRequest()->getParam('PaReq'))))."\"/>\n";

		$html.="<input type=\"hidden\" name=\"TermUrl\" value=\"".str_replace('\\','',str_replace(':::','://',$this->getRequest()->getParam('TermURL')))."&enrolled=".$this->getRequest()->getParam('enrolled')."\"/>\n";
		$html.="<input type=\"hidden\" name=\"MD\" value=\"".$this->getRequest()->getParam('MD')."\"/>\n";
		//$html.="<input type=\"submit\" value=\"Go\"/></p>\n";
		$html.="<NOSCRIPT>\n";
		$html.="<center>\n";
		$html.="<p>Please click button below to Authenticate your card</p>\n";
		$html.="<input type=\"submit\" value=\"Go\"/></p>\n";
		$html.="</center>\n";
		$html.="</NOSCRIPT>\n";
		$html.="</form>\n";
		$html.="</body>\n";
		$html."</html>\n";		
		echo $html;
    }

    
    
    protected function _getSuccessStatusMessage($statusCode,$reasonCode=null)
    {
    	$message='';
        switch($statusCode) {
            case 0:
                $message = Mage::helper('cybersource')->__('Successful 3D-Secure authentication. Successful validation');
                break;
            case 'U':
                $message = Mage::helper('cybersource')->__('3D-Secure authentication. Status: U. 3D-Secure not available. Transaction is performed');
                break;
            case 'M':
                $message = Mage::helper('cybersource')->__('3D-Secure authentication. Status: M. Card doesn\'t support 3D-Secure. Transaction is performed');
                break;
            case 9:
            	$message = Mage::helper('cybersource')->__('3D-Secure authentication Failed. Status Code: %s.', $statusCode);
            	break;
            default:
           		$message = Mage::helper('cybersource')->__('3D-Secure authentication. Status: %s. Transaction is performed', $statusCode);
           		break;
        }
        $message.=' (' . $reasonCode . ')';
        return $message;
    }
    
    /**
     * Set redirect into responce
     *
     * @param   string $path
     * @param   array $arguments
     */
    protected function _redirect($path, $arguments=array())
    {
        $this->getResponse()->setRedirect(Mage::getUrl($path, $arguments));
        return $this;
    }
    
    
    public function cybersourcePayerAuthSubmitDebugAction()
    {
		$url=str_replace('\\','',str_replace(':::','://',$this->getRequest()->getParam('ACSURL')));
		
		$postdata="PaReq=".urlencode(str_replace(" ","+",str_replace('\\','',str_replace('\n','',$this->getRequest()->getParam('PaReq')))));
		$postdata.="&TermUrl=". urlencode(str_replace('\\','',str_replace(':::','://',$this->getRequest()->getParam('TermURL'))));
		$postdata.="&enrolled=".urlencode($this->getRequest()->getParam('enrolled'));
		$postdata.="&MD=".urlencode($this->getRequest()->getParam('MD'));
		//$postdata = urlencode($postdata);  
		echo'<pre>';	
    	print_r($url);
    	echo "\n";
    	print_r($postdata);
    	echo'</pre>';
/*   	$fp = @fsockopen($url, 80, $errno, $errstr, 10);
		if(!$fp) {
			echo "Error $errno: $errstr<br />\n";
			exit;
		}
		
		
		$data = "POST /cgi-bin/calc.cgi HTTP/1.0\r\n";
		$data .= "Host: {$url}\r\n";
		$data .= "Content-type: application/x-www-form-urlencoded\r\n";
		$data .= "Content-length: " . strlen($postdata) . "\r\n";
		$data .= "\r\n";
		$data .= $postdata;
		$data .= "\r\n";
		
		fputs($fp, $data);
		
		while(!feof($fp)) {
		$return .= fgets($fp);
		}
		
		fclose($fp);*/
    	try{
		$ch = curl_init(); // initialize curl handle
		curl_setopt($ch, CURLOPT_URL,$url); 
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);   
		curl_setopt($ch, CURLOPT_POST, 1); // 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata); 
		var_dump($ch);
		$return = curl_exec($ch); 
		}catch(Exception $e){
		echo $e->getMessage();
    	}
		curl_close($ch);
		echo "[{$return}]";
		
    	
    }

}
