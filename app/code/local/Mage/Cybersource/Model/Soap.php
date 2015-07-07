<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   Mage
 * @package    Mage_cybersource
 * @copyright  Copyright (c) 2008 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


class Mage_Cybersource_Model_Soap extends Mage_Payment_Model_Method_Cc
{
    protected $_code  = 'cybersource_soap';
    protected $_formBlockType = 'cybersource/form';
    protected $_infoBlockType = 'cybersource/info';

    const WSDL_URL_TEST = 'https://ics2wstest.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.51.wsdl';
    const WSDL_URL_LIVE = 'https://ics2ws.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.51.wsdl';

    const RESPONSE_CODE_SUCCESS = 100;
    const RESPONSE_CODE_ENROLLED = 475;
    const RESPONSE_CODE_AVSFAIL = 200;

    const CC_CARDTYPE_SS = 'SS';
    
    protected $_ccCybersourceCcTypes = Array('VI' => '001' ,'MC' => '002', 'JCB' => '007', 'SS' =>'024' );
    

    /**
     * Availability options
    */
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc = false;

    protected $_payer_enroll_check_available = true;
    protected $_request;

        /*
    * overwrites the method of Mage_Payment_Model_Method_Cc
    * for switch or solo card
    */
    public function OtherCcType($type)
    {
        return (parent::OtherCcType($type) || $type==self::CC_CARDTYPE_SS || $type=='JCB' || $type=='UATP');
    }

    /**
     * overwrites the method of Mage_Payment_Model_Method_Cc
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {

        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        parent::assignData($data);
        $info = $this->getInfoInstance();

        if ($data->getCcType()==self::CC_CARDTYPE_SS) {
            $info->setCcSsIssue($data->getCcSsIssue())
                ->setCcSsStartMonth((($data->getCcSsStartMonth()==0) || ($data->getCcSsStartMonth()==null))? 1 : $data->getCcSsStartMonth())
                ->setCcSsStartYear($data->getCcSsStartYear())
            ;
        }     
        
        return $this;
    }

    /**
     * Validate payment method information object
     *
     * @param   Mage_Payment_Model_Info $info
     * @return  Mage_Payment_Model_Abstract
     */
    
    public function _unsetSessionVars()
    {
    	unset($_SESSION["cybersource_currency"]);
	    unset($_SESSION["cybersource_total"]);
	    unset($_SESSION["cyberscoure_cardtype"]);
	   	$mSession=Mage::getSingleton('core/session');
    	$mSession->unsetData('billing');
    	$mSession->unsetData('shipping');
    	$mSession->unsetData('purchase_totals');
    	Mage::getSingleton('checkout/session')->unsetData('additional_data');
    	Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    	Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    	Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');
    }
    
    public function validate()
    {
        if (!extension_loaded('soap')) {
            Mage::throwException(Mage::helper('cybersource')->__('SOAP extension is not enabled. Please contact us.'));
        }
        /**
        * to validate paymene method is allowed for billing country or not
        */
        $paymentInfo = $this->getInfoInstance();
        
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
            $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
        }
        if (!$this->canUseForCountry($billingCountry)) {
            Mage::throwException($this->_getHelper()->__('Selected payment type is not allowed for billing country.'));
        }

        $info = $this->getInfoInstance();
        $errorMsg = false;
        $availableTypes = explode(',',$this->getConfigData('cctypes'));

        $ccNumber = $info->getCcNumber();
		
        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        $info->setCcNumber($ccNumber);
        $ccType = '';

