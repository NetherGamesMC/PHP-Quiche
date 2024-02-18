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
use Socket;
use function random_bytes;
use function socket_getsockname;
use function socket_last_error;
use function spl_object_id;

class QuicheClientSocket extends QuicheSocket{

    private Socket $socket;
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

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if($socket === false || !socket_connect($socket, $peerAddress->getAddress(), $peerAddress->getPort())){
            $errno = socket_last_error();
            $errstr = socket_strerror($errno);
            throw new RuntimeException("Failed to create socket: $errstr ($errno)");
        }

        $this->socket = $socket;

        if(socket_getsockname($socket, $localAddress, $localPort) === false){
            throw new RuntimeException("Failed to get local address");
        }

        $this->localAddress = new SocketAddress($localAddress, $localPort);

        $this->registerSocket($socket, function() : void{
            if(!$this->readSocket()){
                $this->connection = null;
            }
        });
    }

    protected function handleOutgoing() : void{
        if($this->connection !== null){
            if($this->connection->isClosed() || !$this->connection->handleOutgoing()){
                $this->connection = null;
            }
        }
    }

    /**
     * Can also be used to reconnect
     */
    public function connect() : QuicheConnection{
        $recvInfo = quiche_recv_info_ptr::array();
        $recvInfo->from = struct_sockaddr_ptr::castFrom($localSockAddress = $this->localAddress->getSocketAddressFFI());
        $recvInfo->from_len = QuicheBindings::sizeof($localSockAddress[0]);
        $recvInfo->to = struct_sockaddr_ptr::castFrom($peerSockAddress = $this->peerAddress->getSocketAddressFFI());
        $recvInfo->to_len = QuicheBindings::sizeof($peerSockAddress[0]);

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
            $this->peerAddress,
            spl_object_id($this->socket)
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
        while(($length = socket_recv($this->socket, $buffer, $this->config->getMaxRecvUdpPayloadSize(), MSG_DONTWAIT)) !== false){
            if(!$this->connection?->handleIncoming($buffer, $length, $this->recvInfo)){
                return false;
            }
        }

        return true;
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->connection?->close($applicationError, $error, $reason);

        parent::close($applicationError, $error, $reason);
    }

    public function getSocket() : Socket{
        return $this->socket;
    }

    public function setNonWritableSocket(int $socketId) : void{
        if($this->isRegisteredNonWritableSocket($socketId)){
            return;
        }

        $this->registerNonWritableSocket($this->socket, function() use ($socketId) : void{
            if($this->connection?->handleOutgoingQueue() ?? true){
                $this->removeNonWritableSocket($socketId);

                $this->connection?->handleOutgoing();
            }
        });
    }
}