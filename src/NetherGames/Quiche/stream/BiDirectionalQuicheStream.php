<?php

namespace NetherGames\Quiche\stream;

use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use RuntimeException;

class BiDirectionalQuicheStream extends QuicheStream{
    use ReadableQuicheStreamTrait;
    use WriteableQuicheStreamTrait;

    public function __construct(
        QuicheFFI $bindings,
        int $id,
        struct_quiche_conn_ptr $connection,
        protected uint8_t_ptr $tempBuffer
    ){
        if($id % 4 !== 0 && $id % 4 !== 1){ // Bidirectional streams are 0x0 and 0x1
            throw new RuntimeException("Invalid stream ID $id for bidirectional stream");
        }

        parent::__construct($bindings, $id, $connection);
    }

    public function shutdown(int $reason = 0, bool $hard = false) : void{ // todo: ask how this behaviour is supposed to work
        $this->shutdownReading($reason);
        $this->shutdownWriting($reason, $hard);
    }

    public function onConnectionClose(bool $peerClosed) : void{
        $this->onShutdownWriting();
        $this->onShutdownReading();

        parent::onConnectionClose($peerClosed);
    }

    protected function onShutdownByPeer() : void{
        $this->onShutdownWriting();
        $this->onShutdownReading();

        parent::onShutdownByPeer();
    }

    public function isClosed() : bool{
        return !$this->readable && !$this->writable;
    }
}