<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PublicLocaleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 20], KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if ($route !== 'landing' && $route !== 'support' && !str_starts_with($route, 'monitoring') && !str_starts_with($route, 'guide_')) {
            return;
        }

        $explicit = $request->query->all()['lang'] ?? null;
        $saved = $request->cookies->get('site_language');
        $locale = in_array($explicit, ['ru', 'en'], true) ? $explicit
            : (in_array($saved, ['ru', 'en'], true) ? $saved
                : (trim($request->headers->get('Accept-Language', '')) !== '' ? $request->getPreferredLanguage(['en', 'ru']) : 'ru'));

        $request->setLocale($locale);
        $request->attributes->set('_public_locale', true);
        $request->attributes->set('_save_language', in_array($explicit, ['ru', 'en'], true));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->get('_public_locale')) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Content-Language', $request->getLocale());
        $response->setVary(['Accept-Language', 'Cookie'], false);
        if ($request->attributes->get('_save_language')) {
            $response->headers->setCookie(Cookie::create('site_language', $request->getLocale(), new \DateTimeImmutable('+180 days'))
                ->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite('lax'));
        }
        if ($request->attributes->get('_save_language') || $request->cookies->has('site_language')) {
            $response->setPrivate();
        }
    }
}
