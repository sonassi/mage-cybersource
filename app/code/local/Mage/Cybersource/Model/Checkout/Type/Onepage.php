<?php


class Mage_Cybersource_Model_Checkout_Type_Onepage extends Mage_Checkout_Model_Type_Onepage
{


    /**
     * Overiding save order function as we don't want to save order at this stage
     *
     * @return array
     */
	
 	public function saveOrder()
  {
        $paymentType = $this->getQuote()->getPayment()->getMethod();
        if($paymentType != 'cybersource_soap') return parent::saveOrder();
        
        
        $this->validateOrder();
        $billing = $this->getQuote()->getBillingAddress();
        if (!$this->getQuote()->isVirtual()) {
            $shipping = $this->getQuote()->getShippingAddress();
        }
        switch ($this->getQuote()->getCheckoutMethod()) {
        case 'guest':
            if (!$this->getQuote()->isAllowedGuestCheckout()) {
                Mage::throwException(Mage::helper('checkout')->__('Sorry, guest checkout is not enabled. Please try again or contact store owner.'));
            }
            $this->getQuote()->setCustomerEmail($billing->getEmail())
                ->setCustomerIsGuest(true)
                ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
            break;

        case 'register':
            $customer = Mage::getModel('customer/customer');
            /* @var $customer Mage_Customer_Model_Customer */

            $customerBilling = $billing->exportCustomerAddress();
            $customer->addAddress($customerBilling);

            if (!$this->getQuote()->isVirtual() && !$shipping->getSameAsBilling()) {
                $customerShipping = $shipping->exportCustomerAddress();
                $customer->addAddress($customerShipping);
            }

            if ($this->getQuote()->getCustomerDob() && !$billing->getCustomerDob()) {
                $billing->setCustomerDob($this->getQuote()->getCustomerDob());
            }

            if ($this->getQuote()->getCustomerTaxvat() && !$billing->getCustomerTaxvat()) {
                $billing->setCustomerTaxvat($this->getQuote()->getCustomerTaxvat());
            }

            Mage::helper('core')->copyFieldset('checkout_onepage_billing', 'to_customer', $billing, $customer);

            $customer->setPassword($customer->decryptPassword($this->getQuote()->getPasswordHash()));
            $customer->setPasswordHash($customer->hashPassword($customer->getPassword()));

            $this->getQuote()->setCustomer($customer);
            break;

        default:
            $customer = Mage::getSingleton('customer/session')->getCustomer();

            if (!$billing->getCustomerId() || $billing->getSaveInAddressBook()) {
                $customerBilling = $billing->exportCustomerAddress();
                $customer->addAddress($customerBilling);
            }
            if (!$this->getQuote()->isVirtual() &&
                ((!$shipping->getCustomerId() && !$shipping->getSameAsBilling()) ||
                (!$shipping->getSameAsBilling() && $shipping->getSaveInAddressBook()))) {

                $customerShipping = $shipping->exportCustomerAddress();
                $customer->addAddress($customerShipping);
            }
            $customer->setSavedFromQuote(true);
            $customer->save();

            $changed = false;
            if (isset($customerBilling) && !$customer->getDefaultBilling()) {
                $customer->setDefaultBilling($customerBilling->getId());
                $changed = true;
            }
            if (!$this->getQuote()->isVirtual() && isset($customerBilling) && !$customer->getDefaultShipping() && $shipping->getSameAsBilling()) {
                $customer->setDefaultShipping($customerBilling->getId());
                $changed = true;
            }
            elseif (!$this->getQuote()->isVirtual() && isset($customerShipping) && !$customer->getDefaultShipping()){
                $customer->setDefaultShipping($customerShipping->getId());
                $changed = true;
            }

            if ($changed) {
                $customer->save();
            }
        }

        $this->getQuote()->reserveOrderId();
        $convertQuote = Mage::getModel('sales/convert_quote');
        /* @var $convertQuote Mage_Sales_Model_Convert_Quote */
        //$order = Mage::getModel('sales/order');
        if ($this->getQuote()->isVirtual()) {
            $order = $convertQuote->addressToOrder($billing);
        }
        else {
            $order = $convertQuote->addressToOrder($shipping);
        }
        /* @var $order Mage_Sales_Model_Order */
        $order->setBillingAddress($convertQuote->addressToOrderAddress($billing));

        if (!$this->getQuote()->isVirtual()) {
            $order->setShippingAddress($convertQuote->addressToOrderAddress($shipping));
        }

        $order->setPayment($convertQuote->paymentToOrderPayment($this->getQuote()->getPayment()));
		
        foreach ($this->getQuote()->getAllItems() as $item) {
            $orderItem = $convertQuote->itemToOrderItem($item);
            if ($item->getParentItem()) {
                $orderItem->setParentItem($order->getItemByQuoteItemId($item->getParentItem()->getId()));
            }
            $order->addItem($orderItem);
        }

        /**
         * We can use configuration data for declare new order status
         */
        Mage::dispatchEvent('checkout_type_onepage_save_order', array('order'=>$order, 'quote'=>$this->getQuote()));
        // check again, if customer exists
        if ($this->getQuote()->getCheckoutMethod() == 'register') {
            if ($this->_customerEmailExists($customer->getEmail(), Mage::app()->getWebsite()->getId())) {
                Mage::throwException(Mage::helper('checkout')->__('There is already a customer registered using this email address'));
            }
        }
        
        Mage::getSingleton('checkout/session')->setSecure3d(false);
        
        $order->place();

        if ($this->getQuote()->getCheckoutMethod()=='register') {
            $customer->save();
            $customerBillingId = $customerBilling->getId();
            if (!$this->getQuote()->isVirtual()) {
                $customerShippingId = isset($customerShipping) ? $customerShipping->getId() : $customerBillingId;
                $customer->setDefaultShipping($customerShippingId);
            }
            $customer->setDefaultBilling($customerBillingId);
            $customer->save();

            $this->getQuote()->setCustomerId($customer->getId());

            $order->setCustomerId($customer->getId());
            Mage::helper('core')->copyFieldset('customer_account', 'to_order', $customer, $order);

            $billing->setCustomerId($customer->getId())->setCustomerAddressId($customerBillingId);
            if (!$this->getQuote()->isVirtual()) {
                $shipping->setCustomerId($customer->getId())->setCustomerAddressId($customerShippingId);
            }

            if ($customer->isConfirmationRequired()) {
                $customer->sendNewAccountEmail('confirmation');
            }
            else {
                $customer->sendNewAccountEmail();
            }
        }

        /**
         * a flag to set that there will be redirect to third party after confirmation
         * eg: paypal standard ipn
         */
        $redirectUrl = $this->getQuote()->getPayment()->getOrderPlaceRedirectUrl();
        if(!$redirectUrl){
            $order->setEmailSent(true);
        }

      	if(!$redirectUrl ){
      		$order->setState(
                Mage::getStoreConfig('payment/cybersource_soap/order_status'), 
                true,
               	Mage::helper('cybersource')->__('Payment Successful'),
               	true
            	);
        	$order->save();
        	//Saving Additional Data
        	$payment = $order->getPayment();
        	$payment->setAdditionalData(Mage::getSingleton('checkout/session')->getAdditionalData())
			->save();
			Mage::dispatchEvent('checkout_type_onepage_save_order_after', array('order'=>$order, 'quote'=>$this->getQuote()));
      	}

		
        

        /**
         * need to have somelogic to set order as new status to make sure order is not finished yet
         * quote will be still active when we send the customer to paypal
         */
        $this->getCheckout()->setLastQuoteId($this->getQuote()->getId());
        $this->getCheckout()->setLastOrderId($order->getId());
        $this->getCheckout()->setLastRealOrderId($order->getIncrementId());
        $this->getCheckout()->setRedirectUrl($redirectUrl);

        /**
         * we only want to send to customer about new order when there is no redirect to third party
         */
/*        if(!$redirectUrl){
        	try
        	{
            	//$order->sendNewOrderEmail();
        	}
           	catch(Exception $e)
           	{
           		Mage::logexception($e);
           	}
        }
*/
        if ($this->getQuote()->getCheckoutMethod()=='register') {
            /**
             * we need to save quote here to have it saved with Customer Id.
             * so when loginById() executes checkout/session method loadCustomerQuote
             * it would not create new quotes and merge it with old one.
             */
            $this->getQuote()->save();
            if ($customer->isConfirmationRequired()) {
                Mage::getSingleton('checkout/session')->addSuccess(Mage::helper('customer')->__('Account confirmation is required. Please, check your e-mail for confirmation link. To resend confirmation email please <a href="%s">click here</a>.',
                    Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail())
                ));
            }
            else {
                Mage::getSingleton('customer/session')->loginById($customer->getId());
            }
        }

