<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Entity\SupportTicket;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SupportTest extends DatabaseWebTestCase
{
    public function testFormSavesTicket(): void
    {
        $this->client->request('GET', '/support');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/support', [
            'name' => 'Вася', 'contact' => '@vasya', 'version' => '1.2.6', 'message' => 'Лаунчер не открывается после установки',
        ]);
        self::assertResponseRedirects('/support?sent=1');

        $tickets = $this->em->getRepository(SupportTicket::class)->findAll();
        self::assertCount(1, $tickets);
        self::assertSame('@vasya', $tickets[0]->getContact());
        self::assertSame(SupportTicket::STATUS_NEW, $tickets[0]->getStatus());
    }

    public function testInvalidAndHoneypotAreNotSaved(): void
    {
        $this->client->request('POST', '/support', ['name' => 'Вася', 'contact' => '@v', 'message' => 'коротко']);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/support', ['name' => 'Бот', 'contact' => 'bot@example.com', 'message' => 'Достаточно длинное сообщение', 'website' => 'spam']);

        self::assertCount(0, $this->em->getRepository(SupportTicket::class)->findAll());
    }

    public function testAdminReadsTicket(): void
    {
        $user = new AdminUser('admin');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'correct-password'));
        $ticket = new SupportTicket('Вася', '@vasya', null, 'Не грузятся серверы', '192.0.2.1', 'UA', new \DateTimeImmutable());
        $this->persist($user, $ticket);

        $crawler = $this->client->request('GET', '/admin/login');
        $form = $crawler->filter('form')->form(['_username' => 'admin', '_password' => 'correct-password']);
        $this->client->submit($form);

        $this->client->request('GET', '/admin/support-ticket');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Не грузятся серверы');

        $this->client->request('GET', '/admin/support-ticket/'.$ticket->getId());
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame(SupportTicket::STATUS_READ, $this->em->find(SupportTicket::class, $ticket->getId())->getStatus());
    }
}
