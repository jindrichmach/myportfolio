<?php
/**
 * MyPortfolio — VZOROVÁ konfigurace.
 *
 * Postup:
 *   1. Zkopíruj tento soubor jako cfg.php (`cp cfg.sample.php cfg.php`)
 *   2. Vyplň reálné hodnoty (DB heslo, SMTP, pepper, …)
 *   3. cfg.php je v .gitignore — NIKDY ho necommituj
 *
 * Per-environment override můžeš dát do cfg.local.php (taky gitignored).
 *
 * Docker / kontejnerový deploy: stačí prázdný `<?php return [];` a všechny
 * citlivé hodnoty předat přes ENV (viz envOverrideMap v Config.php). Veřejné
 * URL zdrojů kurzů a cen mají defaultní hodnoty v `Config::baselineDefaults()`.
 * Volitelná ENV `MYPORTFOLIO_DATA_DIR=/data` přesune VŠECHNY stateful adresáře
 * (log/, storage/{uploads,backup,sessions,cache}, private/dkim/) pod jediný volume.
 * `${MYPORTFOLIO_DATA_DIR}/cfg.local.php` se navíc auto-loaduje, takže
 * per-instance konfigurace přežije image update.
 */

return [
    'app' => [
        'env'    => 'production',                    // 'development' | 'production'. Nikdy nedávat 'development' na veřejně dostupný server.
        'debug'  => false,                           // false v produkci — skryje stack trace v API odpovědích
        'url'    => 'https://dev.example.com',       // veřejná URL aplikace, používá se v emailech (reset hesla) a pro WebAuthn origin
        'pepper' => 'CHANGE-ME',                     // doplňková sůl k password_hash, 32B base64: openssl rand -base64 32
        'secret_encryption_key' => '',               // 32B base64 pro AES-256-GCM (TOTP secrets); openssl rand -base64 32. Pokud prázdné, fallback HKDF z pepperu.
        'timezone' => 'Europe/Prague',
        'locale_default' => 'cs',                    // jazyk UI při prvním načtení (před přihlášením)
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'myportfolio',
        'user'    => 'myportfolio',
        'pass'    => 'CHANGE-ME',
        'charset' => 'utf8mb4',
        'socket'  => null,                           // unix socket path (nebo null pro TCP)
        'dump_tool' => '',                           // absolutní cesta k mariadb-dump pro cron-backup.php. Prázdné = auto-detekce z PATH.
        'backup_skip_routines' => false,             // true = vynechat stored procedures/functions ze zálohy (DB user bez privilege)
    ],
    'redis' => [
        'enabled' => false,                          // rate limiting a brute-force ochrana (bez Redis fallback na MariaDB)
        'host'    => '127.0.0.1',
        'port'    => 6379,
        'auth'    => null,                           // heslo nebo null
        'db'      => 0,
        'prefix'  => 'myportfolio:dev:',             // namespace všech klíčů — změň na 'myportfolio:prod:' v produkci
    ],
    'session' => [
        'lifetime_days' => 30,                       // platnost cookie i serverové session
        // Výchozí interval automatického zámku (min); 0 nic nevynucuje, uživatel si může
        // nastavit 1–1440 min. Odemknout zamčenou session jde POUZE passkey.
        'lock_after_minutes' => 0,
        'cookie_name'   => '__Host-myportfolio_session', // __Host- prefix → vyžaduje Secure. Pro HTTP dev změnit na 'myportfolio_session' a cookie_secure=false.
        'cookie_secure' => true,                     // true vyžaduje HTTPS — false jen pro lokální HTTP dev
        'cookie_samesite' => 'Lax',                  // 'Lax' | 'Strict' | 'None' (None vyžaduje secure=true)
    ],
    'auth' => [
        // null = zachovat legacy auth.require_totp; true/false = explicitní MFA politika.
        'require_mfa' => null,

        // Které metody SPLNÍ povinné MFA: 'passkey' a/nebo 'totp'.
        'allowed_mfa_methods' => ['passkey', 'totp'],

        // Opt-in přihlášení discoverable passkey bez e-mailu a hesla (vyžaduje HTTPS origin).
        'passwordless_login' => [
            'enabled' => false,
        ],

        // true = vynutit TOTP (2FA) pro VŠECHNY uživatele (legacy přepínač, viz require_mfa).
        'require_totp' => false,

        // E-mailové OTP jako 2. faktor pro uživatele BEZ TOTP. Vyžaduje funkční `smtp`.
        'email_otp' => [
            'enabled'                 => false,
            'code_ttl_minutes'        => 10,
            'max_attempts'            => 5,
            'resend_cooldown_seconds' => 60,
            'trusted_device_days'     => 30,
            'trusted_cookie_name'     => '__Host-myportfolio_td', // pro HTTP dev změnit (viz session.cookie_name)
        ],
    ],
    'smtp' => [
        'host'           => 'smtp.example.com',
        'port'           => 587,                     // 25 plain | 465 SSL | 587 STARTTLS
        'encryption'     => 'tls',                   // 'ssl' | 'tls' | '' (žádné, jen lokální dev)
        'auth_enabled'   => false,
        'user'           => 'CHANGE-ME',
        'pass'           => 'CHANGE-ME',
        'verify_peer'    => true,                    // ověřit cert serveru — vypnout JEN pro self-signed dev SMTP

        'from_email'     => 'noreply@example.com',
        'from_name'      => 'MyPortfolio',
        'reply_to_email' => '',
        'reply_to_name'  => '',

        // DKIM podpis odchozí pošty. Klíče dej do /private/dkim/ (gitignored).
        'dkim' => [
            'enabled'         => false,
            'domain'          => 'example.com',
            'selector'        => 'myportfolio',
            'passphrase'      => '',
            'private_key_path'=> __DIR__ . '/private/dkim/myportfolio.pem',
            'public_key_path' => __DIR__ . '/private/dkim/myportfolio.pub',
            'dns_doc_path'    => __DIR__ . '/private/dkim/dns.txt',
        ],
    ],
    'logging' => [
        'level'    => 'info',                        // debug | info | notice | warning | error
        'path'     => __DIR__ . '/log/app.log',
        'max_files'=> 90,                            // rotace: drž posledních N denních souborů
    ],
    'storage' => [
        'uploads_dir'  => __DIR__ . '/storage/uploads',   // dočasné upload soubory (CSV importy)
        'backup_dir'   => __DIR__ . '/storage/backup',    // zálohy z cron-backup.php
        'sessions_dir' => __DIR__ . '/storage/sessions',  // rezervovaný runtime adresář (browser session jsou v MariaDB)
        'cache_dir'    => __DIR__ . '/storage/cache',
    ],
    'rate_limits' => [
        'login_per_min_per_ip'      => 10,
        'forgot_per_hour_per_email' => 3,
        'mutation_per_min_per_user' => 120,
        'read_per_min_per_user'     => 1200,
        'setup_per_hour_per_ip'     => 5,
    ],
    'brute_force' => [
        'captcha_after'   => 5,                      // selhání / 5 min  → vyžadovat CAPTCHA
        'lockout_15m_at'  => 10,                     // selhání / 15 min → lockout 15 min
        'lockout_24h_at'  => 30,                     // selhání / 60 min → lockout 24h
        'window_seconds'  => [300, 900, 3600],
    ],
    'captcha' => [
        'provider'    => 'none',                     // 'turnstile' (Cloudflare) | 'none'
        'site_key'    => 'CHANGE-ME',
        'secret_key'  => 'CHANGE-ME',                // server-side verify — NIKDY do frontend bundle
        'verify_url'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'script_url'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'timeout'     => 5,
        'fail_open'   => true,                       // Cloudflare API timeout: true=povolit login, false=odmítnout
        'action'      => 'login',
    ],
    'ip_allowlist' => [
        'enabled' => false,                          // true = blokovat mimo allow seznam
        'mode'    => 'block',                        // 'block' = 403 | 'log_only' = jen logovat
        'allow'   => [
            // '127.0.0.1',
            // '192.168.1.0/24',
        ],
        'apply_to' => 'all',                         // 'all' | 'admin_only' | 'mutations_only'
        'trusted_proxies' => [
            // '10.0.0.0/8',
        ],
        'header' => 'X-Forwarded-For',
    ],

    // Zdroje kurzů a cen. URL mají defaulty v Config::baselineDefaults(); tady jen klíče a override.
    'prices' => [
        'coingecko' => [
            'api_key' => '',                         // volitelný demo/pro klíč CoinGecko (vyšší limity)
        ],
    ],

    // Bankovní avíza: IMAP server jen na veřejné adrese (ochrana proti dotazům do vnitřní sítě).
    // true = povolit i místní / privátní adresy (vlastní poštovní server v LAN).
    'bank_notices' => [
        'allow_private_imap' => false,
    ],

    // Kontrola nové verze (Systém → Aktualizace) přes GitHub releases; pro fork změň repozitář.
    // 'update' => [
    //     'github_repo' => 'jindrichmach/myportfolio',
    // ],

    // Cron retention (api/bin/cron-cleanup.php + cron-backup.php)
    'cron' => [
        'backup' => [
            'daily_retention_days'   => 30,
            'monthly_retention_days' => 365,
            'output_dir'             => __DIR__ . '/storage/backup',
            'password'               => '',          // volitelné heslo ZIP záloh (AES-256). Prázdné = bez šifrování.
        ],
    ],
];
