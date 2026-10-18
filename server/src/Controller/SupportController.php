<?php

namespace App\Controller;

use App\Entity\SupportTicket;
use App\Release\ReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Форма поддержки для тех, у кого лаунчер не работает. Обращения читаются в админке. */
final class SupportController extends AbstractController
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $supportLimiter,
    ) {
    }

    #[Route('/support', name: 'support', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $values = ['name' => '', 'contact' => '', 'version' => trim((string) $request->query->get('version', '')), 'message' => ''];
        $error = null;
        $sent = $request->query->getBoolean('sent');

        if ($request->isMethod('POST')) {
            foreach (['name', 'contact', 'version', 'message'] as $field) {
                $values[$field] = trim((string) $request->request->get($field, ''));
            }
            $honeypot = trim((string) $request->request->get('website', ''));
            $limit = $this->supportLimiter->create($request->getClientIp() ?? 'unknown')->consume();

            if ($honeypot !== '') {
                // Бот: делаем вид, что всё хорошо, и ничего не сохраняем.
                return $this->redirectToRoute('support', ['sent' => 1]);
            }
            if (!$limit->isAccepted()) {
                $error = 'Слишком много обращений. Попробуйте позже.';
            } elseif (mb_strlen($values['name']) < 1 || mb_strlen($values['name']) > 100) {
                $error = 'Укажите имя (до 100 символов).';
            } elseif (mb_strlen($values['contact']) < 3 || mb_strlen($values['contact']) > 200) {
                $error = 'Укажите, как с вами связаться: Telegram, Discord или почту.';
            } elseif (mb_strlen($values['message']) < 10 || mb_strlen($values['message']) > 5000) {
                $error = 'Опишите проблему подробнее (от 10 до 5000 символов).';
            } elseif (mb_strlen($values['version']) > 20) {
                $error = 'Версия лаунчера слишком длинная.';
            } else {
                $this->em->persist(new SupportTicket(
                    $values['name'],
                    $values['contact'],
                    $values['version'] !== '' ? $values['version'] : null,
                    $values['message'],
                    $request->getClientIp() ?? 'unknown',
                    $request->headers->get('User-Agent'),
                    new \DateTimeImmutable(),
                ));
                $this->em->flush();

                return $this->redirectToRoute('support', ['sent' => 1]);
            }
        }

        $response = $this->render('landing/support.html.twig', [
            'release' => $this->releases->latestPublished(),
            'values' => $values,
            'error' => $error,
            'sent' => $sent,
        ]);
        $response->setPrivate();
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
