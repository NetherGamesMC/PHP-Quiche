<?php

namespace NetherGames\Quiche\io;

use Closure;
use NetherGames\Quiche\io\promise\PromiseResolver;
use RuntimeException;
use SplDoublyLinkedList;

class Buffer{
    private bool $closed = false;
    /** @var SplDoublyLinkedList<list{0: string, 1: PromiseResolver|null}> */
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
     * @param ?Closure $onWrite function() : void
     */
    private function __construct(private ?Closure $onWrite){
        $this->buffer = new SplDoublyLinkedList();
    }

    public function close() : void{
        $this->closed = true;

        foreach($this->buffer as $item){
            [$_, $promise] = $item;

            $promise?->failure();
        }
    }

    /**
     * @caution Throws an exception if shift is called on an empty buffer
     * @phpstan-return list{0: string, 1: PromiseResolver|null}
     */
    public function shift() : array{
        return $this->buffer->shift();
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    public function isEmpty() : bool{
        return $this->buffer->isEmpty();
    }

    public function write(string $str, ?PromiseResolver $promiseResolver = null) : void{
        if($this->closed){
            $promiseResolver?->failure();
            throw new RuntimeException("Buffer is closed");
        }

        $this->buffer->push([$str, $promiseResolver]);

        if($this->onWrite !== null){
            ($this->onWrite)();
        }
    }

    public function unshift(string $str, ?PromiseResolver $promiseResolver) : void{
        $this->buffer->unshift([$str, $promiseResolver]);
    }
}