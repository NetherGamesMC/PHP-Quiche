<?php

namespace NetherGames\Quiche\stream;

use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use RuntimeException;

class ReadableQuicheStream extends QuicheStream{
    use ReadableQuicheStreamTrait;

    public function __construct(
        QuicheFFI $bindings,
        int $id,
        struct_quiche_conn_ptr $connection,
        protected uint8_t_ptr $tempBuffer
    ){
        if($id % 4 !== 2 && $id % 4 !== 3){ // Unidirectional streams are 0x2 and 0x3
            throw new RuntimeException("Invalid stream ID $id for unidirectional stream");
        }

        parent::__construct($bindings, $id, $connection);
    }

    public function handleOutgoing() : void{
        // do nothing;
    }

    public function shutdown(int $reason = 0) : void{
        $this->shutdownReading($reason);
    }

    public function onConnectionClose(bool $peerClosed) : void{
        $this->onShutdownReading();

        parent::onConnectionClose($peerClosed);
    }

    protected function onShutdownByPeer() : void{
        $this->onShutdownReading();

        parent::onShutdownByPeer();
    }

    public function isClosed() : bool{
        return !$this->readable;
    }
}