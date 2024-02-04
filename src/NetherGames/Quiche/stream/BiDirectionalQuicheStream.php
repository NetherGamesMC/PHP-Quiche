<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use RuntimeException;

class BiDirectionalQuicheStream extends QuicheStream{
    use ReadableQuicheStreamTrait;
    use WriteableQuicheStreamTrait;

    /** @var ?Closure function(bool $peerClosed) : void */
    private ?Closure $onShutdown = null;

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

    public function setShutdownCallback(?Closure $onShutdown) : void{
        $this->onShutdown = $onShutdown;
    }

    protected function onPartialClose(bool $peerClosed) : void{
        parent::onPartialClose($peerClosed);

        if($this->isClosed() && $this->onShutdown !== null){
            ($this->onShutdown)($peerClosed);
        }
    }

    public function onConnectionClose(bool $peerClosed) : void{
        $this->onShutdownWriting($peerClosed);
        $this->onShutdownReading($peerClosed);
    }

    public function isClosed() : bool{
        return !$this->isReadable() && !$this->isWritable();
    }
}