        //Setting this one more time like control flag that we haves saved order
        //Must be checkout on success page to show it or not.
        $this->getCheckout()->setLastSuccessQuoteId($this->getQuote()->getId());

        $this->getQuote()->save();
        

		//echo $this;
        return $this;
    }
    
    public function saveOrderSuccess($order) {
      
       $this->getQuote()->setIsActive(false);
       $this->getQuote()->save();
      
       #Mage::log('Updating stock levels');             
       Mage::dispatchEvent(
            'checkout_submit_all_after',
            array('order' => $order, 'quote' => $this->getQuote(), 'recurring_profiles' => null)
        );
      
    }

    public function saveOrderAfter3dSecure()
    {
        $billing = $this->getQuote()->getBillingAddress();

        if (!$this->getQuote()->isVirtual()) {
            $shipping = $this->getQuote()->getShippingAddress();
        }

        $this->getQuote()->reserveOrderId();
        $convertQuote = Mage::getModel('sales/convert_quote');
        /* @var $convertQuote Mage_Sales_Model_Convert_Quote */
        //$order = Mage::getModel('sales/order');
        if ($this->getQuote()->isVirtual()) {
            $order = $convertQuote->addressToOrder($billing);
        }
        else {
            $order = $convertQuote->addressToOrder($shipping);
        }
        /* @var $order Mage_Sales_Model_Order */
        $order->setBillingAddress($convertQuote->addressToOrderAddress($billing));

        if (!$this->getQuote()->isVirtual()) {
            $order->setShippingAddress($convertQuote->addressToOrderAddress($shipping));
        }

        $order->setPayment($convertQuote->paymentToOrderPayment($this->getQuote()->getPayment()));

        foreach ($this->getQuote()->getAllItems() as $item) {
            $order->addItem($convertQuote->itemToOrderItem($item));
        }

        /**
         * We can use configuration data for declare new order status
         */
        Mage::dispatchEvent('checkout_type_onepage_save_order', array('order'=>$order, 'quote'=>$this->getQuote()));

        Mage::getSingleton('checkout/session')->setSecure3d(true);
        

        
        $order->setEmailSent(true);
    		$order->setState(
              Mage::getStoreConfig('payment/cybersource_soap/order_status'), 
              true,
             	Mage::helper('cybersource')->__('Payment Successful'),
             	true
          	);
        $order->save();
        /* Removed to eliminate email sending faults - moved to order success observer.
        try{
        	$order->sendNewOrderEmail();
        }catch (Exception $e)
        {
        	Mage::logException($e);
        }
        */
        
        //Saving Additional Data
        $payment = $order->getPayment();
        
        $payment->setAdditionalData(serialize(Mage::getSingleton('checkout/session')->getAdditionalData()))->save();
        
        Mage::dispatchEvent('checkout_type_onepage_save_order_after', array('order'=>$order, 'quote'=>$this->getQuote()));

        $this->getQuote()->setIsActive(false);
        $this->getQuote()->save();

        $this->getCheckout()->setLastQuoteId($this->getQuote()->getId());
        $this->getCheckout()->setLastOrderId($order->getId());
        $this->getCheckout()->setLastRealOrderId($order->getIncrementId());

        $this->saveOrderSuccess($order);
        
        return $this;
    }

}
