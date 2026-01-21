<?php

namespace NetherGames\Quiche\socket;

use Closure;
use LogicException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\SocketAddress;
use RuntimeException;
use Socket;
use function random_bytes;
use function socket_recvfrom;
use function strlen;
use const AF_INET;
use const MSG_DONTWAIT;

class QuicheClientSocket extends QuicheSocket{

    private ?QuicheConnection $connection = null;

    /**
     * @param Closure         $acceptCallback function(QuicheConnection $connection, QuicheStream $stream) : void
     * @param SocketAddress[] $address
     */
    public function __construct(
        private SocketAddress $peerAddress,
        Closure $acceptCallback,
        bool $enableDebugLogging = false,
        array $address = []
    ){
        parent::__construct($acceptCallback, $enableDebugLogging);

        if(empty($address)){
            $address = [new SocketAddress($this->peerAddress->getSocketFamily() === AF_INET ? "0.0.0.0" : "::", 0)];
        }

        $this->registerUDPSockets($address);
    }

    protected function handleOutgoing() : void{
        if($this->connection !== null){
            if($this->connection->isClosed() || !$this->connection->handleOutgoing()){
                $this->onClosed();
            }
        }
    }

    /**
     * Can also be used to reconnect
     */
    public function connect(?string $session = null) : QuicheConnection{
        if($this->connection !== null){
            throw new LogicException("Already connected");
        }

        $socketId = $this->getSocketIds()[0];
        $info = SocketAddress::createRevcInfo($localAddress = $this->getLocalAddressBySocketId($socketId), $this->peerAddress);

        $connection = $this->bindings->quiche_connect(
            $this->peerAddress->getHostname(),
            random_bytes($scidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN),
            $scidLength,
            $info->from,
            $info->from_len,
            $info->to,
            $info->to_len,
            $this->config->getBinding(),
        );

        if($connection === null){
            throw new RuntimeException("Failed to create connection");
        }

        if($session !== null && ($error = $this->bindings->quiche_conn_set_session($connection, $session, strlen($session))) !== 0){
            throw new RuntimeException("Failed to set session: $error");
        }

        return $this->connection = new QuicheConnection(
            $this->bindings,
            $this,
            $this->tempBuffer,
            $this->config,
            $connection,
            $this->acceptCallback,
            $localAddress,
            $this->peerAddress,
            $socketId
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
    protected function readSocket(int $socketId, Socket $socket) : bool{
        while(($bufferLength = socket_recvfrom($socket, $buffer, $this->config->getMaxRecvUdpPayloadSize(), MSG_DONTWAIT, $peerAddr, $peerPort)) !== false){
            if(!$this->connection?->handleIncoming($buffer, $bufferLength, $this->getLocalAddressBySocketId($socketId), new SocketAddress($peerAddr, $peerPort))){
                return false;
            }
        }

        return true;
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->connection?->close($applicationError, $error, $reason);

        parent::close($applicationError, $error, $reason);
    }

    public function setNonWritableSocket(int $socketId) : void{
        if($this->isRegisteredNonWritableSocket($socketId)){
            return;
        }

        $this->registerNonWritableSocket($this->getSocketById($socketId), function() use ($socketId) : void{
            if($this->connection?->handleOutgoingQueue() ?? true){
                $this->removeNonWritableSocket($socketId);

                $this->connection?->handleOutgoing();
            }
        });
    }

    public function addSCID(string $scid, QuicheConnection $connection) : void{
        // do nothing
    }

    public function removeSCID(string $scid) : void{
        // do nothing
    }
}