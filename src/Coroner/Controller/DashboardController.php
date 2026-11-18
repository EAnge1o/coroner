<?php

namespace App\Coroner\Controller;

use App\Coroner\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Twig\Environment;

/**
 * The public face of the demo app: one page showing the morgue and the
 * graveyard, so judges (and the 90-second video) can see the state without
 * touching the terminal. The MCP tools operate on exactly this data.
 */
#[AsController]
final readonly class DashboardController
{
    private const FAILURE_TRANSPORT = 'failed';

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'messenger.receiver_locator')]
        private ServiceProviderInterface $receiverLocator,
        private Environment $twig,
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function __invoke(): Response
    {
        $failed = [];
        $receiver = $this->receiverLocator->get(self::FAILURE_TRANSPORT);
        if ($receiver instanceof ListableReceiverInterface) {
            foreach ($receiver->all(50) as $envelope) {
                $error = $envelope->last(ErrorDetailsStamp::class);
                $failed[] = [
                    'id' => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
                    'class' => $envelope->getMessage()::class,
                    'payload' => json_encode($envelope->getMessage(), JSON_UNESCAPED_SLASHES),
                    'error' => $error?->getExceptionMessage(),
                    'exception_class' => $error?->getExceptionClass(),
                    'retries' => \count($envelope->all(RedeliveryStamp::class)),
                ];
            }
        }

        return new Response($this->twig->render('dashboard.html.twig', [
            'orders' => $this->em->getRepository(Order::class)->findAll(),
            'failed' => $failed,
        ]));
    }
}
