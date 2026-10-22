<?php

declare(strict_types=1);

/**
 * Vygeneruje konfiguraci z cfg.sample.php s nahrazenými hodnotami (instalační skript cmd/install.sh).
 * Komentáře šablony zůstanou; mění se jen hodnoty uvedených klíčů (tečková notace, např. db.pass).
 *
 *   php api/bin/install-config.php --out=cfg.php --set app.url=https://x.cz --set db.port=3306 --set redis.enabled=true
 *
 * Hodnota `true` / `false` / `null` / celé číslo se zapíše jako PHP literál, ostatní jako řetězec;
 * prefix `s:` vynutí řetězec (hesla: `--set db.pass=s:123456`).
 * Klíč, který v šabloně není, je chyba (překlep by jinak tiše nic nenastavil).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$root = dirname(__DIR__, 2);
$out = null;
$set = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
        continue;
    }
    $pair = match (true) {
        str_starts_with($arg, '--set=') => substr($arg, 6),
        $arg === '--set'                => $args[++$i] ?? '',
        default                         => null,
    };
    if ($pair === null || !str_contains($pair, '=')) {
        fwrite(STDERR, "Neznámý argument: $arg\n");
        exit(1);
    }
    [$key, $value] = explode('=', $pair, 2);
    $set[$key] = $value;
}
if ($out === null || $set === []) {
    fwrite(STDERR, "Použití: php api/bin/install-config.php --out=cfg.php --set klíč=hodnota [...]\n");
    exit(1);
}

$literal = static function (string $v): string {
    if (str_starts_with($v, 's:')) {
        return var_export(substr($v, 2), true);
    }
    if (in_array(strtolower($v), ['true', 'false', 'null'], true)) {
        return strtolower($v);
    }
    if (preg_match('/^-?\d+$/', $v) === 1) {
        return $v;
    }
    return var_export($v, true);
};

$lines = file($root . '/cfg.sample.php') ?: [];
$path = [];
$done = [];
foreach ($lines as $n => $line) {
    if (preg_match("/^\s*'([\w-]+)'\s*=>\s*\[\s*(\/\/.*)?$/", $line, $m) === 1) {
        $path[] = $m[1];
        continue;
    }
    if (preg_match('/^\s*\],?\s*(\/\/.*)?$/', $line) === 1) {
        array_pop($path);
        continue;
    }
    if (preg_match("/^(\s*'([\w-]+)'\s*=>\s*)(.+?)(,\s*(\/\/.*)?)$/", rtrim($line, "\n"), $m) === 1) {
        $key = implode('.', [...$path, $m[2]]);
        if (array_key_exists($key, $set)) {
            $lines[$n] = $m[1] . $literal($set[$key]) . $m[4] . "\n";
            $done[$key] = true;
        }
    }
}
$missing = array_diff(array_keys($set), array_keys($done));
if ($missing !== []) {
    fwrite(STDERR, 'V cfg.sample.php chybí klíče: ' . implode(', ', $missing) . "\n");
    exit(1);
}
$target = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
file_put_contents($target, implode('', $lines));
chmod($target, 0640);

// Kontrola: soubor jde načíst a hodnoty sedí
$cfg = require $target;
foreach ($set as $key => $value) {
    $actual = $cfg;
    foreach (explode('.', $key) as $part) {
        $actual = $actual[$part] ?? null;
    }
    $expected = eval('return ' . $literal($value) . ';');
    if ($actual !== $expected) {
        fwrite(STDERR, "Kontrola selhala u $key.\n");
        exit(1);
    }
}
echo "✓ $out zapsán (" . count($set) . " hodnot).\n";
