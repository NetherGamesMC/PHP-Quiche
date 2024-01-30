<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\socket\QuicheSocket;
use RuntimeException;

trait ReadableQuicheStreamTrait{

    /** @var Closure function(string $data) : void */
    protected Closure $onDataArrival;
    protected bool $readable = true;

    public function handleIncoming() : void{
        $received = $this->bindings->quiche_conn_stream_recv(
            $this->connection,
            $this->id,
            $this->tempBuffer,
            QuicheSocket::SEND_BUFFER_SIZE,
            [&$fin]
        );

        if($fin || $received === QuicheBindings::QUICHE_ERR_STREAM_RESET || $received === QuicheBindings::QUICHE_ERR_INVALID_STREAM_STATE){
            $this->onShutdownByPeer();
        }else if($received < 0){
            throw new RuntimeException("Failed to read from stream: " . $received);
        }

        if($received > 0){
            ($this->onDataArrival)($this->tempBuffer->toString($received));
        }
    }

    protected function onShutdownReading() : void{
        $this->readable = false;
    }

    public function isReadable() : bool{
        return $this->readable;
    }

    protected function shutdownReading(int $reason = 0) : void{
        $this->bindings->quiche_conn_stream_shutdown(
            $this->connection,
            $this->id,
            QuicheBindings::QUICHE_SHUTDOWN_READ,
            $reason,
        );

        $this->onShutdownReading();
        $this->checkShutdown();
    }

    /**
     * @param Closure $onDataArrival function(string $data) : void
     */
    public function setOnDataArrival(Closure $onDataArrival) : void{
        $this->onDataArrival = $onDataArrival;
    }
}