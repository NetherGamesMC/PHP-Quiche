<?php

namespace NetherGames\Quiche\stats;

use NetherGames\Quiche\bindings\quiche_stats;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;

class QuicheStats extends MinimalQuicheStats{

    public static function getConnectionStats(QuicheFFI $bindings, struct_quiche_conn_ptr $connectionPointer) : self{
        /** @var quiche_stats $stats */
        $bindings->quiche_conn_stats($connectionPointer, [&$stats]);

        return new self($bindings, $connectionPointer, $stats);
    }

    /** @var list<QuichePathStats> */
    private array $paths = [];
    private int $ackedBytes;
    private int $resetStreamCountLocal;
    private int $stoppedStreamCountLocal;
    private int $resetStreamCountRemote;
    private int $stoppedStreamCountRemote;

    public function __construct(
        QuicheFFI $bindings,
        struct_quiche_conn_ptr $connectionPointer,
        quiche_stats $stats,
    ){
        parent::__construct($stats);

        for($i = 0; $i < $stats->paths_count; ++$i){
            $this->paths[] = QuichePathStats::getQuichePathStats($bindings, $connectionPointer, $i);
        }

        $this->ackedBytes = $stats->acked_bytes;
        $this->resetStreamCountLocal = $stats->reset_stream_count_local;
        $this->stoppedStreamCountLocal = $stats->stopped_stream_count_local;
        $this->resetStreamCountRemote = $stats->reset_stream_count_remote;
        $this->stoppedStreamCountRemote = $stats->stopped_stream_count_remote;
    }

    /**
     * @return QuichePathStats[]
     */
    public function getPaths() : array{
        return $this->paths;
    }

    public function getAckedBytes() : int{
        return $this->ackedBytes;
    }

    public function getResetStreamCountLocal() : int{
        return $this->resetStreamCountLocal;
    }

    public function getStoppedStreamCountLocal() : int{
        return $this->stoppedStreamCountLocal;
    }

    public function getResetStreamCountRemote() : int{
        return $this->resetStreamCountRemote;
    }

    public function getStoppedStreamCountRemote() : int{
        return $this->stoppedStreamCountRemote;
    }
}