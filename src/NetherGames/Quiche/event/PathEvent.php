<?php

namespace NetherGames\Quiche\event;

use NetherGames\Quiche\SocketAddress;

class PathEvent extends Event{
    public function __construct(private readonly SocketAddress $localAddress, private readonly SocketAddress $peerAddress){
    }

    public function getLocalAddress() : SocketAddress{
        return $this->localAddress;
    }

    public function getPeerAddress() : SocketAddress{
        return $this->peerAddress;
    }
}