<?php

namespace NetherGames\Quiche\stream;

use Closure;
use InvalidArgumentException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\io\Buffer;
use NetherGames\Quiche\io\BufferUtils;
use NetherGames\Quiche\io\QueueReader;
use NetherGames\Quiche\io\QueueWriter;
use RuntimeException;

trait WriteableQuicheStreamTrait{
    private int $priority = 127; // https://github.com/cloudflare/quiche/blob/master/quiche/src/stream/mod.rs#L45
    /** @var ?Closure $writeClosure function(string $data, int $length) : int */
    private ?Closure $writeClosure = null;
    /** @var ?Closure function(bool $peerClosed) : void */
    private ?Closure $onShutdownWriting = null;

    private QueueReader $reader;
    private bool $writable = true;

    public function handleOutgoing() : bool{
        $written = BufferUtils::tryWrite(
            $this->reader,
            $this->writeClosure ??= fn(string $data, int $length) : int => $this->bindings->quiche_conn_stream_send($this->connection, $this->id, $data, $length, (int) ($length === 0 && !$this->writable))
        );

        if($written === QuicheBindings::QUICHE_ERR_DONE){
            if(($return = $this->bindings->quiche_conn_stream_writable($this->connection, $this->id, 0)) === QuicheBindings::QUICHE_ERR_INVALID_STREAM_STATE){
                $this->onShutdownWriting(true); // only way of checking STOP_SENDING :( : https://github.com/cloudflare/quiche/issues/1299

                return false;
            }elseif($return < 0){
                throw new RuntimeException("Failed to write to stream: " . $return);
            }
        }

        return true;
    }

    /**
     * @CAUTION if peerClosed, this method will only get called whenever you try to write to the stream and the stream is not writable
     * https://github.com/cloudflare/quiche/issues/1299
     *
     * @param ?Closure $onShutdownWriting (bool $peerClosed)
     */
    public function setShutdownWritingCallback(?Closure $onShutdownWriting) : void{
        $this->onShutdownWriting = $onShutdownWriting;
    }

    public function setupWriter() : QueueWriter{
        if(isset($this->reader)){
            throw new RuntimeException("Stream already has a writer");
        }

        [$read, $write] = Buffer::create(fn() => $this->handleOutgoing());
        $this->reader = $read;

        return $write;
    }

    public function isWritable() : bool{
        return $this->writable;
    }

    protected function onShutdownWriting(bool $peerClosed) : void{
        if(!$this->writable){
            return;
        }

        $this->writable = false;
        $this->reader->close();

        if($this->onShutdownWriting !== null){
            ($this->onShutdownWriting)($peerClosed);
        }

        $this->onPartialClose($peerClosed);
    }

    public function gracefulShutdownWriting() : void{
        if(!$this->isWritable()){
            throw new RuntimeException("Stream is already closed");
        }

        $this->handleOutgoing(); // send the current buffer
        $this->bindings->quiche_conn_stream_send($this->connection, $this->id, null, 0, 1);
        $this->handleOutgoing(); // send the FIN
        $this->onShutdownWriting(false);
    }

    public function forceShutdownWriting(int $reason) : void{
        if(!$this->isWritable()){
            throw new RuntimeException("Stream is already closed");
        }

        $this->bindings->quiche_conn_stream_shutdown(
            $this->connection,
            $this->id,
            QuicheBindings::QUICHE_SHUTDOWN_WRITE,
            $reason,
        );

        $this->handleOutgoing(); // send the shutdown
        $this->onShutdownWriting(false);
    }

    public function setPriority(int $priority, bool $incremental = true) : void{
        if($priority < 0 || $priority > 255){
            throw new InvalidArgumentException("Priority must be between 0 and 255");
        }

        if($this->priority !== $priority){
            $this->bindings->quiche_conn_stream_priority($this->connection, $this->id, $priority, (int) $incremental);
            $this->priority = $priority;
        }
    }
}