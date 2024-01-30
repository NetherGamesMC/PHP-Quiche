<?php

namespace NetherGames\Quiche;

use Socket;

class SocketHolder{
    private Socket $socket;
    private SocketAddress $socketAddress;

    public function __construct(Socket $socket, SocketAddress $socketAddress){
        $this->socket = $socket;
        $this->socketAddress = $socketAddress;
    }

    public function getSocket() : Socket{
        return $this->socket;
    }

    public function getSocketAddress() : SocketAddress{
        return $this->socketAddress;
    }
}