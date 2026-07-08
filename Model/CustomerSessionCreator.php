<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;

/**
 * Establishes an authenticated storefront session for a provisioned customer,
 * without a password — the identity was already verified by the OIDC callback.
 *
 * Delegates to {@see Session::setCustomerDataAsLoggedIn()}, which regenerates the
 * session id, flags the HTTP auth context, and fires the `customer_login` /
 * `customer_data_object_login` events so core listeners react exactly as they
 * would for a native storefront login.
 *
 * Mirrors {@see \MageDevGroup\AdminSso\Model\AdminSessionCreator} for the customer
 * domain.
 */
class CustomerSessionCreator
{
    /**
     * @param Session $customerSession
     */
    public function __construct(
        private readonly Session $customerSession
    ) {
    }

    /**
     * Log the given customer into the current storefront session.
     *
     * @param CustomerInterface $customer persisted customer to authenticate
     */
    public function create(CustomerInterface $customer): void
    {
        $this->customerSession->setCustomerDataAsLoggedIn($customer);
    }
}