        if (!$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorCode = 'ccsave_expiration,ccsave_expiration_yr';
            $errorMsg = $this->_getHelper()->__('Incorrect credit card expiration date');
        }
		if ($ccNumber) {
        // ccNumber is not present after 3Dcallback, in this case we supose cc is already checked
        if (in_array($info->getCcType(), $availableTypes)){
            if ($this->validateCcNum($ccNumber)
                // Other credit card type number validation
                || ($this->OtherCcType($info->getCcType()) && $this->validateCcNumOther($ccNumber))) {

                $ccType = 'OT';
                $ccTypeRegExpList = array(
                    'VI' => '/^4[0-9]{12}([0-9]{3})?$/', // Visa
                    'MC' => '/^5[1-5][0-9]{14}$/',       // Master Card
                    'AE' => '/^3[47][0-9]{13}$/',        // American Express
                    'DI' => '/^6011[0-9]{12}$/',          // Discovery
                    'JCB' => '/^(3[0-9]{15}|(2131|1800)[0-9]{12})$/', // JCB
                    'LASER' => '/^(6304|6706|6771|6709)[0-9]{12}([0-9]{3})?$/', // LASER
                	'SS' => '/^((6759[0-9]{12})|(5[0678][0-9]{11,18})|(6767[0-9]{11})|(6767[0-9]{15})|(6767[0-9]{12})|(49[013][1356][0-9]{13})|(633[34][0-9]{12})|(633110[0-9]{10})|(564182[0-9]{10}))([0-9]{2,3})?$/'
                );

                foreach ($ccTypeRegExpList as $ccTypeMatch=>$ccTypeRegExp) {
                    if (preg_match($ccTypeRegExp, $ccNumber)) {
                        $ccType = $ccTypeMatch;
                        break;
                    }
                }

                if (!$this->OtherCcType($info->getCcType()) && $ccType!=$info->getCcType()) {
                    $errorCode = 'ccsave_cc_type,ccsave_cc_number';
                    $errorMsg = $this->_getHelper()->__('Credit card number mismatch with credit card type');
                }
            }
            else {
                $errorCode = 'ccsave_cc_number';
                $errorMsg = $this->_getHelper()->__('Invalid Credit Card Number');
            }

        }
        else {
            $errorCode = 'ccsave_cc_type';
            $errorMsg = $this->_getHelper()->__('Credit card type is not allowed for this payment method');
        }
		}
        if($errorMsg){
            Mage::throwException($errorMsg);
        }
        return $this;
    }

   /**
     * Getting Soap Api object
     *
     * @param   array $options
     * @return  Mage_Cybersource_Model_Api_ExtendedSoapClient
     */
    protected function getSoapApi($options = array())
    {
        $wsdl = $this->getConfigData('test') ? self::WSDL_URL_TEST  : self::WSDL_URL_LIVE;
        return new Mage_Cybersource_Model_Api_ExtendedSoapClient($wsdl, $options);
    }

    /**
     * Initializing soap header
     */
    protected function iniRequest()
    {
        $this->_request = new stdClass();
        $this->_request->merchantID = $this->getConfigData('merchant_id');
        $session = Mage::getSingleton('checkout/session');
        if($merchantref = $session->getMerchantReferenceCode())
        {
        	$this->_request->merchantReferenceCode = $merchantref;
        }
        else
        {
        	$merchantref = $this->_generateReferenceCode();
         	$this->_request->merchantReferenceCode = $merchantref;
         	$session->setMerchantReferenceCode($merchantref);      	
        }
		
        if($requestToken = $session->getCyberSourceLastRequestToken())
        {
        	$this->_request->requestToken = $requestToken;
        }
        
        if($requestId = $session->getCyberSourceLastRequestId())
        {
        	$this->_request->requestID = $requestId;
        }
        $this->_request->clientLibrary = "PHP";
        $this->_request->clientLibraryVersion = phpversion();
        $this->_request->clientEnvironment = php_uname();
        
    }

    /**
     * Random generator for merchant referenc code
     *
     * @return random number
     */
    protected function _generateReferenceCode()
    {
        return md5(microtime() . rand(0, time()));
    }

    /**
     * Getting customer IP address
     *
     * @return IP address string
     */
    protected function getIpAddress()
    {
        return (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    }

    /**
     * Assigning billing address to soap
     *
     * @param Varien_Object $billing
     * @param String $email
     */
    protected function addBillingAddress($billing, $email)
    {
        if (!$email) {
            $email = Mage::getSingleton('checkout/session')->getQuote()->getBillingAddress()->getEmail();
        }
        
        $billTo = new stdClass();
        $billTo->firstName = $billing->getFirstname();
        $billTo->lastName = $billing->getLastname();
        $billTo->company = $billing->getCompany();
        $billTo->street1 = $billing->getStreet(1);
        $billTo->street2 = $billing->getStreet(2);
        $billTo->city = $billing->getCity();
        $billTo->state = $billing->getRegion();
        $billTo->postalCode = $billing->getPostcode();
        $billTo->country = $billing->getCountry();
        $billTo->phoneNumber = $this->cleanPhoneNum($billing->getTelephone()); //validate the phone number better
        $billTo->email = ($email ? $email : Mage::getStoreConfig('trans_email/ident_general/email'));
        $billTo->ipAddress = $this->getIpAddress();
        $this->_request->billTo = $billTo;
        Mage::getSingleton('core/session')->setBilling($billTo);
    }
    
    public function cleanPhoneNum($phoneNumberIn)
    {

		$filtered = preg_replace("/[^0-9]/","",$phoneNumberIn);
		
		if(strlen($filtered) < 4)
		{
			
			return '01243674830';
		
		}else
		{
			
			return $filtered;
		}
    }

    /**
     * Assigning shipping address to soap object
     *
     * @param Varien_Object $shipping
     */
    protected function addShippingAddress($shipping)
    {
        //checking if we have shipping address, in case of virtual order we will not have it
        if ($shipping) {
            $shipTo = new stdClass();
            $shipTo->firstName = $shipping->getFirstname();
            $shipTo->lastName = $shipping->getLastname();
            $shipTo->company = $shipping->getCompany();
            $shipTo->street1 = $shipping->getStreet(1);
            $shipTo->street2 = $shipping->getStreet(2);
            $shipTo->city = $shipping->getCity();
            $shipTo->state = $shipping->getRegion();
            $shipTo->postalCode = $shipping->getPostcode();
            $shipTo->country = $shipping->getCountry();
            $shipTo->phoneNumber = $this->cleanPhoneNum($shipping->getTelephone());
            $this->_request->shipTo = $shipTo;
            Mage::getSingleton('core/session')->setShipping($shipTo);
        }
    }

    /**
     * Assigning credit card information
     *
     * @param Mage_Model_Order_Payment $payment
     */
    protected function addCcInfo($payment)
    {
    	$info = $this->getInfoInstance();       
		$ccNumber = preg_replace('/[\-\s]+/', '', $payment->getCcNumber());
        $info->setCcNumber($ccNumber);
        
        $card = new stdClass();
        $card->fullName = $payment->getCcOwner();
        $card->expirationMonth = $payment->getCcExpMonth();
        $card->expirationYear =  $payment->getCcExpYear();
        $card->accountNumber = $payment->getCcNumber();
        if ($payment->hasCcCid()) {
            $card->cvNumber =  $payment->getCcCid();
        }
        if ($payment->getCcType()==self::CC_CARDTYPE_SS && $payment->hasCcSsIssue()) {
            $card->issueNumber =  $payment->getCcSsIssue();
        }
        if ($payment->getCcType()==self::CC_CARDTYPE_SS && $payment->hasCcSsStartYear()) {
            $card->startMonth =  $payment->getCcSsStartMonth();
            $card->startYear =  $payment->getCcSsStartYear();
        }
        //Translate cartype to cybersource card type value

        	if(array_key_exists($payment->getCcType(),$this->_ccCybersourceCcTypes))
        	{
        	$card->cardType = $this->_ccCybersourceCcTypes[$payment->getCcType()];
        	$this->_payer_enroll_check_available=true;
        	}else{
        	$this->_payer_enroll_check_available=false;
        	}
        $this->_request->card = $card;
    }
    
    /**
     * Assigning Payer Auth Flag to request
     */
    protected function addPayerAuthFlag($payment,$override=false)
    {
    	if($this->getConfigData('usepayerauth')==1)
        {
        	if((array_key_exists($payment->getCcType(),$this->_ccCybersourceCcTypes)) || $override==true)
        	{
    			$payerAuthEnrollService = new stdClass();
        		$payerAuthEnrollService->run = "true";
    			$this->_request->payerAuthEnrollService = $payerAuthEnrollService;
        	 }
        }
        
    }

     /**
     * Assigning Payer Auth Validate Flag to request
     */
    protected function addPayerAuthValidateFlag($PaRes,$requestID=null)
    {
    	    $payerAuthValidateService = new stdClass();
        	$payerAuthValidateService->run = "true";
        	$payerAuthValidateService->signedPARes = $PaRes;
        	$payerAuthValidateService->authRequestID = $requestID;
            $session = Mage::getSingleton('checkout/session');
        	if($requestToken = $session->getCyberSourceLastRequestToken())
        	{	
        		$payerAuthValidateService->authRequestToken = $requestToken;
        	}
    		$this->_request->payerAuthValidateService = $payerAuthValidateService;
    }
    
    
    
    /**
     * Assigning CC Auth Flag to request
     */	
    protected function addCcAuthFlag($requestID=null)
    {
    	$ccAuthService = new stdClass();
        $ccAuthService->run = "true";
        if($requestID)
        {
        	$ccAuthService->requestID = $requestID;
        }
        $this->_request->ccAuthService = $ccAuthService;
    }
    
    
    /**
     * Assigning Totals  to request
     */	
	public function addPurchaseTotals($payment,$amount)
	{
        $purchaseTotals = new stdClass();
        $purchaseTotals->currency = $payment->getOrder()->getBaseCurrencyCode();
        $purchaseTotals->grandTotalAmount = $amount;
        $this->_request->purchaseTotals = $purchaseTotals;
        Mage::getSingleton('core/session')->setPurchaseTotals($purchaseTotals);
	}
    
    protected function _executeSoapCall()
    {
		$result='';
    	try {
	        	$soapClient = $this->getSoapApi();
	            $result = $soapClient->runTransaction($this->_request);
	        } catch (Exception $e) {
	        	$this->_unsetSessionVars(); 
	           Mage::throwException('Soap Call Failed: '.$e->getMessage());
	        }
	    return $result;
    }
    
    public function validatePayerAuthentication($encMD, $PaRes,$payment)
    {
    	$md = explode('|',Mage::helper('core')->decrypt($encMD));
    	
    	$coSession = Mage::getSingleton('core/session');
    	$additionalData=Mage::getSingleton('checkout/session')->getAdditionalData();

    	$this->_request='';
    	$error = false;
        $soapClient = $this->getSoapApi();
                
    	//Build Request    
        $this->iniRequest();
        $action=$this->getConfigData('payment_action');
        //if($action==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE){
        	//Auth Only
            $requestId = Mage::getSingleton('checkout/session')->getCyberSourceLastRequestId();
        	$this->addCcAuthFlag($requestId);
        /*}elseif($action==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE){
       		//Capture
       		    	if(array_key_exists('authorize_response',$additionalData))
    		{
    	    	$csToken=$additionalData['authorize_response']->requestToken;
    	    	$authRequestId = $additionalData['authorize_response']->requestID;	
    		}	
       		$ccCaptureService = new stdClass();
            $ccCaptureService->run = "true";
            $ccCaptureService->authRequestToken = $csToken;
            $ccCaptureService->authRequestID = $authRequestId;
            $this->_request->ccCaptureService = $ccCaptureService;
        }*/
        
        //Retrive billnig data
        $this->_request->billTo = $coSession->getBilling();
        //Retrieve Shipping Data
        $this->_request->shipTo = $coSession->getShipping();
        //Retrieve Order Totals
        $this->_request->purchaseTotals= $coSession->getPurchaseTotals();
        $this->addCcInfo($payment);
        $this->_request->card->accountNumber = $md[0];
        $this->_request->card->cvNumber =  $md[1];

        //$this->_request->orderRequestToken=$csToken;
	    $this->addPayerAuthValidateFlag($PaRes,$requestId);
	 		
        //End Request Build
		
	    $result=$this->_executeSoapCall();


	    $additionalData['validate_payerauth_response']=$result;
	    Mage::getSingleton('checkout/session')->setAdditionalData($additionalData);
	    return $result;
    }
    
        public function caputureAfterPayerAuth($encMD, $PaRes,$payment)
    	{
    	$md = explode('|',Mage::helper('core')->decrypt($encMD));
    	
    	$coSession = Mage::getSingleton('core/session');
    	$additionalData=Mage::getSingleton('checkout/session')->getAdditionalData();
    	$this->_request='';
    	$error = false;
        $soapClient = $this->getSoapApi();
                
    	//Build Request    
        $this->iniRequest();

    	$csToken=$additionalData['validate_payerauth_response']->requestToken;
    	$authRequestId = $additionalData['validate_payerauth_response']->requestID;	
    			
        $ccCaptureService = new stdClass();
        $ccCaptureService->run = "true";
        $ccCaptureService->authRequestToken = $csToken;
        $ccCaptureService->authRequestID = $authRequestId;
        $this->_request->ccCaptureService = $ccCaptureService;
       
        
        //Retrive billnig data
        $this->_request->billTo = $coSession->getBilling();
        //Retrieve Shipping Data
        $this->_request->shipTo = $coSession->getShipping();
        //Retrieve Order Totals
        $this->_request->purchaseTotals= $coSession->getPurchaseTotals();
        $this->addCcInfo($payment);
        
        $this->_request->card->accountNumber = $md[0];
        $this->_request->card->cvNumber =  $md[1];

        //$this->_request->orderRequestToken=$csToken;
	    //$this->addPayerAuthValidateFlag($PaRes);
	 		
        //End Request Build
		
        $result=$this->_executeSoapCall();
        $additionalData['capture_response']=$result;
        Mage::getSingleton('checkout/session')->setAdditionalData($additionalData);
        return $result;
    }
    
    
    protected function _processResult($payment,$result,$tag=NULL)
    {

			$this->_returnParam='';
    		$_SESSION["cyberscoure_cardtype"] = '';
			$_SESSION["cybersource_total"] = '';
			$_SESSION["cybersource_currency"] = '';  	
			$additionalData=array();
			$additionalData[$tag]=$result;
			$additionalData['purchase_total']=$this->_request->purchaseTotals->grandTotalAmount;
		    //$payment->setAdditionalData(serialize($additionalData))->save();

		//all is good the response code is 100
    	if ($result->reasonCode==self::RESPONSE_CODE_SUCCESS && $result->decision=='ACCEPT') {
    	       // Remove any latent redirect URL from the session data
    	       $this->getCheckout()->setRedirectUrl('');

                $payment->setLastTransId($result->requestID)
                		->setLastCybersourceToken($result->requestToken)
                    	->setCcTransId($result->requestID)
                    	->setCybersourceToken($result->requestToken)
                    	->setCcAvsStatus($result->ccAuthReply->avsCode);
                /*
                 * checking if we have cvCode in response bc
                 * if we don't send cvn we don't get cvCode in response
                 */
                if (isset($result->ccAuthReply->cvCode)) {
                    $payment->setCcCidStatus($result->ccAuthReply->cvCode);
            	}
            	$payment->setStatus(self::STATUS_APPROVED);	
				$payment->setAdditionalData(serialize($additionalData));
				Mage::getSingleton('checkout/session')->setAdditionalData(serialize($additionalData));
				Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    			Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    			Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');

    			Mage::getSingleton('checkout/type_onepage')->saveOrderSuccess($payment->getOrder());
    			
         }elseif($result->reasonCode==self::RESPONSE_CODE_ENROLLED && $result->decision=='REJECT'){
         	//THIS IS WHEN A CARD HAS BEEN CHECKED FOR ENROLLMENT 
         
            // 3D Secure Data
        	$sPAReq = 	$result->payerAuthEnrollReply->paReq;
        	//$sMd = 		$result->payerAuthEnrollReply->xid;
        	
        	
        	$encryptionHandler = $this->getInfoInstance();
        	$sMd = Mage::helper('core')->encrypt($payment->getData('cc_number') . '|' . $payment->getData('cc_cid'));
        	$sACSURL = 	$result->payerAuthEnrollReply->acsURL;
        	$session = Mage::getSingleton('checkout/session');
				//$session->getQuote()->setIsActive(true)->save();
        		Mage::getSingleton('checkout/session')
        										->setAcsurl($result->payerAuthEnrollReply->acsURL)
	  											->setPareq($result->payerAuthEnrollReply->paReq)
  												->setCybersourceQuoteId($session->getQuoteId())
                								->setCybersourceSuccessQuoteId($session->getLastSuccessQuoteId())
                								->setCybersourceRealOrderId($session->getLastRealOrderId())
												->setAdditionalData($additionalData);
			Mage::getSingleton('checkout/session')->setCyberSourceLastRequestId($result->requestID);
			Mage::getSingleton('checkout/session')->setCyberSourceLastRequestToken($result->requestToken);
			
			$this->_returnParam = '3DSECURE|?ACSURL='.$sACSURL."|MD=".urlencode($sMd)."|PaReq=".$sPAReq."|TermURL=".$this->getTermURL()."?tokenid=".$result->requestToken."|".$this->get3DSecureURL();
         	$payment->setLastTransId($result->requestID)
                    ->setCcTransId($result->requestID)
                    ->setCybersourceToken($result->requestToken);
            if(isset($result->ccAuthReply->avsCode)){$payment->setCcAvsStatus($result->ccAuthReply->avsCode);}
                /*
                 * checking if we have cvCode in response bc
                 * if we don't send cvn we don't get cvCode in response
                 */
                if (isset($result->ccAuthReply->cvCode)) {
                    $payment->setCcCidStatus($result->ccAuthReply->cvCode);
                }     
			$_SESSION["cyberscoure_cardtype"] = $this->_request->card->cardType;
			$_SESSION["cybersource_total"] = $this->_request->purchaseTotals->grandTotalAmount;
			$_SESSION["cybersource_currency"] = $this->_request->purchaseTotals->currency;
         }elseif($result->reasonCode==self::RESPONSE_CODE_AVSFAIL && $result->decision=='REJECT'){
         
         //AVS FAILED BUT CARD OK SO DO WHAT THE CONFIG SAYS CARD NOT ENROLLED
         	$fail3d_status=Mage::getStoreConfig('payment/cybersource_soap/fail_avs_order_status');
         	if($fail3d_status!='nosave')
         	{
         	
         	if($result->requestID)
         	{
         	mage::log('AVS FAIL CALLED NO 3d Secure ' . $result->requestID);
         	}
         
         	
         		// Save order as normal
    	       $this->getCheckout()->setRedirectUrl('');

                $payment->setLastTransId($result->requestID)
                		->setLastCybersourceToken($result->requestToken)
                    	->setCcTransId($result->requestID)
                    	->setCybersourceToken($result->requestToken)
                    	->setCcAvsStatus($result->ccAuthReply->avsCode);
                /*
                 * checking if we have cvCode in response bc
                 * if we don't send cvn we don't get cvCode in response
                 */
                if (isset($result->ccAuthReply->cvCode)) {
                    $payment->setCcCidStatus($result->ccAuthReply->cvCode);
            	}
            	$payment->setStatus(self::STATUS_APPROVED);
				$payment->setAdditionalData(serialize($additionalData));
				Mage::getSingleton('checkout/session')->setAdditionalData(serialize($additionalData));
				Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_id');
    			Mage::getSingleton('checkout/session')->unsetData('cyber_source_last_request_token');
    			Mage::getSingleton('checkout/session')->unsetData('merchant_reference_code');

    			Mage::getSingleton('checkout/type_onepage')->saveOrderSuccess($payment->getOrder());

    			
    			
         	}else{
         		Mage::throwException('Sorry your billing address does not match that on your card, please check the first line of the address and the postcode and try again.');
         		$this->_unsetSessionVars();    
         	}
         }else{
         	//improve this error reporting with actual error codes.
         	$errorResponses = $this->getErrorCodes();
         	$errorResponse = 'Sorry an unknown error has occurred with your payment, please call us. '. $result->reasonCode;
         	if(key_exists($result->reasonCode, $errorResponses))
         	{
         		$errorResponse = $errorResponses[$result->reasonCode];
         	}
         	
            Mage::throwException($errorResponse);
            
            $this->_unsetSessionVars();    
         } 
         $this->getCheckout()->setParams($this->_returnParam);      
    }
       
	protected function getTermURL()
    {
   		return Mage::getURL('cybersource/payment/authresult', array('_forced_secure'=>true));
   		//return str_replace('http://','https://',Mage::getURL('cybersource/payment/authresult'));
    }
    
    
 	protected function get3DSecureURL()
    {
   		return Mage::getURL('cybersource/payment/cybersourcepayerauthform', array('_forced_secure'=>true));
   		//return Mage::getURL('cybersource/payment/cybersourcePayerAuthSubmitDebug');
    }
    
	public function getCheckout()
    {
       return Mage::getSingleton('checkout/session');
    }
    
	/**
     * Authorizing payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return Mage_Cybersource_Model_Soap
     */  
    public function authorize(Varien_Object $payment, $amount)
    {    	
    	$error = false;
    	//Build Request    
        $this->iniRequest();
		$this->addCcAuthFlag();
	    $this->addPayerAuthFlag($payment,false);//Will automatically check is supported for 3d secure  

        $this->addBillingAddress($payment->getOrder()->getBillingAddress(), $payment->getOrder()->getCustomerEmail());
        $this->addShippingAddress($payment->getOrder()->getShippingAddress());
        $this->addCcInfo($payment);
       	$this->addPurchaseTotals($payment,$amount);
        //End Request Build
        
        //first try and execute the soap call if an execption occours its a cybersource fault
		try{
			$result = $this->_executeSoapCall();
		}catch(Exception $e){
			Mage::throwException(
                Mage::helper('cybersource')->__($e->getMessage())
            );
        }
        
        //request fine so try the auth
       try{
			$rtn = $this->_processResult($payment,$result,'authorize_response');
		}catch(Exception $e){
			Mage::throwException(
                Mage::helper('cybersource')->__('There has been an error processing your payment: %s', $e->getMessage())
            );
        }

        return $rtn;
    }
    
    /**
     * Capturing payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return Mage_Cybersource_Model_Soap
     */
    public function capture(Varien_Object $payment, $amount)
    {
    	$error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();
        if ($payment->getCcTransId() && $payment->getCybersourceToken()) {
            $ccCaptureService = new stdClass();
            $ccCaptureService->run = "true";
            $ccCaptureService->authRequestToken = $payment->getCybersourceToken();
            $ccCaptureService->authRequestID = $payment->getCcTransId();
            $this->_request->ccCaptureService = $ccCaptureService;

            $item0 = new stdClass();
            $item0->unitPrice = $amount;
            $item0->id = 0;
            $this->_request->item = array($item0);
        } else {
			$this->addCcAuthFlag();
	    	$this->addPayerAuthFlag($payment,false);//Will automatically check is supported for 3d secure
			
            $ccCaptureService = new stdClass();
            $ccCaptureService->run = "true";
            $this->_request->ccCaptureService = $ccCaptureService;

            $this->addBillingAddress($payment->getOrder()->getBillingAddress(), $payment->getOrder()->getCustomerEmail());
            $this->addShippingAddress($payment->getOrder()->getShippingAddress());
            $this->addCcInfo($payment);
			$this->addPurchaseTotals($payment,$amount);
        }
        try {
        	$result = $this->_executeSoapCall();
			$this->_processResult($payment,$result,'authorize_response');
        } catch (Exception $e) {
           Mage::throwException(
                Mage::helper('cybersource')->__('Gateway request error: %s', $e->getMessage())
            );
        }
        if ($error !== false) {
            Mage::throwException($error);
        }
        return $this;
    }

   /**
     * To assign transaction id and token after capturing payment
     *
     * @param Mage_Sale_Model_Order_Invoice $invoice
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Mage_Cybersource_Model_Soap
     */
    public function processInvoice($invoice, $payment)
    {
        parent::processInvoice($invoice, $payment);
        $invoice->setTransactionId($payment->getLastTransId());
        $invoice->setCybersourceToken($payment->getLastCybersourceToken());
        return $this;
    }

   /**
     * To assign transaction id and token before voiding the transaction
     *
     * @param Mage_Sale_Model_Order_Invoice $invoice
     * @param Mage_Sale_Order_Payment $payment
     * @return Mage_Cybersource_Model_Soap
     */
    public function processBeforeVoid($invoice, $payment)
    {
        parent::processBeforeVoid($invoice, $payment);
        $payment->setVoidTransactionId($document->getTransactionId());
        $payment->setVoidCybersourceToken($invoice->getCybersourceToken());
        return $this;
    }

   /**
     * Void the payment transaction
     *
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Mage_Cybersource_Model_Soap
     */
    public function void(Varien_Object $payment)
    {
        $error = false;
        if ($payment->getVoidTransactionId() && $payment->getVoidCybersourceToken()) {
            $soapClient = $this->getSoapApi();
            $this->iniRequest();
            $voidService = new stdClass();
            $voidService->run = "true";
            $voidService->voidRequestToken = $payment->getVoidCybersourceToken();
            $voidService->voidRequestID = $payment->getVoidTransactionId();
            $this->_request->voidService = $voidService;
            try {
                $result = $soapClient->runTransaction($this->_request);
                if ($result->reasonCode==self::RESPONSE_CODE_SUCCESS) {
                    $payment->setLastTransId($result->requestID)
                        ->setCcTransId($result->requestID)
                        ->setCybersourceToken($result->requestToken)
                        ;
                } else {
                     $error = Mage::helper('cybersource')->__('There is an error in processing payment. Please try again or contact us.');
                }
            } catch (Exception $e) {
               Mage::throwException(
                    Mage::helper('cybersource')->__('Gateway request error: %s', $e->getMessage())
                );
            }
         }else{
            $error = Mage::helper('cybersource')->__('Invalid transaction id or token');
        }
        if ($error !== false) {
            Mage::throwException($error);
        }
        return $this;
    }

   /**
     * To assign correct transaction id and token before refund
     *
     * @param Mage_Sale_Model_Order_Invoice $invoice
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Mage_Cybersource_Model_Soap
     */
    public function processBeforeRefund($invoice, $payment)
    {
        parent::processBeforeRefund($invoice, $payment);
        $payment->setRefundTransactionId($invoice->getTransactionId());
        $payment->setRefundCybersourceToken($invoice->getCybersourceToken());
        return $this;
    }

   /**
     * Refund the payment transaction
     *
     * @param Mage_Sale_Model_Order_Payment $payment
     * @param flaot $amount
     * @return Mage_Cybersource_Model_Soap
     */
    public function refund(Varien_Object $payment, $amount)
    {
        $error = false;
        if ($payment->getRefundTransactionId() && $payment->getRefundCybersourceToken() && $amount>0) {
            $soapClient = $this->getSoapApi();
            $this->iniRequest();
            $ccCreditService = new stdClass();
            $ccCreditService->run = "true";
            $ccCreditService->captureRequestToken = $payment->getCybersourceToken();
            $ccCreditService->captureRequestID = $payment->getCcTransId();
            $this->_request->ccCreditService = $ccCreditService;

            $purchaseTotals = new stdClass();
            $purchaseTotals->grandTotalAmount = $amount;
            $this->_request->purchaseTotals = $purchaseTotals;

            try {
                $result = $soapClient->runTransaction($this->_request);
                if ($result->reasonCode==self::RESPONSE_CODE_SUCCESS) {
                    $payment->setLastTransId($result->requestID)
                        ->setLastCybersourceToken($result->requestToken)
                        ;
                } else {
                     $error = Mage::helper('cybersource')->__('There is an error in processing payment. Please try again or contact us.');
                }
            } catch (Exception $e) {
               Mage::throwException(
                    Mage::helper('cybersource')->__('Gateway request error: %s', $e->getMessage())
                );
            }
        } else {
            $error = Mage::helper('cybersource')->__('Error in refunding the payment');
        }
        if ($error !== false) {
            Mage::throwException($error);
        }
        return $this;
    }


   /**
     * To assign correct transaction id and token after refund
     *
     * @param Mage_Sale_Model_Order_Creditmemo $creditmemo
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Mage_Cybersource_Model_Soap
     */
    public function processCreditmemo($creditmemo, $payment)
    {
        parent::processCreditmemo($creditmemo, $payment);
        $creditmemo->setTransactionId($payment->getLastTransId());
        $creditmemo->setCybersourceToken($payment->getLastCybersourceToken());
        return $this;
    }
    
    
    public function getOrderPlaceRedirectUrl()
    {
          $tmp = Mage::getSingleton('checkout/session');
          //if ( $tmp->getAcsurl() && $tmp->getMd() && $tmp->getPareq()) {
          	return $this->getCheckout()->getParams();
          //} else {
          //	return false;
          //}
    }
    
    public function getErrorCodes()
    {
    	//list of error responses here
    	$errorArray = array(
    	'203'=>'Sorry your card has been declined by your bank, please try a different card or check with your bank',
    	'201'=>'Your issuing bank has requested more information about the transaction, please contact them and try again',
    	'202'=>'Your credit card has expired, please enter a valid card',
    	'204'=>'You have insufficient funds on the account for this transaction',
    	'207'=>'Sorry we are unable to reach your bank to verify this transaction, please try again.',
    	'210'=>'Your card has reached its credit limit and this transaction cannot be processed',
    	'211'=>'Your CVN (3 digit code) is invalid, please amend and try again',
    	'230'=>'Your CVN (3 digit code) is invalid, please amend and try again'
    	);
    	return $errorArray;
    }
}