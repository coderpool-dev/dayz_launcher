<?php

namespace App\Controller\Admin;

use App\Entity\LauncherInstall;
use App\Stats\StatsQuery;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/** Установки лаунчера: последняя активность, IP, версия (только просмотр). */
final class LauncherInstallCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LauncherInstall::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('установка')
            ->setEntityLabelInPlural('Установки лаунчера')
            ->setDefaultSort(['lastSeenAt' => 'DESC'])
            ->setSearchFields(['launcherId', 'lastIp', 'version'])
            ->setHelp('index', sprintf('Онлайн — лаунчер присылал сигнал за последние %d минут.', StatsQuery::ONLINE_WINDOW_MINUTES));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('lastSeenAt', 'Последняя активность'))
            ->add(TextFilter::new('version', 'Версия'))
            ->add(TextFilter::new('lastIp', 'IP'));
    }

    public function configureFields(string $pageName): iterable
    {
        $onlineSince = new \DateTimeImmutable(sprintf('-%d minutes', StatsQuery::ONLINE_WINDOW_MINUTES));

        yield BooleanField::new('online', 'Онлайн')
            ->setVirtual(true)
            ->renderAsSwitch(false)
            ->formatValue(fn ($value, LauncherInstall $install): bool => $install->getLastSeenAt() >= $onlineSince);
        yield DateTimeField::new('lastSeenAt', 'Последняя активность');
        yield TextField::new('lastIp', 'IP');
        yield TextField::new('version', 'Версия');
        yield IntegerField::new('startCount', 'Запусков');
        yield IntegerField::new('playCount', 'Нажатий «Играть»');
        yield DateTimeField::new('firstSeenAt', 'Первый запуск');
        yield TextField::new('launcherId', 'ID установки')->hideOnIndex();
    }
}
