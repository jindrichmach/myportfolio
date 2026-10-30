<?php

declare(strict_types=1);

namespace MyPortfolio;

use MyPortfolio\Action\Admin\AppearanceAction;
use MyPortfolio\Action\Admin\CronJobsAction;
use MyPortfolio\Action\Api\ApiCatalogAction;
use MyPortfolio\Action\Admin\SmtpAction;
use MyPortfolio\Action\Admin\UpdateStatusAction;
use MyPortfolio\Action\Admin\ListActivityLogAction;
use MyPortfolio\Action\Admin\RunCronJobAction;
use MyPortfolio\Action\Admin\UserAdminAction;
use MyPortfolio\Action\Auth\ApiMeAction;
use MyPortfolio\Action\Auth\ChangePasswordAction;
use MyPortfolio\Action\Auth\ForgotPasswordAction;
use MyPortfolio\Action\Auth\LoginAction;
use MyPortfolio\Action\Auth\LogoutAction;
use MyPortfolio\Action\Auth\MeAction;
use MyPortfolio\Action\Auth\MfaRecoveryCodeAction;
use MyPortfolio\Action\Auth\MfaStepUpAction;
use MyPortfolio\Action\Auth\PasskeyAction;
use MyPortfolio\Action\Auth\ResetPasswordAction;
use MyPortfolio\Action\Auth\SessionAction;
use MyPortfolio\Action\Auth\SetupAction;
use MyPortfolio\Action\Auth\SetupSmtpTestAction;
use MyPortfolio\Action\Auth\SetupStatusAction;
use MyPortfolio\Action\Auth\Tokens\CreateTokenAction;
use MyPortfolio\Action\Auth\Tokens\ListTokensAction;
use MyPortfolio\Action\Auth\Tokens\RevokeTokenAction;
use MyPortfolio\Action\Auth\TotpAction;
use MyPortfolio\Action\Catalog\IconAction;
use MyPortfolio\Action\Import\ImportsAction;
use MyPortfolio\Action\Catalog\InstitutionsAction;
use MyPortfolio\Action\Catalog\InstrumentsAction;
use MyPortfolio\Action\Market\FxRatesAction;
use MyPortfolio\Action\Market\MarketStatusAction;
use MyPortfolio\Action\Market\PricesAction;
use MyPortfolio\Action\Portfolio\AccountsAction;
use MyPortfolio\Action\Portfolio\PortfoliosAction;
use MyPortfolio\Action\Portfolio\PositionsAction;
use MyPortfolio\Action\Portfolio\TransactionsAction;
use MyPortfolio\Action\Portfolio\HistoryAction;
use MyPortfolio\Action\Portfolio\ValuationAction;
use MyPortfolio\Action\Settings\BankNoticesAction;
use MyPortfolio\Action\Settings\ProfileAction;
use MyPortfolio\Action\Settings\AlertsAction;
use MyPortfolio\Action\Settings\BenchmarkAction;
use MyPortfolio\Action\Settings\ExchangesAction;
use MyPortfolio\Action\Settings\WeeklyReportAction;
use MyPortfolio\Action\Summary\SummaryAction;
use MyPortfolio\Action\Allocation\AllocationAction;
use MyPortfolio\Action\NetWorth\NetWorthAction;
use MyPortfolio\Action\Sharing\InviteAction;
use MyPortfolio\Action\Sharing\SharesAction;
use MyPortfolio\Action\Tax\TaxReportAction;
use MyPortfolio\Action\System\HealthAction;
use MyPortfolio\Action\System\VersionAction;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health',  HealthAction::class);
        $app->get('/api/version', VersionAction::class);
        // Dokumentace API (katalog endpointů dostupných tokenem)
        $app->get('/api/api-catalog',  [ApiCatalogAction::class, 'catalog']);
        $app->get('/api/openapi.json', [ApiCatalogAction::class, 'openApi']);

        $app->group('/api/auth', function ($g) {
            $g->get ('/setup-status',    SetupStatusAction::class);
            $g->post('/setup',           SetupAction::class);
            $g->post('/setup/smtp-test', SetupSmtpTestAction::class);
            $g->post('/login',           LoginAction::class);
            $g->post('/webauthn/login/options', [LoginAction::class, 'passkeyOptions']);
            $g->post('/logout',          LogoutAction::class);
            $g->get ('/me',              MeAction::class);
            $g->get ('/api-me',          ApiMeAction::class);  // connection-test pro bearer i session
            $g->post('/change-password', ChangePasswordAction::class);
            $g->post('/forgot',          ForgotPasswordAction::class);
            $g->post('/reset',           ResetPasswordAction::class);

            $g->get ('/totp/status',     [TotpAction::class, 'status']);
            $g->post('/totp/setup',      [TotpAction::class, 'setup']);
            $g->post('/totp/enable',     [TotpAction::class, 'enable']);

            $g->get   ('/webauthn/credentials',              [PasskeyAction::class, 'credentials']);
            $g->post  ('/webauthn/register/options',          [PasskeyAction::class, 'registerOptions']);
            $g->post  ('/webauthn/register/verify',           [PasskeyAction::class, 'registerVerify']);
            $g->post  ('/webauthn/login/verify',              [PasskeyAction::class, 'loginVerify']);
            $g->post  ('/webauthn/step-up/options',           [PasskeyAction::class, 'stepUpOptions']);
            $g->post  ('/webauthn/step-up/verify',            [PasskeyAction::class, 'stepUpVerify']);
            $g->patch ('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'rename']);
            $g->delete('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'revoke']);
            $g->post  ('/mfa/step-up/totp',                   [MfaStepUpAction::class, 'totp']);
            $g->post  ('/mfa/step-up/recovery',               [MfaStepUpAction::class, 'recovery']);
            $g->get   ('/mfa/recovery-codes',                 [MfaRecoveryCodeAction::class, 'status']);
            $g->post  ('/mfa/recovery-codes',                 [MfaRecoveryCodeAction::class, 'generate']);
            $g->get   ('/session/status',                     [SessionAction::class, 'status']);
            $g->post  ('/session/activity',                   [SessionAction::class, 'activity']);
            $g->post  ('/session/lock',                       [SessionAction::class, 'lock']);
            $g->get   ('/session/lock-preference',            [SessionAction::class, 'lockPreference']);
            $g->put   ('/session/lock-preference',            [SessionAction::class, 'updateLockPreference']);
            $g->post  ('/session/unlock/options',             [SessionAction::class, 'unlockOptions']);
            $g->post  ('/session/unlock/verify',              [SessionAction::class, 'unlockVerify']);
            // API tokeny (Personal Access Tokens) — správa jen ze session auth
            $g->get   ('/tokens',                  ListTokensAction::class);
            $g->post  ('/tokens',                  CreateTokenAction::class);
            $g->delete('/tokens/{id:[0-9]+}',      RevokeTokenAction::class);
        });

        // Nastavení vlastního profilu (jméno, jazyk, měna zobrazení)
        $app->get('/api/settings/profile', [ProfileAction::class, 'get']);
        $app->put('/api/settings/profile', [ProfileAction::class, 'update']);
        $app->get ('/api/settings/weekly-report',         [WeeklyReportAction::class, 'get']);
        $app->put ('/api/settings/weekly-report',         [WeeklyReportAction::class, 'update']);
        $app->post('/api/settings/weekly-report/send',    [WeeklyReportAction::class, 'send']);
        $app->get ('/api/settings/weekly-report/preview', [WeeklyReportAction::class, 'preview']);
        $app->get ('/api/settings/period-report/preview', [WeeklyReportAction::class, 'periodPreview']);
        $app->get   ('/api/settings/exchanges',                 [ExchangesAction::class, 'list']);
        $app->post  ('/api/settings/exchanges/test',            [ExchangesAction::class, 'test']);
        $app->post  ('/api/settings/exchanges',                 [ExchangesAction::class, 'create']);
        $app->put   ('/api/settings/exchanges/{id:[0-9]+}',     [ExchangesAction::class, 'update']);
        $app->delete('/api/settings/exchanges/{id:[0-9]+}',     [ExchangesAction::class, 'delete']);
        $app->post  ('/api/settings/exchanges/{id:[0-9]+}/sync', [ExchangesAction::class, 'syncNow']);
        $app->put ('/api/settings/benchmark',             [BenchmarkAction::class, 'update']);
        $app->get ('/api/settings/alerts',                [AlertsAction::class, 'get']);
        $app->put ('/api/settings/alerts',                [AlertsAction::class, 'update']);
        $app->post('/api/settings/alerts/send',           [AlertsAction::class, 'send']);
        $app->get   ('/api/price-alerts',                 [AlertsAction::class, 'priceAlerts']);
        $app->post  ('/api/price-alerts',                 [AlertsAction::class, 'createPriceAlert']);
        $app->put   ('/api/price-alerts/{id:[0-9]+}',     [AlertsAction::class, 'updatePriceAlert']);
        $app->delete('/api/price-alerts/{id:[0-9]+}',     [AlertsAction::class, 'deletePriceAlert']);

        // Bankovní avíza — IMAP schránky uživatele a protokol zpracování (ne pro Bearer tokeny)
        $app->get   ('/api/settings/imap-accounts',                    [BankNoticesAction::class, 'list']);
        $app->post  ('/api/settings/imap-accounts',                    [BankNoticesAction::class, 'create']);
        $app->put   ('/api/settings/imap-accounts/{id:[0-9]+}',        [BankNoticesAction::class, 'update']);
        $app->delete('/api/settings/imap-accounts/{id:[0-9]+}',        [BankNoticesAction::class, 'delete']);
        $app->post  ('/api/settings/imap-accounts/{id:[0-9]+}/test',   [BankNoticesAction::class, 'test']);
        $app->post  ('/api/settings/imap-accounts/{id:[0-9]+}/scan',   [BankNoticesAction::class, 'scan']);
        $app->get   ('/api/bank-notices',                              [BankNoticesAction::class, 'notices']);

        // Katalog — číst a zakládat smí každý, upravovat a mazat admin (/api/admin/…)
        $app->get ('/api/institutions', [InstitutionsAction::class, 'list']);
        $app->post('/api/institutions', [InstitutionsAction::class, 'create']);
        $app->get ('/api/instruments',  [InstrumentsAction::class, 'list']);
        $app->post('/api/instruments',  [InstrumentsAction::class, 'create']);
        // Ikony — nahrát / odebrat smí admin nebo autor položky (kontrola v CatalogIcons)
        $app->post  ('/api/{kind:institutions|instruments}/{id:[0-9]+}/icon', [IconAction::class, 'upload']);
        $app->delete('/api/{kind:institutions|instruments}/{id:[0-9]+}/icon', [IconAction::class, 'remove']);
        $app->get   ('/api/icons/{file:[0-9a-f]{32}\.png}',                  [IconAction::class, 'show']);
        $app->post  ('/api/{kind:institutions|instruments}/{id:[0-9]+}/icon/library', [IconAction::class, 'fromLibrary']);
        $app->post  ('/api/institutions/{id:[0-9]+}/icon/website',          [IconAction::class, 'fromWebsite']);
        $app->get   ('/api/icon-library',                                   [IconAction::class, 'library']);
        $app->get   ('/api/icon-library/{set:crypto|etf|institutions}/{slug:[a-z0-9-]{1,40}}.png', [IconAction::class, 'libraryImage']);

        // Ceny a kurzy (globální data)
        $app->get ('/api/prices',   [PricesAction::class, 'list']);
        $app->post('/api/prices',   [PricesAction::class, 'create']);
        $app->get ('/api/fx-rates', FxRatesAction::class);

        // Portfolio — data přihlášeného uživatele
        foreach ([
            'accounts'   => AccountsAction::class,
            'portfolios' => PortfoliosAction::class,
            'positions'  => PositionsAction::class,
            'transactions' => TransactionsAction::class,
        ] as $resource => $action) {
            $app->get   ("/api/$resource",               [$action, 'list']);
            $app->post  ("/api/$resource",               [$action, 'create']);
            $app->get   ("/api/$resource/{id:[0-9]+}",   [$action, 'get']);
            $app->put   ("/api/$resource/{id:[0-9]+}",   [$action, 'update']);
            $app->delete("/api/$resource/{id:[0-9]+}",   [$action, 'delete']);
        }
        $app->post('/api/transfers', [TransactionsAction::class, 'transfer']);

        // Importy z exportů burz a brokerů (M6)
        $app->get ('/api/imports/sources',                           [ImportsAction::class, 'sources']);
        $app->post('/api/imports/preview',                           [ImportsAction::class, 'preview']);
        $app->get ('/api/imports/preview/{token:[0-9a-f]{32}}',      [ImportsAction::class, 'view']);
        $app->post('/api/imports/preview/{token:[0-9a-f]{32}}/confirm', [ImportsAction::class, 'confirm']);
        $app->get ('/api/imports',                                   [ImportsAction::class, 'batches']);
        $app->post('/api/imports/{id:[0-9]+}/revert',                [ImportsAction::class, 'revert']);

        // Měsíční / roční souhrn, dividendy a úroky
        $app->get ('/api/summary', [SummaryAction::class, 'summary']);
        $app->get ('/api/income',  [SummaryAction::class, 'income']);

        // Cílová alokace a rebalancing
        $app->get ('/api/allocation',         [AllocationAction::class, 'show']);
        $app->put ('/api/allocation/targets', [AllocationAction::class, 'save']);

        // Sdílení pro domácnost (vždy vlastní účet; pozvánka je veřejná)
        $app->get   ('/api/shares',                           [SharesAction::class, 'list']);
        $app->post  ('/api/shares',                           [SharesAction::class, 'create']);
        $app->get   ('/api/shares/activity',                  [SharesAction::class, 'activity']);
        $app->put   ('/api/shares/{id:[0-9]+}',               [SharesAction::class, 'update']);
        $app->delete('/api/shares/{id:[0-9]+}',               [SharesAction::class, 'delete']);
        $app->post  ('/api/shares/{id:[0-9]+}/resend',        [SharesAction::class, 'resend']);
        $app->get   ('/api/household',                        [SharesAction::class, 'household']);
        $app->get   ('/api/public/invite/{token:[0-9a-f]{64}}', [InviteAction::class, 'show']);
        $app->post  ('/api/public/invite/{token:[0-9a-f]{64}}', [InviteAction::class, 'accept']);

        // Čisté jmění (majetek mimo investice a závazky)
        $app->get   ('/api/net-worth',                              [NetWorthAction::class, 'overview']);
        $app->get   ('/api/net-worth/history',                      [NetWorthAction::class, 'history']);
        $app->post  ('/api/net-worth/items',                        [NetWorthAction::class, 'createItem']);
        $app->put   ('/api/net-worth/items/{id:[0-9]+}',            [NetWorthAction::class, 'updateItem']);
        $app->delete('/api/net-worth/items/{id:[0-9]+}',            [NetWorthAction::class, 'deleteItem']);
        $app->get   ('/api/net-worth/items/{id:[0-9]+}/entries',    [NetWorthAction::class, 'entries']);
        $app->post  ('/api/net-worth/items/{id:[0-9]+}/entries',    [NetWorthAction::class, 'saveEntry']);
        $app->delete('/api/net-worth/entries/{id:[0-9]+}',          [NetWorthAction::class, 'deleteEntry']);

        // Daňový report (M7)
        $app->get ('/api/tax-report/years',      [TaxReportAction::class, 'years']);
        $app->get ('/api/tax-report/filing',     [TaxReportAction::class, 'filing']);
        $app->get ('/api/tax-report/filing.csv', [TaxReportAction::class, 'filingCsv']);
        $app->get ('/api/tax-report',            [TaxReportAction::class, 'report']);
        $app->get ('/api/tax-report/export.csv', [TaxReportAction::class, 'csv']);

        // Ocenění a přehled (měna zobrazení uživatele)
        $app->get   ('/api/dashboard',                          [ValuationAction::class, 'dashboard']);
        $app->get   ('/api/positions/{id:[0-9]+}/valuation',    [ValuationAction::class, 'position']);
        $app->post  ('/api/positions/{id:[0-9]+}/cash-balance', [PositionsAction::class, 'cashBalance']);
        $app->get   ('/api/positions/{id:[0-9]+}/valuations',   [ValuationAction::class, 'list']);
        $app->post  ('/api/positions/{id:[0-9]+}/valuations',   [ValuationAction::class, 'create']);
        $app->delete('/api/valuations/{id:[0-9]+}',             [ValuationAction::class, 'delete']);
        $app->get   ('/api/history',                            HistoryAction::class);

        // Admin
        $app->get    ('/api/admin/market-status', MarketStatusAction::class);
        $app->get    ('/api/admin/appearance', [AppearanceAction::class, 'get']);
        $app->put    ('/api/admin/appearance', [AppearanceAction::class, 'update']);
        $app->get    ('/api/admin/smtp',      [SmtpAction::class, 'get']);
        $app->put    ('/api/admin/smtp',      [SmtpAction::class, 'update']);
        $app->post   ('/api/admin/smtp/test', [SmtpAction::class, 'test']);
        $app->put    ('/api/admin/institutions/{id:[0-9]+}', [InstitutionsAction::class, 'update']);
        $app->delete ('/api/admin/institutions/{id:[0-9]+}', [InstitutionsAction::class, 'delete']);
        $app->put    ('/api/admin/instruments/{id:[0-9]+}',  [InstrumentsAction::class, 'update']);
        $app->delete ('/api/admin/instruments/{id:[0-9]+}',  [InstrumentsAction::class, 'delete']);
        $app->get    ('/api/admin/users',             [UserAdminAction::class, 'list']);
        $app->post   ('/api/admin/users',             [UserAdminAction::class, 'create']);
        $app->put    ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'update']);
        $app->delete ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'delete']);
        $app->get    ('/api/admin/activity-log',      ListActivityLogAction::class);
        $app->get    ('/api/admin/cron-jobs',         CronJobsAction::class);
        $app->post   ('/api/admin/cron-jobs/{script:cron-[a-z0-9-]+}/run', RunCronJobAction::class);
        $app->get    ('/api/admin/update',            UpdateStatusAction::class);

        // 404 fallback pro /api/*
        $app->any('/api/{path:.*}', function ($req, $res) {
            return \MyPortfolio\Http\Json::error($res, 'not_found', 'Route not found', 404);
        });
    }
}
