<?php

namespace NetherGames\Quiche\io;

class QueueReader extends QueueIO{
    public function shift() : ?string{
        return $this->buffer->shift();
    }

    public function isEmpty() : bool{
        return $this->buffer->isEmpty();
    }

    public function unshift(string $str) : void{
        $this->buffer->unshift($str);
    }
}