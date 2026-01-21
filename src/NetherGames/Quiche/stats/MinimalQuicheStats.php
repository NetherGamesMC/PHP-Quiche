<?php

namespace NetherGames\Quiche\stats;

use NetherGames\Quiche\bindings\quiche_path_stats;
use NetherGames\Quiche\bindings\quiche_stats;

class MinimalQuicheStats{
    private int $receivedPackets;
    private int $sentPackets;
    private int $lostPackets;
    private int $retransmittedPackets;
    private int $sentBytes;
    private int $receivedBytes;
    private int $lostBytes;
    private int $streamRetransmittedBytes;
    private int $receivedDatagrams;
    private int $sentDatagrams;

    public function __construct(quiche_stats|quiche_path_stats $stats){
        $this->receivedPackets = $stats->recv;
        $this->sentPackets = $stats->sent;
        $this->lostPackets = $stats->lost;
        $this->retransmittedPackets = $stats->retrans;
        $this->sentBytes = $stats->sent_bytes;
        $this->receivedBytes = $stats->recv_bytes;
        $this->lostBytes = $stats->lost_bytes;
        $this->streamRetransmittedBytes = $stats->stream_retrans_bytes;
        $this->receivedDatagrams = $stats->dgram_recv;
        $this->sentDatagrams = $stats->dgram_sent;
    }

    public function getReceivedPackets() : int{
        return $this->receivedPackets;
    }

    public function getSentPackets() : int{
        return $this->sentPackets;
    }

    public function getLostPackets() : int{
        return $this->lostPackets;
    }

    public function getRetransmittedPackets() : int{
        return $this->retransmittedPackets;
    }

    public function getSentBytes() : int{
        return $this->sentBytes;
    }

    public function getReceivedBytes() : int{
        return $this->receivedBytes;
    }

    public function getLostBytes() : int{
        return $this->lostBytes;
    }

    public function getStreamRetransmittedBytes() : int{
        return $this->streamRetransmittedBytes;
    }

    public function getReceivedDatagrams() : int{
        return $this->receivedDatagrams;
    }

    public function getSentDatagrams() : int{
        return $this->sentDatagrams;
    }
}