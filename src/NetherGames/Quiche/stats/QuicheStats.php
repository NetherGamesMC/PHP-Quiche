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
    private int $spuriousLostPackets;
    private int $ackedBytes;
    private int $resetStreamCountLocal;
    private int $stoppedStreamCountLocal;
    private int $resetStreamCountRemote;
    private int $stoppedStreamCountRemote;
    private int $blockedDataSentCount;
    private int $blockedStreamDataSentCount;
    private int $blockedDataReceivedCount;
    private int $blockedStreamDataReceivedCount;
    private int $receivedPathChallengeCount;
    private int $inFlightBytesDurationMsec;
    private bool $bufferSendInconsistent;

    public function __construct(
        QuicheFFI $bindings,
        struct_quiche_conn_ptr $connectionPointer,
        quiche_stats $stats,
    ){
        parent::__construct($stats);

        for($i = 0; $i < $stats->paths_count; ++$i){
            $this->paths[] = QuichePathStats::getQuichePathStats($bindings, $connectionPointer, $i);
        }

        $this->spuriousLostPackets = $stats->spurious_lost;
        $this->ackedBytes = $stats->acked_bytes;
        $this->resetStreamCountLocal = $stats->reset_stream_count_local;
        $this->stoppedStreamCountLocal = $stats->stopped_stream_count_local;
        $this->resetStreamCountRemote = $stats->reset_stream_count_remote;
        $this->stoppedStreamCountRemote = $stats->stopped_stream_count_remote;
        $this->blockedDataSentCount = $stats->data_blocked_sent_count;
        $this->blockedStreamDataSentCount = $stats->stream_data_blocked_sent_count;
        $this->blockedDataReceivedCount = $stats->data_blocked_recv_count;
        $this->blockedStreamDataReceivedCount = $stats->stream_data_blocked_recv_count;
        $this->receivedPathChallengeCount = $stats->path_challenge_rx_count;
        $this->inFlightBytesDurationMsec = $stats->bytes_in_flight_duration_msec;
        $this->bufferSendInconsistent = (bool) $stats->tx_buffered_inconsistent;
    }

    /**
     * @return QuichePathStats[]
     */
    public function getPaths() : array{
        return $this->paths;
    }

    public function getSpuriousLostPackets() : int{
        return $this->spuriousLostPackets;
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

    public function getBlockedDataSentCount() : int{
        return $this->blockedDataSentCount;
    }

    public function getBlockedStreamDataSentCount() : int{
        return $this->blockedStreamDataSentCount;
    }

    public function getBlockedDataReceivedCount() : int{
        return $this->blockedDataReceivedCount;
    }

    public function getBlockedStreamDataReceivedCount() : int{
        return $this->blockedStreamDataReceivedCount;
    }

    public function getReceivedPathChallengeCount() : int{
        return $this->receivedPathChallengeCount;
    }

    public function getInFlightBytesDurationMsec() : int{
        return $this->inFlightBytesDurationMsec;
    }

    public function isBufferSendInconsistent() : bool{
        return $this->bufferSendInconsistent;
    }
}