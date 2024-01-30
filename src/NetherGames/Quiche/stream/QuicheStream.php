<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;

abstract class QuicheStream{
    protected ?Closure $onShutdown = null;

    public function __construct(
        protected readonly QuicheFFI $bindings,
        protected readonly int $id,
        protected readonly struct_quiche_conn_ptr $connection,
    ){
    }

    /**
     * Callable to be called when the stream is closed, by the peer or local
     *
     * @param ?Closure $onShutdown (bool $peerClosed)
     */
    public function setShutdownCallback(?Closure $onShutdown) : void{
        $this->onShutdown = $onShutdown;
    }

    abstract public function isClosed() : bool;

    abstract public function handleOutgoing() : void;

    abstract public function handleIncoming() : void;

    /**
     * Called when the stream is closed, by the peer
     */
    protected function onShutdownByPeer() : void{
        if($this->onShutdown !== null){
            ($this->onShutdown)(true);
        }
    }

    /**
     * Called when the connection is closed, by the local side or peer
     * @internal
     */
    public function onConnectionClose(bool $peerClosed) : void{
        if($this->onShutdown !== null){
            ($this->onShutdown)($peerClosed);
        }
    }

    /**
     * Called to check if the stream is closed by the local side.
     */
    protected function checkShutdown() : void{
        if($this->isClosed() && $this->onShutdown !== null){
            ($this->onShutdown)(false);
        }
    }
}