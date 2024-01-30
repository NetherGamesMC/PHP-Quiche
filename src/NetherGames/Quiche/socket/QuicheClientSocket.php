<?php

namespace NetherGames\Quiche\socket;

use Closure;
use LogicException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\quiche_recv_info_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_ptr;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\SocketAddress;
use RuntimeException;
use function random_bytes;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_recvfrom;

class QuicheClientSocket extends QuicheSocket{

    /** @var resource $socket */
    private $socket;
    private ?QuicheConnection $connection = null;
    private SocketAddress $localAddress;
    private quiche_recv_info_ptr $recvInfo;

    /**
     * @param Closure $acceptCallback function(QuicheConnection $connection, QuicheStream $stream) : void
     */
    public function __construct(
        private SocketAddress $peerAddress,
        Closure $acceptCallback,
        bool $enableDebugLogging = false,
    ){
        parent::__construct($acceptCallback, $enableDebugLogging);

        $socket = stream_socket_client(
            "udp://" . $peerAddress->getSocketAddress(),
            $errno,
            $errstr,
            null,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );

        if($socket === false || $errno !== 0){
            throw new RuntimeException("Failed to create socket: {$errstr} ({$errno})");
        }
        stream_set_blocking($socket, false);

        $this->socket = $socket;

        $localAddress = stream_socket_get_name($this->socket, true);
        if($localAddress === false){
            throw new RuntimeException("Failed to get local address");
        }

        $this->localAddress = SocketAddress::createFromAddress($localAddress);
    }

    public function tick() : void{
        if($this->connection !== null){
            if($this->connection->isClosed() || !$this->readSocket() || !$this->connection->handleOutgoing()){
                $this->connection = null;
            }
        }
    }

    /**
     * Can also be used to reconnect
     */
    public function connect() : QuicheConnection{
        $recvInfo = quiche_recv_info_ptr::array();
        $recvInfo->from = struct_sockaddr_ptr::castFrom($peerSockAddress = $this->localAddress->getSocketAddressFFI());
        $recvInfo->from_len = QuicheBindings::sizeof($peerSockAddress[0]);
        $recvInfo->to = struct_sockaddr_ptr::castFrom($localSockAddress = $this->peerAddress->getSocketAddressFFI());
        $recvInfo->to_len = QuicheBindings::sizeof($localSockAddress[0]);

        $connection = $this->bindings->quiche_connect(
            $this->peerAddress->getSocketAddress(),
            random_bytes(self::LOCAL_CONN_ID_LEN),
            self::LOCAL_CONN_ID_LEN,
            $recvInfo->to,
            $recvInfo->to_len,
            $recvInfo->from,
            $recvInfo->to_len,
            $this->config->getBinding(),
        );

        if($connection === null){
            throw new RuntimeException("Failed to create connection");
        }

        $this->recvInfo = $recvInfo;

        return $this->connection = new QuicheConnection(
            $this->bindings,
            $this,
            $this->tempBuffer,
            $this->config,
            $connection,
            $this->acceptCallback,
            $this->peerAddress
        );
    }

    public function getConnection() : QuicheConnection{
        if($this->connection === null){
            throw new LogicException("Not connected");
        }

        return $this->connection;
    }

    /**
     * @return bool whether the connection is still alive
     */
    private function readSocket() : bool{
        while(($buffer = stream_socket_recvfrom($this->socket, $this->config->getMaxRecvUdpPayloadSize())) !== false){
            if(!$this->connection?->handleIncoming($buffer, $this->recvInfo)){
                return false;
            }
        }

        return true;
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->connection?->close($applicationError, $error, $reason);
    }


    /**
     * @return resource $socket
     */
    public function getSocket(){
        return $this->socket;
    }
}