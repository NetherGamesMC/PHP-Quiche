<?php

namespace NetherGames\Quiche\io;

class QueueReader extends QueueIO{
    public function shift() : ?string{
        return $this->buffer->shift();
    }

    public function readd(string $str) : void{
        $this->buffer->readd($str);
    }
}