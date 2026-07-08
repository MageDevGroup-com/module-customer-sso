<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Controller\Sso;

use MageDevGroup\CustomerSso\Model\CustomerProvisioner;
use MageDevGroup\CustomerSso\Model\CustomerSessionCreator;
use MageDevGroup\CustomerSso\Model\GroupAssigner;
use MageDevGroup\CustomerSso\Model\Oidc\CallbackHandler;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Handles the storefront OIDC redirect back from the IdP: surfaces IdP error
 * responses, then hands the `code`/`state` to {@see CallbackHandler} for a verified,
 * provider-agnostic identity.
 *
 * A public storefront action (the shopper is not yet logged in). On success it
 * resolves the customer — by IdP subject, then email under the auto-link policy,
 * else JIT create — assigns its customer group from the IdP groups, and establishes
 * the storefront session, then lands the shopper in the account area. Any failure is
 * turned into a login-page error message so the shopper is never left on a broken
 * page.
 */
class Callback implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param CallbackHandler $callbackHandler
     * @param CustomerProvisioner $customerProvisioner
     * @param GroupAssigner $groupAssigner
     * @param CustomerSessionCreator $customerSessionCreator
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CallbackHandler $callbackHandler,
        private readonly CustomerProvisioner $customerProvisioner,
        private readonly GroupAssigner $groupAssigner,
        private readonly CustomerSessionCreator $customerSessionCreator,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process the IdP callback and route the browser accordingly.
     *
     * On success the shopper lands in the account area; any failure returns to the
     * customer login page with a message.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $error = (string)$this->request->getParam('error');
        if ($error !== '') {
            $this->messageManager->addErrorMessage($this->describeIdpError());
            return $this->toLogin();
        }

        $code = (string)$this->request->getParam('code');
        $state = (string)$this->request->getParam('state');
        if ($code === '' || $state === '') {
            $this->messageManager->addErrorMessage(
                __('The SSO sign-in response was incomplete. Please try again.')
            );
            return $this->toLogin();
        }

        try {
            $identity = $this->callbackHandler->handle($code, $state);
            $customer = $this->customerProvisioner->provision($identity);
            $customer = $this->groupAssigner->assign($customer, $identity);
            $this->customerSessionCreator->create($customer);
        } catch (LocalizedException $e) {
            // Storefront session messages render as raw HTML. This module's own
            // exceptions carry safe static text, but repository/validation exceptions
            // (e.g. from customerRepository->save on a JIT create) can embed the
            // IdP-supplied email/name — escape to prevent reflected XSS.
            $this->messageManager->addErrorMessage($this->escaper->escapeHtml($e->getMessage()));
            return $this->toLogin();
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not complete SSO sign-in. Please try again or contact the store owner.')
            );
            return $this->toLogin();
        }

        return $this->redirectTo('customer/account');
    }

    /**
     * Redirect back to the customer login page.
     *
     * @return Redirect
     */
    private function toLogin(): Redirect
    {
        return $this->redirectTo('customer/account/login');
    }

    /**
     * Build a redirect result to the given storefront path.
     *
     * @param string $path
     * @return Redirect
     */
    private function redirectTo(string $path): Redirect
    {
        $result = $this->resultRedirectFactory->create();
        $result->setPath($path);

        return $result;
    }

    /**
     * Build a user-facing message from the IdP's `error`/`error_description` params.
     */
    private function describeIdpError(): Phrase
    {
        $description = trim((string)$this->request->getParam('error_description'));
        $detail = $description !== '' ? $description : (string)$this->request->getParam('error');

        // Storefront session messages are rendered as raw HTML, so escape the
        // attacker-controllable IdP params here to prevent reflected XSS.
        return __('The identity provider rejected the sign-in: %1', $this->escaper->escapeHtml($detail));
    }
}
