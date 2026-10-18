<?php

namespace App\EventSubscriber;

use App\Traffic\TrafficRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Считает заходы на публичные страницы сайта: главную, мониторинг и статьи. */
final class TrafficSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIXES = ['landing', 'monitoring', 'guide_'];

    public function __construct(private readonly TrafficRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || !$request->isMethod('GET') || $event->getResponse()->getStatusCode() !== 200) {
            return;
        }

        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                $event->getResponse()->setPrivate();
                $event->getResponse()->headers->addCacheControlDirective('no-store');
                $this->recorder->recordVisit($request, $event->getResponse());

                return;
            }
        }
    }
}
