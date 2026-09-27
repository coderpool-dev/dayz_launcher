<?php

namespace App\Controller\Admin;

use App\Entity\SponsorServer;
use App\ServerList\ServerListPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class SponsorServerCrudController extends AbstractCrudController
{
    public function __construct(private readonly ServerListPublisher $publisher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return SponsorServer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('спонсорский сервер')
            ->setEntityLabelInPlural('Спонсорские серверы')
            ->setDefaultSort(['priority' => 'DESC', 'title' => 'ASC'])
            ->setHelp('index', 'Спонсорские серверы показываются в лаунчере первыми (с плашкой AD), в порядке приоритета. Сервер определяется по IP и порту — подходит и query-, и игровой порт. Изменения видны в лаунчере при следующем обновлении списка.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Название')->setHelp('Для себя: проект, владелец, контакт.');
        yield TextField::new('ip', 'IP');
        yield IntegerField::new('port', 'Порт')->setHelp('Query- или игровой порт сервера (как в адресе ip:port).');
        yield IntegerField::new('priority', 'Приоритет')->setHelp('Чем больше число, тем выше сервер среди спонсоров.');
        yield BooleanField::new('active', 'Активен');
        yield DateTimeField::new('activeUntil', 'Действует до')->setHelp('Пусто — бессрочно.');
        yield DateTimeField::new('createdAt', 'Добавлен')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->publisher->publish();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->publisher->publish();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        $this->publisher->publish();
    }
}