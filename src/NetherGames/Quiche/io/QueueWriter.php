<?php

namespace NetherGames\Quiche\io;

use NetherGames\Quiche\io\promise\Promise;
use NetherGames\Quiche\io\promise\PromiseResolver;

class QueueWriter extends QueueIO{
    public function write(string $str) : void{
        $this->buffer->write($str);
    }

    public function writeWithPromise(string $str) : Promise{
        $resolver = new PromiseResolver();

        $this->buffer->write($str, $resolver);

        return $resolver->getPromise();
    }
}