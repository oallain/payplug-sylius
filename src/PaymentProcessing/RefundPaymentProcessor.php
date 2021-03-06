<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Symfony\Component\HttpFoundation\Session\Session;

final class RefundPaymentProcessor implements PaymentProcessorInterface
{
    /** @var Session */
    private $session;

    /** @var PayPlugApiClientInterface */
    private $payPlugApiClient;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Session $session,
        PayPlugApiClientInterface $payPlugApiClient,
        LoggerInterface $logger
    ) {
        $this->session = $session;
        $this->payPlugApiClient = $payPlugApiClient;
        $this->logger = $logger;
    }

    public function process(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        $details = $payment->getDetails();

        if (
            PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()->getFactoryName() ||
            (isset($details['status']) && PayPlugApiClientInterface::REFUNDED === $details['status'])
        ) {
            return;
        }

        if (!isset($details['payment_id'])) {
            $this->session->getFlashBag()->add('info', 'The payment refund was made only locally.');

            return;
        }

        $this->logger->info('[PayPlug] Start refund payment', ['payment_id' => $details['payment_id']]);

        $gatewayConfig = $paymentMethod->getGatewayConfig()->getConfig();

        $this->payPlugApiClient->initialise($gatewayConfig['secretKey']);

        try {
            $this->payPlugApiClient->refundPayment($details['payment_id']);
        } catch (\Exception $exception) {
            $message = $exception->getMessage();

            $this->session->getFlashBag()->add('error', $message);

            $this->logger->error('[PayPlug] Refund Payment', ['error' => $message]);

            throw new UpdateHandlingException();
        }
    }
}
