<?php declare(strict_types=1);

use FFIMe\FFIMe;

$quichePHPFile = getenv("QUICHE_PHP_FILE");
if($quichePHPFile === false){
    $quichePHPFile = __DIR__ . "/quiche.php";
}

if(!file_exists($quichePHPFile)){
    $quichePath = getenv("QUICHE_PATH");
    if($quichePath === false || !is_file($quichePath)){
        echo "Quiche path not found\n";
        exit(1);
    }

    $quicheHFile = getenv("QUICHE_H_FILE");
    if($quicheHFile === false || !is_file($quicheHFile)){
        $quicheHFile = __DIR__ . "/quiche.h";
    }

    $quiche = (new FFIMe($quichePath))->include($quicheHFile)->include("netinet/in.h");

    file_put_contents($quichePHPFile, "<?php declare(strict_types=1);\n" . $quiche->compile('NetherGames\Quiche\bindings\Quiche', ($argv[2] ?? "") === "dev"));
}

require $quichePHPFile;