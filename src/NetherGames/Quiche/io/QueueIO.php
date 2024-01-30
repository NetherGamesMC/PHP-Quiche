<?php

namespace NetherGames\Quiche\io;

class QueueIO{
    public function __construct(protected Buffer $buffer){
    }

    public function close() : void{
        $this->buffer->close();
    }
}