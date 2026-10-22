<?php

declare(strict_types=1);

/**
 * Brána údržby — MUSÍ zůstat nad `require autoload.php` a nesmí sáhnout na
 * žádnou třídu aplikace. Přesně v okně, které hlídá (nasazení souborů + migrace),
 * je autoloader nekonzistentní: mapa tříd ukazuje na soubory, které ještě
 * nedorazily. Kdyby brána potřebovala autoload, spadla by dřív, než odpoví.
 *
 * Značku zakládá a maže {@see \MyPortfolio\Service\Update\MaintenanceMode}.
 */
(static function (): void {
    $base = getenv('MYPORTFOLIO_DATA_DIR');
    $base = is_string($base) && trim($base) !== ''
        ? rtrim(trim($base), "/\\")
        : dirname(__DIR__, 2);

    $raw = @file_get_contents($base . '/storage/maintenance.json');
    if (!is_string($raw) || $raw === '') {
        return;
    }
    $flag = json_decode($raw, true);
    if (!is_array($flag)) {
        return;
    }

    // Mrtvá značka po spadlém workeru nesmí držet instalaci dole navěky.
    $expires = strtotime((string) ($flag['expires_at'] ?? ''));
    if ($expires === false || $expires < time()) {
        return;
    }

    $product = (string) ($flag['product'] ?? 'aplikace');
    $target  = (string) ($flag['target'] ?? '');
    $message = 'Probíhá aktualizace na ' . $product . ($target !== '' ? ' ' . $target : '')
        . '. Zkus to prosím za chvíli — nasazení souborů a migrace databáze trvají jednotky minut.';

    http_response_code(503);
    header('Retry-After: 20');
    header('Cache-Control: no-store');

    $path   = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_starts_with($path, '/api/') || stripos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['error' => ['code' => 'maintenance', 'message' => $message]],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta http-equiv="refresh" content="20">'
        . '<title>Probíhá aktualizace</title>'
        . '<style>body{font:14px/1.6 system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;color:#15131D}'
        . 'h1{color:#3B2D83;font-size:22px}</style></head><body>'
        . '<h1>Probíhá aktualizace</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<p>Stránka se sama obnoví.</p></body></html>';
    exit;
})();

require __DIR__ . '/../vendor/autoload.php';

use MyPortfolio\Bootstrap;

try {
    $app = Bootstrap::buildApp();
    $app->run();
} catch (\Throwable $e) {
    // Pre-bootstrap chyba (typicky chybějící cfg.php nebo nedostupná DB).
    http_response_code(503);

    $msg = $e->getMessage();
    $missingCfg = str_contains($msg, 'cfg.php');
    $isJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    // Detekce produkce — pokud cfg.php existuje a obsahuje 'env' => 'production',
    // skryjeme detail. Bez cfg.php (úplně čerstvá instalace) ukážeme návod, abychom pomohli adminovi.
    $isProd = false;
    $cfgFile = __DIR__ . '/../../cfg.php';
    if (is_file($cfgFile)) {
        $cfgContent = (string) @file_get_contents($cfgFile);
        $isProd = !preg_match("/'env'\s*=>\s*'development'/", $cfgContent);
    }

    // Detail loguj, pokud je dostupný adresář.
    $logDir = \MyPortfolio\Infrastructure\Config\RuntimePaths::log();
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir . '/bootstrap-error.log',
            sprintf("[%s] %s\n%s\n\n", date('Y-m-d H:i:s'), $msg, $e->getTraceAsString()),
            FILE_APPEND
        );
    }

    $publicMsg = ($isProd && !$missingCfg)
        ? 'Aplikace nedostupná, kontaktujte administrátora.'
        : $msg;
    $publicCode = $missingCfg ? 'config_missing' : 'bootstrap_failed';

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['error' => ['code' => $publicCode, 'message' => $publicMsg]];
        if (!$isProd && $missingCfg) {
            $payload['error']['hint'] = 'Vytvoř cfg.php z cfg.sample.php a spusť `php api/bin/setup.php`.';
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="cs">
    <head>
      <meta charset="utf-8">
      <title>MyPortfolio</title>
      <style>
        body { font: 14px/1.5 system-ui, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 20px; color: #15131D; }
        h1 { color: #3B2D83; }
        code { background: #F4F2F8; padding: 2px 6px; border-radius: 4px; font-family: 'JetBrains Mono', Consolas, monospace; }
        pre { background: #15131D; color: #fff; padding: 12px 16px; border-radius: 6px; overflow-x: auto; }
        .err { background: #FBEDED; border-left: 3px solid #D45B5B; padding: 12px; margin: 16px 0; }
      </style>
    </head>
    <body>
      <?php if ($isProd && !$missingCfg): ?>
        <h1>Aplikace nedostupná</h1>
        <p>Došlo k dočasné chybě. Kontaktujte administrátora.</p>
      <?php else: ?>
        <h1>MyPortfolio — chybí konfigurace</h1>
        <div class="err"><strong>Chyba:</strong> <?= htmlspecialchars($publicMsg, ENT_QUOTES) ?></div>
        <?php if ($missingCfg): ?>
          <h2>Postup</h2>
          <ol>
            <li>Z rootu repa zkopíruj vzorovou konfiguraci:
              <pre>cp cfg.sample.php cfg.php</pre>
            </li>
            <li>Otevři <code>cfg.php</code> a vyplň hodnoty <code>CHANGE-ME</code> — minimálně:
              <ul>
                <li><code>app.url</code> — tvoje doména</li>
                <li><code>app.pepper</code> — vygeneruj: <code>openssl rand -base64 32</code></li>
                <li><code>db.host</code> / <code>db.name</code> / <code>db.user</code> / <code>db.pass</code></li>
                <li><code>smtp.*</code> pro odesílání e-mailů</li>
              </ul>
            </li>
            <li>Spusť úvodní nastavení:
              <pre>php api/bin/setup.php</pre>
            </li>
          </ol>
        <?php else: ?>
          <h2>Pravděpodobné příčiny</h2>
          <ul>
            <li>Neplatné údaje v <code>cfg.php</code> (db.host / user / pass)</li>
            <li>MariaDB neběží nebo je na jiném portu</li>
            <li>Databáze neexistuje</li>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </body>
    </html>
    <?php
}
