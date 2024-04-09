<?php

namespace NetherGames\Quiche\stream;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\socket\QuicheSocket;
use RuntimeException;

trait ReadableQuicheStreamTrait{

    /** @var Closure function(string $data) : void */
    private Closure $onDataArrival;
    /** @var Closure[] function(bool $peerClosed) : void */
    private array $onShutdownReading = [];
    private bool $readable = true;

    public function handleIncoming() : void{
        $received = $this->bindings->quiche_conn_stream_recv(
            $this->connection,
            $this->id,
            $this->tempBuffer,
            QuicheSocket::SEND_BUFFER_SIZE,
            [&$fin]
        );

        if($received > 0){
            ($this->onDataArrival)($this->tempBuffer->toString($received));
        }else if($received === QuicheBindings::QUICHE_ERR_STREAM_RESET || $received === QuicheBindings::QUICHE_ERR_INVALID_STREAM_STATE){
            $this->onConnectionClose(true);

            return;
        }elseif($received < 0){
            throw new RuntimeException("Failed to read from stream: " . $received);
        }

        if($fin){
            $this->onShutdownReading(true);
        }
    }

    /**
     * @param Closure $onShutdownReading function(bool $peerClosed) : void
     */
    public function addShutdownReadingCallback(Closure $onShutdownReading) : void{
        $this->onShutdownReading[] = $onShutdownReading;
    }

    protected function onShutdownReading(bool $peerClosed) : void{
        if(!$this->readable){
            return;
        }

        $this->readable = false;

        foreach($this->onShutdownReading as $onShutdownReading){
            $onShutdownReading($peerClosed);
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