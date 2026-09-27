<?php

namespace App\Controller\Admin;

use App\Stats\StatsQuery;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly StatsQuery $stats)
    {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'onlineNow' => $this->stats->onlineNow(),
            'onlineWindow' => StatsQuery::ONLINE_WINDOW_MINUTES,
            'uniqueIpsToday' => $this->stats->uniqueIpsToday(),
            'playsToday' => $this->stats->playsToday(),
            'totalInstalls' => $this->stats->totalInstalls(),
            'daily' => $this->stats->daily(30),
            'topServers' => $this->stats->topServers(7),
            'versions' => $this->stats->versions(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('PREPISKA · Лаунчер')
            ->setLocales(['ru'])
            ->renderContentMaximized();
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateTimeFormat('dd.MM.yyyy HH:mm')
            ->setDateFormat('dd.MM.yyyy')
            ->setPaginatorPageSize(50);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Статистика', 'fa fa-chart-line');

        yield MenuItem::section('Лаунчер');
        yield MenuItem::linkTo(SponsorServerCrudController::class, 'Спонсорские серверы', 'fa fa-star');
        yield MenuItem::linkTo(LauncherReleaseCrudController::class, 'Версии лаунчера', 'fa fa-upload');

        yield MenuItem::section('Журналы');
        yield MenuItem::linkTo(PlayEventCrudController::class, 'Нажатия «Играть»', 'fa fa-play');
        yield MenuItem::linkTo(LauncherInstallCrudController::class, 'Установки', 'fa fa-desktop');

        yield MenuItem::section();
        yield MenuItem::linkToLogout('Выйти', 'fa fa-sign-out');
    }
}
