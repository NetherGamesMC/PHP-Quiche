<?php

namespace NetherGames\Quiche\event;

use NetherGames\Quiche\SocketAddress;

class ReusedSourceConnectionIdPathEvent extends PathEvent{
    public function __construct(private readonly int $id, private readonly SocketAddress $oldLocalAddress, private readonly SocketAddress $oldPeerAddress, SocketAddress $localAddress, SocketAddress $peerAddress){
        parent::__construct($localAddress, $peerAddress);
    }

    public function getId() : int{
        return $this->id;
    }

    public function getOldLocalAddress() : SocketAddress{
        return $this->oldLocalAddress;
    }

    public function getOldPeerAddress() : SocketAddress{
        return $this->oldPeerAddress;
    }
}