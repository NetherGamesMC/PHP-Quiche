<?php

namespace NetherGames\Quiche\io;

use RuntimeException;
use SplDoublyLinkedList;

class Buffer{
    private bool $closed = false;
    /** @var SplDoublyLinkedList<string> */
    private SplDoublyLinkedList $buffer;

    public function __construct(){
        $this->buffer = new SplDoublyLinkedList();
    }

    public function close() : void{
        $this->closed = true;
    }

    public function shift() : ?string{
        try{
            return $this->buffer->shift();
        }catch(RuntimeException){
            return null;
        }
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    public function write(string $str) : void{
        if($this->closed){
            throw new RuntimeException("Buffer is closed");
        }

        $this->buffer->push($str);
    }

    public function readd(string $str) : void{
        $this->buffer->unshift($str);
    }
}