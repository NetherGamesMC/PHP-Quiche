<?php

namespace NetherGames\Quiche\stream;

use BadMethodCallException;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use RuntimeException;

class WriteableQuicheStream extends QuicheStream{
    use WriteableQuicheStreamTrait;

    public function __construct(
        QuicheFFI $bindings,
        int $id,
        struct_quiche_conn_ptr $connection
    ){
        if($id % 4 !== 2 && $id % 4 !== 3){ // Unidirectional streams are 0x2 and 0x3
            throw new RuntimeException("Invalid stream ID $id for unidirectional stream");
        }

        parent::__construct($bindings, $id, $connection);
    }

    public function onConnectionClose(bool $peerClosed) : void{
        $this->onShutdownWriting($peerClosed);
    }

    public function isClosed() : bool{
        return !$this->isWritable();
    }

    public function handleIncoming() : bool{
        throw new BadMethodCallException("Cannot handle incoming data on a writeable stream");
    }
}