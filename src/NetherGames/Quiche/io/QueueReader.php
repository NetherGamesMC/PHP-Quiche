<?php

namespace NetherGames\Quiche\io;

use NetherGames\Quiche\io\promise\PromiseResolver;

class QueueReader extends QueueIO{
    /**
     * @phpstan-return list{0: string, 1: PromiseResolver|null}
     */
    public function shift() : array{
        return $this->buffer->shift();
    }

    public function isEmpty() : bool{
        return $this->buffer->isEmpty();
    }

    public function unshift(string $str, ?PromiseResolver $promiseResolver) : void{
        $this->buffer->unshift($str, $promiseResolver);
    }
}