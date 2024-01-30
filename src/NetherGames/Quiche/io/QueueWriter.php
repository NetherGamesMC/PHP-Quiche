<?php

namespace NetherGames\Quiche\io;

class QueueWriter extends QueueIO{
    public function write(string $str) : void{
        $this->buffer->write($str);
    }
}