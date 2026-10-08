<?php

namespace App\Controller\Admin;

use App\Entity\PlayEvent;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/** Журнал нажатий «Играть» (только просмотр). */
final class PlayEventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PlayEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('нажатие «Играть»')
            ->setEntityLabelInPlural('Нажатия «Играть»')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['serverName', 'serverAddress', 'ip', 'launcherId']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('createdAt', 'Время'))
            ->add(TextFilter::new('serverName', 'Сервер'))
            ->add(TextFilter::new('ip', 'IP'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Время');
        yield TextField::new('serverName', 'Сервер')->setMaxLength(255);
        yield TextField::new('serverAddress', 'Адрес')->setMaxLength(64);
        yield TextField::new('ip', 'IP игрока');
        yield TextField::new('version', 'Версия лаунчера');
        yield TextField::new('launcherId', 'ID установки')->hideOnIndex();
    }
}
