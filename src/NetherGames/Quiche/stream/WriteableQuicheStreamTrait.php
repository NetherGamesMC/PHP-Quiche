<?php

namespace NetherGames\Quiche\stream;

use Closure;
use InvalidArgumentException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\io\Buffer;
use NetherGames\Quiche\io\BufferUtils;
use NetherGames\Quiche\io\QueueReader;
use RuntimeException;
use function is_int;

trait WriteableQuicheStreamTrait{
    private int $priority = 127; // https://github.com/cloudflare/quiche/blob/master/quiche/src/stream/mod.rs#L45
    /** @var ?Closure $writeClosure function(string $data, int $length) : int */
    private ?Closure $writeClosure = null;

    protected QueueReader $reader;
    protected bool $writable = true;

    public function handleOutgoing() : void{
        $success = BufferUtils::tryWrite(
            $this->reader,
            $this->writeClosure ??= fn(string $data, int $length) : int => $this->bindings->quiche_conn_stream_send($this->connection, $this->id, $data, $length, (int) ($length === 0 && !$this->writable))
        );

        if(is_int($success)){
            throw new RuntimeException("Failed to write to stream: " . $success);
        }
    }

    public function setWriteBuffer(Buffer $buffer) : void{
        $this->reader = new QueueReader($buffer);
    }

    public function isWritable() : bool{
        return $this->writable;
    }

    protected function onShutdownWriting() : void{
        $this->writable = false;
        $this->reader->close();
    }

    protected function shutdownWriting(int $reason = 0, bool $hard = false) : void{
        if($hard){
            $this->bindings->quiche_conn_stream_shutdown(
                $this->connection,
                $this->id,
                QuicheBindings::QUICHE_SHUTDOWN_WRITE,
                $reason,
            );
        }else{
            $this->handleOutgoing(); // send the data asap
            $this->bindings->quiche_conn_stream_send($this->connection, $this->id, null, 0, 1);
        }

        $this->handleOutgoing(); // send the FIN
        $this->onShutdownWriting();
        $this->checkShutdown();
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