<?php

namespace NetherGames\Quiche\stats;

use NetherGames\Quiche\bindings\quiche_path_stats;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\SocketAddress;

class QuichePathStats extends MinimalQuicheStats{
    private SocketAddress $localAddress;
    private SocketAddress $peerAddress;

    private int $validationState;
    private bool $active;
    private float $rtt;
    private float $minRTT;
    private float $rttVar;
    private int $congestionWindow;
    private int $pmtu;
    private int $deliveryRate;

    public static function getQuichePathStats(
        QuicheFFI $bindings,
        struct_quiche_conn_ptr $connectionPointer,
        int $pathIndex,
    ) : self{
        /** @var quiche_path_stats $path */
        $bindings->quiche_conn_path_stats($connectionPointer, $pathIndex, [&$path]);

        return new self($path);
    }

    public function __construct(quiche_path_stats $stats){
        parent::__construct($stats);

        $this->localAddress = SocketAddress::createFromFFI($stats->local_addr);
        $this->peerAddress = SocketAddress::createFromFFI($stats->peer_addr);

        $this->validationState = $stats->validation_state;
        $this->active = (bool) $stats->active;
        $this->rtt = $stats->rtt;
        $this->minRTT = $stats->min_rtt;
        $this->rttVar = $stats->rttvar;
        $this->congestionWindow = $stats->cwnd;
        $this->pmtu = $stats->pmtu;
        $this->deliveryRate = $stats->delivery_rate;
    }

    public function getLocalAddress() : SocketAddress{
        return $this->localAddress;
    }

    public function getPeerAddress() : SocketAddress{
        return $this->peerAddress;
    }

    public function getValidationState() : int{
        return $this->validationState;
    }

    public function isActive() : bool{
        return $this->active;
    }

    public function getRTT() : float{
        return $this->rtt;
    }

    public function getMinRTT() : float{
        return $this->minRTT;
    }

    public function getRTTVar() : float{
        return $this->rttVar;
    }

    public function getCongestionWindow() : int{
        return $this->congestionWindow;
    }

    public function getPMTU() : int{
        return $this->pmtu;
    }

    public function getDeliveryRate() : int{
        return $this->deliveryRate;
    }
}