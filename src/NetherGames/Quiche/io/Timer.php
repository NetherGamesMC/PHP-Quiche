<?php

namespace NetherGames\Quiche\io;

use Closure;
use NetherGames\Quiche\QuicheConnection;
use function microtime;
use function spl_object_id;

class Timer{
    /** @var array<int, array{0: QuicheConnection, 1: int}> */
    private array $connections = [];
    private MinHeap $heap;

    /**
     * @param Closure $callback function(QuicheConnection $connection) : void
     */
    public function __construct(private readonly Closure $callback){
        $this->heap = new MinHeap();
    }

    public function reset(QuicheConnection $conn, int $timeout) : void{
        if($timeout < 0 || $timeout >= PHP_INT_MAX){
            $this->stop($conn);

            return;
        }

        $id = spl_object_id($conn);
        $timeout += (int) (microtime(true) * 1000);

        [, $currentTimeout] = $this->connections[$id] ?? [null, null];
        if($currentTimeout !== null && $currentTimeout <= $timeout){
            return;
        }

        $this->connections[$id] = [$conn, $timeout];
        $this->heap->insert([$timeout, $id]);
    }

    public function stop(QuicheConnection $conn) : void{
        unset($this->connections[spl_object_id($conn)]);
    }

    public function manage() : ?int{
        $now = (int) (microtime(true) * 1000);

        while(!$this->heap->isEmpty()){
            [$timeout, $connectionId] = $this->heap->top();

            if($timeout > $now){
                return $timeout - $now;
            }

            $this->heap->extract();

            [$connection, $currentTimeout] = $this->connections[$connectionId] ?? [null, null];
            if($currentTimeout !== $timeout){
                continue;
            }

            unset($this->connections[$connectionId]);
            ($this->callback)($connection);
        }

        return null;
    }
}