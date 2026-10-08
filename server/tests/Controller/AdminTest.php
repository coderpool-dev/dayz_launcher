<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Entity\LauncherRelease;
use App\Entity\LauncherInstall;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminTest extends DatabaseWebTestCase
{
    public function testAdminRequiresLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createAdmin('admin', 'correct-password');

        $this->login('admin', 'wrong-password');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger, .invalid-feedback, .login-error, [class*="error"]');
    }

    public function testDashboardAndSectionsOpen(): void
    {
        $this->createAdmin('admin', 'correct-password');
        $this->login('admin', 'correct-password');
        self::assertResponseRedirects('/admin');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Онлайн сейчас');

        foreach (['/admin/sponsor-server', '/admin/sponsor-server/new', '/admin/launcher-release', '/admin/launcher-release/new', '/admin/play-event', '/admin/launcher-install'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testInstallationOnlineBadgesAreAccessible(): void
    {
        $this->createAdmin('admin', 'correct-password');
        $this->persist(
            new LauncherInstall('online-install', '192.0.2.1', '1.2.6', new \DateTimeImmutable()),
            new LauncherInstall('offline-install', '192.0.2.2', '1.2.5', new \DateTimeImmutable('-1 day')),
        );
        $this->login('admin', 'correct-password');
        $this->client->request('GET', '/admin/launcher-install');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.badge-boolean-true');
        self::assertSelectorExists('.badge-boolean-false');
        self::assertSelectorNotExists('.badge-danger');
    }

    public function testUploadRelease(): void
    {
        $this->createAdmin('admin', 'correct-password');
        $this->login('admin', 'correct-password');

        // Минимальный заголовок Windows-исполняемого файла: валидатор проверяет и расширение, и MIME-тип.
        $content = 'MZ' . str_repeat("\0", 58) . pack('V', 64) . "PE\0\0" . str_repeat("\0", 200);
        $installer = sys_get_temp_dir() . '/prepiska-test-setup.exe';
        file_put_contents($installer, $content);

        $crawler = $this->client->request('GET', '/admin/launcher-release/new');
        $form = $crawler->filter('form[name="LauncherRelease"]')->form();
        $form['LauncherRelease[version]'] = '2.0.0';
        $form['LauncherRelease[notes]'] = 'Новая версия';
        $form['LauncherRelease[upload]']->upload($installer);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $release = $this->em->getRepository(LauncherRelease::class)->findOneBy(['version' => '2.0.0']);
        self::assertNotNull($release);
        self::assertSame('PREPISKA-DayZ-Launcher-Setup-2.0.0.exe', $release->getFileName());
        self::assertSame(hash('sha256', $content), $release->getSha256());

        $this->client->request('GET', '/api/launcher/update', ['version' => '1.0.0']);
        self::assertSame('2.0.0', json_decode((string) $this->client->getResponse()->getContent(), true)['latestVersion']);
    }

    private function createAdmin(string $username, string $password): void
    {
        $user = new AdminUser($username);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->persist($user);
    }

    private function login(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = $username;
        $form['_password'] = $password;
        $this->client->submit($form);
    }
}
