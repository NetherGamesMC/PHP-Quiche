<?php

namespace NetherGames\Quiche\stream;

use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;

abstract class QuicheStream{
    public function __construct(
        protected readonly QuicheFFI $bindings,
        protected readonly int $id,
        protected readonly struct_quiche_conn_ptr $connection,
    ){
    }

    abstract public function isClosed() : bool;

    abstract public function handleOutgoing() : void;

    abstract public function handleIncoming() : void;

    /**
     * Called when the connection is fully or partially closed, by the local side or peer
     */
    protected function onPartialClose(bool $peerClosed) : void{
        // Do nothing
    }

    /**
     * Called when the connection is closed, by the local side or peer
     * @internal
     */
    abstract public function onConnectionClose(bool $peerClosed) : void;
}