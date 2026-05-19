<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

use Psr\Container\ContainerInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\AmazonSqsTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\AmqpTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\BeanstalkdTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\DefaultTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\DoctrineTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\InMemoryTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\ListableReceiverAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\RedisTransportAdapter;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Resolves a Symfony Messenger transport name to the right adapter. The
 * receiver locator (Symfony service) maps name → receiver instance; we then
 * dispatch on receiver class via instanceof checks.
 *
 * Redis is detected by FQCN rather than instanceof because symfony/redis-messenger
 * is an optional dependency — the class may not exist at all in installs that
 * don't use Redis transports.
 */
final class TransportAdapterFactory
{
    /** @var array<string, TransportAdapterInterface> */
    private array $cache = [];

    public function __construct(
        private readonly ContainerInterface $receiverLocator,
    ) {
    }

    public function for(string $name): TransportAdapterInterface
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        $receiver = $this->receiverLocator->get($name);

        return $this->cache[$name] = $this->resolve($name, $receiver);
    }

    private function resolve(string $name, ReceiverInterface $receiver): TransportAdapterInterface
    {
        if ($this->isDoctrineTransport($receiver) && $receiver instanceof ListableReceiverInterface) {
            return new DoctrineTransportAdapter($name, $receiver);
        }
        if ($this->isAmqpTransport($receiver)) {
            return new AmqpTransportAdapter($name, $receiver);
        }
        if ($this->isRedisTransport($receiver)) {
            return new RedisTransportAdapter($name, $receiver);
        }
        if ($this->isBeanstalkdTransport($receiver)) {
            return new BeanstalkdTransportAdapter($name, $receiver);
        }
        if ($this->isAmazonSqsTransport($receiver)) {
            return new AmazonSqsTransportAdapter($name, $receiver);
        }
        if ($receiver instanceof InMemoryTransport) {
            return new InMemoryTransportAdapter($name, $receiver);
        }
        if ($receiver instanceof SyncTransport) {
            return new DefaultTransportAdapter($name, $receiver, 'sync');
        }
        if ($receiver instanceof ListableReceiverInterface) {
            return new ListableReceiverAdapter($name, $receiver);
        }

        return new DefaultTransportAdapter($name, $receiver);
    }

    /**
     * Detect a Doctrine transport regardless of whether the receiver_locator
     * returned the wrapping `DoctrineTransport` (the usual case) or just the
     * inner `DoctrineReceiver`. We match on FQCN because the namespace path
     * is stable and we don't want a hard dependency on
     * symfony/doctrine-messenger in bundles that don't use it.
     */
    private function isDoctrineTransport(ReceiverInterface $receiver): bool
    {
        return $this->isInstanceOfAny($receiver, [
            \Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport::class,
            \Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceiver::class,
        ]);
    }

    private function isAmqpTransport(ReceiverInterface $receiver): bool
    {
        return $this->isInstanceOfAny($receiver, [
            \Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport::class,
            \Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceiver::class,
        ]);
    }

    private function isRedisTransport(ReceiverInterface $receiver): bool
    {
        return $this->isInstanceOfAny($receiver, [
            'Symfony\\Component\\Messenger\\Bridge\\Redis\\Transport\\RedisTransport',
            'Symfony\\Component\\Messenger\\Bridge\\Redis\\Transport\\RedisReceiver',
        ]);
    }

    private function isBeanstalkdTransport(ReceiverInterface $receiver): bool
    {
        return $this->isInstanceOfAny($receiver, [
            'Symfony\\Component\\Messenger\\Bridge\\Beanstalkd\\Transport\\BeanstalkdTransport',
            'Symfony\\Component\\Messenger\\Bridge\\Beanstalkd\\Transport\\BeanstalkdReceiver',
        ]);
    }

    private function isAmazonSqsTransport(ReceiverInterface $receiver): bool
    {
        return $this->isInstanceOfAny($receiver, [
            'Symfony\\Component\\Messenger\\Bridge\\AmazonSqs\\Transport\\AmazonSqsTransport',
            'Symfony\\Component\\Messenger\\Bridge\\AmazonSqs\\Transport\\AmazonSqsReceiver',
        ]);
    }

    /** @param list<class-string> $fqcns */
    private function isInstanceOfAny(object $obj, array $fqcns): bool
    {
        foreach ($fqcns as $fqcn) {
            if (class_exists($fqcn) && $obj instanceof $fqcn) {
                return true;
            }
        }

        return false;
    }
}
