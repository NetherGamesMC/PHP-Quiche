<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\socket\QuicheSocket;
use RuntimeException;

trait ReadableQuicheStreamTrait{

    /** @var Closure function(string $data) : void */
    private Closure $onDataArrival;
    /** @var ?Closure function(bool $peerClosed) : void */
    private ?Closure $onShutdownReading = null;
    private bool $readable = true;

    public function handleIncoming() : bool{
        $received = $this->bindings->quiche_conn_stream_recv(
            $this->connection,
            $this->id,
            $this->tempBuffer,
            QuicheSocket::SEND_BUFFER_SIZE,
            [&$fin]
        );

        if($received > 0){
            ($this->onDataArrival)($this->tempBuffer->toString($received));

            if($fin){
                $this->onShutdownReading(true);

                return false;
            }
        }else if($received === QuicheBindings::QUICHE_ERR_STREAM_RESET || $received === QuicheBindings::QUICHE_ERR_INVALID_STREAM_STATE){
            $this->onConnectionClose(true);

            return false;
        }elseif($received < 0){
            throw new RuntimeException("Failed to read from stream: " . $received);
        }

        return true;
    }

    public function setShutdownReadingCallback(?Closure $onShutdownReading) : void{
        $this->onShutdownReading = $onShutdownReading;
    }

    protected function onShutdownReading(bool $peerClosed) : void{
        if(!$this->readable){
            return;
        }

        $this->readable = false;

        if($this->onShutdownReading !== null){
            ($this->onShutdownReading)($peerClosed);
        }

        $this->onPartialClose($peerClosed);
    }

    public function isReadable() : bool{
        return $this->readable;
    }

    public function shutdownReading(int $reason = 0) : void{
        $this->bindings->quiche_conn_stream_shutdown(
            $this->connection,
            $this->id,
            QuicheBindings::QUICHE_SHUTDOWN_READ,
            $reason,
        );

        $this->onShutdownReading(false);
    }

    /**
     * @param Closure $onDataArrival function(string $data) : void
     */
    public function setOnDataArrival(Closure $onDataArrival) : void{
        $this->onDataArrival = $onDataArrival;
    }
}