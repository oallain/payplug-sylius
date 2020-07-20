<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor;
use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions;

final class RefundPaymentGeneratedHandler
{
    /** @var \Doctrine\Common\Persistence\ObjectManager */
    private $entityManager;

    /** @var \SM\Factory\FactoryInterface */
    private $stateMachineFactory;

    /** @var \PayPlug\SyliusPayPlugPlugin\PaymentProcessing\RefundPaymentProcessor */
    private $refundPaymentProcessor;

    /** @var \Sylius\Component\Core\Repository\PaymentRepositoryInterface */
    private $paymentRepository;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        PaymentRepositoryInterface $paymentRepository,
        FactoryInterface $stateMachineFactory,
        RefundPaymentProcessor $refundPaymentProcessor,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->paymentRepository = $paymentRepository;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->refundPaymentProcessor = $refundPaymentProcessor;
        $this->logger = $logger;
    }

    public function __invoke(RefundPaymentGenerated $message): void
    {
        try {
            /** @var \Sylius\Component\Core\Model\PaymentInterface $payment */
            $payment = $this->paymentRepository->find($message->paymentId());
            $paymentMethod = $payment->getMethod();

            if (null === $paymentMethod) {
                return;
            }

            $gatewayName = $paymentMethod->getCode();

            if ($gatewayName !== PayPlugGatewayFactory::FACTORY_NAME) {
                return;
            }

            if(!isset($payment->getDetails()['refunds'])) {
                $details = $payment->getDetails();
                $details['refunds'] = [];
                $payment->setDetails($details);
            }

            foreach($payment->getDetails()['refunds'] as $refund) {
                if($message->id() === $refund['internal_id']) {
                    return;
                }
            }

            $this->refundPaymentProcessor->processWithAmount($payment, $message->amount(), $message->id());

            /** @var RefundPayment $refundPayment */
            $refundPayment = $this->entityManager->getRepository(RefundPayment::class)->find($message->id());
            $stateMachine = $this->stateMachineFactory->get($refundPayment, RefundPaymentTransitions::GRAPH);
            $stateMachine->apply(RefundPaymentTransitions::TRANSITION_COMPLETE);

            $this->entityManager->flush();
        } catch (\Throwable $throwable) {
            $this->logger->critical($throwable->getMessage());
        }
    }
}
