<?php

namespace App\Controller\Admin;

use App\Entity\SupportTicket;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Symfony\Component\HttpFoundation\Response;

/** Обращения в поддержку: читать можно, менять — только статус и заметку. */
final class SupportTicketCrudController extends AbstractCrudController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public static function getEntityFqcn(): string
    {
        return SupportTicket::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('обращение')
            ->setEntityLabelInPlural('Поддержка')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'contact', 'message', 'launcherVersion', 'ip']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Статус')->setChoices(SupportTicket::STATUSES))
            ->add(DateTimeFilter::new('createdAt', 'Получено'));
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        // Открытие нового обращения помечает его прочитанным.
        $ticket = $context->getEntity()->getInstance();
        if ($ticket instanceof SupportTicket && $ticket->getStatus() === SupportTicket::STATUS_NEW) {
            $ticket->setStatus(SupportTicket::STATUS_READ);
            $this->em->flush();
        }

        return parent::detail($context);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Получено')->setFormTypeOption('disabled', true);
        yield ChoiceField::new('status', 'Статус')
            ->setChoices(SupportTicket::STATUSES)
            ->renderAsBadges([
                SupportTicket::STATUS_NEW => 'danger',
                SupportTicket::STATUS_READ => 'warning',
                SupportTicket::STATUS_RESOLVED => 'success',
            ]);
        yield TextField::new('name', 'Имя')->setFormTypeOption('disabled', true);
        yield TextField::new('contact', 'Контакт')->setFormTypeOption('disabled', true);
        yield TextField::new('launcherVersion', 'Версия лаунчера')->setFormTypeOption('disabled', true);
        yield TextareaField::new('message', 'Сообщение')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
        yield TextField::new('message', 'Сообщение')->onlyOnIndex()->setMaxLength(80);
        yield TextareaField::new('adminNote', 'Заметка')->hideOnIndex();
        yield TextField::new('ip', 'IP')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('userAgent', 'Браузер')->hideOnIndex()->setFormTypeOption('disabled', true);
    }
}
