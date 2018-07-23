<?php

declare(strict_types=1);

namespace spec\Sylius\RefundPlugin\CommandHandler;

use PhpSpec\ObjectBehavior;
use Prooph\ServiceBus\EventBus;
use Prophecy\Argument;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Checker\OrderFullyRefundedTotalCheckerInterface;
use Sylius\RefundPlugin\Checker\OrderRefundingAvailabilityCheckerInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Event\UnitsRefunded;
use Sylius\RefundPlugin\Exception\OrderNotAvailableForRefundingException;
use Sylius\RefundPlugin\Refunder\RefunderInterface;
use Sylius\RefundPlugin\Provider\RefundedUnitTotalProviderInterface;
use Sylius\RefundPlugin\StateResolver\OrderFullyRefundedStateResolverInterface;

final class RefundUnitsHandlerSpec extends ObjectBehavior
{
    function let(
        RefunderInterface $orderItemUnitsRefunder,
        RefunderInterface $orderShipmentsRefunder,
        OrderRefundingAvailabilityCheckerInterface $orderRefundingAvailabilityChecker,
        EventBus $eventBus,
        OrderRepositoryInterface $orderRepository,
        OrderFullyRefundedTotalCheckerInterface $orderFullyRefundedTotalChecker,
        OrderFullyRefundedStateResolverInterface $orderFullyRefundedStateResolver
    ): void {
        $this->beConstructedWith(
            $orderItemUnitsRefunder,
            $orderShipmentsRefunder,
            $orderRefundingAvailabilityChecker,
            $eventBus,
            $orderRepository,
            $orderFullyRefundedTotalChecker,
            $orderFullyRefundedStateResolver
        );
    }

    function it_handles_command_and_create_refund_for_each_refunded_unit(
        OrderRefundingAvailabilityCheckerInterface $orderRefundingAvailabilityChecker,
        RefunderInterface $orderItemUnitsRefunder,
        RefunderInterface $orderShipmentsRefunder,
        EventBus $eventBus,
        OrderInterface $order,
        OrderRepositoryInterface $orderRepository,
        OrderFullyRefundedTotalCheckerInterface $orderFullyRefundedTotalChecker
    ): void {
        $orderRefundingAvailabilityChecker->__invoke('000222')->willReturn(true);

        $orderItemUnitsRefunder->refundFromOrder([1, 3], '000222')->willReturn(3000);
        $orderShipmentsRefunder->refundFromOrder([3, 4], '000222')->willReturn(4000);

        $eventBus->dispatch(Argument::that(function (UnitsRefunded $event): bool {
            return
                $event->orderNumber() === '000222' &&
                $event->unitIds() === [1, 3] &&
                $event->shipmentIds() === [3, 4] &&
                $event->amount() === 7000
            ;
        }))->shouldBeCalled();

        $orderRepository->findOneByNumber('000222')->willReturn($order);
        $orderFullyRefundedTotalChecker->check($order)->willReturn(false);

        $this(new RefundUnits('000222', [1, 3], [3, 4]));
    }

    function it_changes_order_state_to_fully_refunded_when_whole_order_total_is_refunded(
        RefunderInterface $orderItemUnitsRefunder,
        RefunderInterface $orderShipmentsRefunder,
        RefundedUnitTotalProviderInterface $refundedUnitTotalProvider,
        OrderRefundingAvailabilityCheckerInterface $orderRefundingAvailabilityChecker,
        EventBus $eventBus,
        OrderInterface $order,
        OrderRepositoryInterface $orderRepository,
        OrderFullyRefundedTotalCheckerInterface $orderFullyRefundedTotalChecker,
        OrderFullyRefundedStateResolverInterface $orderFullyRefundedStateResolver
    ): void {
        $orderRefundingAvailabilityChecker->__invoke('000222')->willReturn(true);

        $orderItemUnitsRefunder->refundFromOrder([1, 3], '000222')->willReturn(1000);
        $orderShipmentsRefunder->refundFromOrder([3, 4], '000222')->willReturn(500);

        $eventBus->dispatch(Argument::that(function (UnitsRefunded $event): bool {
            return
                $event->orderNumber() === '000222' &&
                $event->unitIds() === [1, 3] &&
                $event->amount() === 1500
            ;
        }))->shouldBeCalled();

        $orderRepository->findOneByNumber('000222')->willReturn($order);
        $orderFullyRefundedTotalChecker->check($order)->willReturn(true);

        $orderFullyRefundedStateResolver->resolve($order)->shouldBeCalled();

        $this(new RefundUnits('000222', [1, 3], [3, 4]));
    }

    function it_throws_an_exception_if_order_is_not_available_for_refund(
        OrderRefundingAvailabilityCheckerInterface $orderRefundingAvailabilityChecker
    ): void {
        $orderRefundingAvailabilityChecker->__invoke('000222')->willReturn(false);

        $this
            ->shouldThrow(OrderNotAvailableForRefundingException::class)
            ->during('__invoke', [new RefundUnits('000222', [1, 3], [3, 4])])
        ;
    }
}
