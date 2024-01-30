<?php declare(strict_types=1);

use FFIMe\FFIMe;

require __DIR__ . "/../vendor/autoload.php";

$quichePath = $argv[1] ?? getenv("QUICHE_PATH");
if ($quichePath === false || !is_file($quichePath)) {
    echo "Quiche path not found\n";
    exit(1);
} else {
    define("QUICHE_PATH", $quichePath);
}

$quiche = (new FFIMe(QUICHE_PATH, [
    __DIR__,
]))->include("bindings.h");

[$namespace, $code] = explode("\n", $quiche->compile('NetherGames\Quiche\bindings\Quiche', ($argv[2] ?? "") === "dev"), 2);

$pattern = '/(__uint8_t) (sa_len|ss_len|sin_len|sin6_len);/';
$replacement = "' . (PHP_OS_FAMILY === 'Darwin' ? 'uint8_t $2;' : '') . '"; // these are only defined on macOS
$code = preg_replace($pattern, $replacement, $code);

$code = str_replace("'" . QUICHE_PATH . "'", "QUICHE_PATH", $code);
$env = "defined('QUICHE_PATH') or define('QUICHE_PATH', getenv('QUICHE_PATH') ?: throw new \RuntimeException('QUICHE_PATH not defined'));";

file_put_contents(__DIR__ . "/quiche.php", "<?php declare(strict_types=1);\n" . $namespace . "\n" . $env . "\n" . $code);