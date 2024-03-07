<?php

namespace NetherGames\Quiche\io;

use Closure;
use RuntimeException;
use SplDoublyLinkedList;

class Buffer{
    private bool $closed = false;
    /** @var SplDoublyLinkedList<string> */
    private SplDoublyLinkedList $buffer;

    /**
     * @param Closure|null $onWrite function() : void
     *
     * @return array{0: QueueReader, 1: QueueWriter}
     */
    public static function create(Closure $onWrite = null) : array{
        $buffer = new self($onWrite);

        return [
            new QueueReader($buffer),
            new QueueWriter($buffer)
        ];
    }

    /**
     * @param ?Closure $onFirstWrite function() : void
     */
    private function __construct(private ?Closure $onFirstWrite){
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

    public function isEmpty() : bool{
        return $this->buffer->isEmpty();
    }

    public function write(string $str) : void{
        if($this->closed){
            throw new RuntimeException("Buffer is closed");
        }

        $this->buffer->push($str);

        if($this->onFirstWrite !== null){
            ($this->onFirstWrite)();
        }
    }

    public function unshift(string $str) : void{
        $this->buffer->unshift($str);
    }
}