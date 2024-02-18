<?php declare(strict_types=1);

use FFIMe\FFIMe;

if(!file_exists(__DIR__ . "/quiche.php")){
    $quichePath = $argv[1] ?? getenv("QUICHE_PATH");
    if($quichePath === false || !is_file($quichePath)){
        echo "Quiche path not found\n";
        exit(1);
    }else{
        define("QUICHE_PATH", $quichePath);
    }

    $quiche = (new FFIMe(QUICHE_PATH, [
        __DIR__,
    ]))->include("bindings.h");

    file_put_contents(__DIR__ . "/quiche.php", "<?php declare(strict_types=1);\n" . $quiche->compile('NetherGames\Quiche\bindings\Quiche', ($argv[2] ?? "") === "dev"));
}

require __DIR__ . "/quiche.php";