<?php declare(strict_types=1);

use FFIMe\FFIMe;

$quichePHPFile = getenv("QUICHE_PHP_FILE");
if($quichePHPFile === false){
    $quichePHPFile = __DIR__ . "/quiche.php";
}

$timerFdPHPFile = getenv("TIMERFD_PHP_FILE");
if($timerFdPHPFile === false){
    $timerFdPHPFile = __DIR__ . "/timerfd.php";
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

if(!file_exists($timerFdPHPFile)){
    $timerHFile = getenv("TIMERFD_H_FILE");
    if($timerHFile === false || !is_file($timerHFile)){
        $timerHFile = __DIR__ . "/timerfd.h";
    }

    $timerfd = (new FFIMe(FFIME::LIBC))->include($timerHFile);
    file_put_contents($timerFdPHPFile, "<?php declare(strict_types=1);\n" . $timerfd->compile('NetherGames\Quiche\bindings\timer\TimerFd'));
}

require $quichePHPFile;
require $timerFdPHPFile;