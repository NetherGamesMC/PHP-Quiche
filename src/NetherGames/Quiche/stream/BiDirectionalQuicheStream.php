<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use RuntimeException;

class BiDirectionalQuicheStream extends QuicheStream{
    use ReadableQuicheStreamTrait;
    use WriteableQuicheStreamTrait;

    /** @var Closure[] function(bool $peerClosed) : void */
    private array $onShutdown = [];

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

    /**
     * @CAUTION if peerClosed, this method will only get called whenever you try to write to the stream and the stream is not writable
     * https://github.com/cloudflare/quiche/issues/1299
     *
     * @param Closure $onShutdown function(bool $peerClosed) : void
     */
    public function addShutdownCallback(Closure $onShutdown) : void{
        $this->onShutdown[] = $onShutdown;
    }

    protected function onPartialClose(bool $peerClosed) : void{
        // HACK: Check if the stream is closed due to STOP_SENDING when it's no longer readable
        if($this->isWritable() && $this->bindings->quiche_conn_stream_writable($this->connection, $this->id, 0) === QuicheBindings::QUICHE_ERR_INVALID_STREAM_STATE){
            $this->onShutdownWriting(true); // only way of checking STOP_SENDING :( : https://github.com/cloudflare/quiche/issues/1299
        }

        parent::onPartialClose($peerClosed);

        if($this->isClosed()){
            foreach($this->onShutdown as $onShutdown){
                $onShutdown($peerClosed);
            }
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