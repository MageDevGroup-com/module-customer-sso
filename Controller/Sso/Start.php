<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Controller\Sso;

use MageDevGroup\CustomerSso\Model\Oidc\AuthorizationStarter;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts the storefront OIDC login: redirects the browser to the IdP
 * authorization URL built by {@see AuthorizationStarter}.
 *
 * A public storefront action (the login page is already unauthenticated). When
 * SSO is disabled or misconfigured the starter throws and the shopper is sent
 * back to the native customer login with an error message, so the login page is
 * never left in a broken state.
 */
class Start implements HttpGetActionInterface
{
    /**
     * @param AuthorizationStarter $authorizationStarter
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationStarter $authorizationStarter,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Redirect to the IdP, or back to the customer login on failure.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $result = $this->resultRedirectFactory->create();

        try {
            return $result->setUrl($this->authorizationStarter->start());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not start SSO sign-in. Please try again or contact the store owner.')
            );
        }

        return $result->setPath('customer/account/login');
    }
}
