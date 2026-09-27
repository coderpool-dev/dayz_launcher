<?php

namespace App\Controller\Admin;

use App\Entity\LauncherRelease;
use App\Release\ReleaseStorage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Загрузка новых версий лаунчера. Лаунчер сам предложит обновиться
 * до последней опубликованной версии.
 */
final class LauncherReleaseCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ReleaseStorage $storage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return LauncherRelease::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('версию лаунчера')
            ->setEntityLabelInPlural('Версии лаунчера')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Загрузите установщик (PREPISKA-DayZ-Launcher-Setup-x.y.z.exe из GitHub Releases). Лаунчеры с более старой версией предложат обновиться до последней опубликованной версии. Постоянная ссылка на последнюю версию: ' . $this->urls->generate('download_latest', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('version', 'Версия')->setHelp('В формате 1.2.3 — должна совпадать с версией в установщике.');
        yield TextareaField::new('notes', 'Что нового')->setHelp('Покажется пользователю в лаунчере.')->hideOnIndex();
        yield BooleanField::new('published', 'Опубликована')->setHelp('Неопубликованные версии лаунчеры не видят.');

        yield Field::new('upload', 'Установщик (.exe)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions(['required' => $pageName === Crud::PAGE_NEW])
            ->setHelp($pageName === Crud::PAGE_EDIT ? 'Оставьте пустым, чтобы не менять файл.' : '')
            ->onlyOnForms();

        yield TextField::new('fileName', 'Файл')->hideOnForm()
            ->formatValue(fn (?string $value, LauncherRelease $release): string => $release->hasFile() ? sprintf('%s (%.1f МБ)', $value, $release->getFileSize() / 1048576) : '—');
        yield TextField::new('sha256', 'SHA-256')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Загружена')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->storeUpload($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->storeUpload($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        $this->storage->remove($entityInstance);
    }

    private function storeUpload(LauncherRelease $release): void
    {
        if ($release->getUpload() !== null) {
            $this->storage->store($release, $release->getUpload());
        }
    }
}
