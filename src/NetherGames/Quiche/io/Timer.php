<?php

namespace NetherGames\Quiche\io;

use Closure;
use NetherGames\Quiche\QuicheConnection;
use function microtime;
use function spl_object_id;

class Timer{
    /** @var array<int, QuicheConnection> */
    private array $connections = [];
    private MinHeap $heap;

    /**
     * @param Closure $callback function(QuicheConnection $connection) : void
     */
    public function __construct(private readonly Closure $callback){
        $this->heap = new MinHeap();
    }

    public function reset(QuicheConnection $conn, int $timeout) : void{
        $id = spl_object_id($conn);

        $this->connections[$id] = $conn;
        $this->heap->insert([$timeout + (int) (microtime(true) * 1000), $id]);
    }

    public function stop(QuicheConnection $conn) : void{
        unset($this->connections[spl_object_id($conn)]);
    }

    public function getNextTimeout() : ?int{
        while(!$this->heap->isEmpty()){
            [$timeout, $connectionId] = $this->heap->top();

            if(!isset($this->connections[$connectionId])){
                $this->heap->extract();
                continue;
            }

            return max(0, $timeout - (int) (microtime(true) * 1000));
        }

        return null;
    }

    public function manage() : void{
        $now = null;
        while(!$this->heap->isEmpty()){
            [$timeout, $connectionId] = $this->heap->top();
            if($timeout > $now ??= (int) (microtime(true) * 1000)){
                break;
            }

            $this->heap->extract();

            if(($connection = $this->connections[$connectionId] ?? null) === null){
                continue;
            }

            ($this->callback)($connection);
        }
    }
}