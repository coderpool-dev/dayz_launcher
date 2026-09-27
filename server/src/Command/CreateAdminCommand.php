<?php

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:create', description: 'Создать администратора или сменить ему пароль')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Логин')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Пароль (если не указан — будет сгенерирован)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = trim((string) $input->getArgument('username'));
        $password = (string) ($input->getOption('password') ?? rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'ab'), '='));

        if (mb_strlen($password) < 10) {
            $io->error('Пароль должен быть не короче 10 символов.');

            return Command::FAILURE;
        }

        $user = $this->em->getRepository(AdminUser::class)->findOneBy(['username' => $username]);
        $isNew = $user === null;
        $user ??= new AdminUser($username);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('%s: %s', $isNew ? 'Администратор создан' : 'Пароль обновлён', $username));
        if ($input->getOption('password') === null) {
            $io->writeln('Пароль: ' . $password);
        }

        return Command::SUCCESS;
    }
}
