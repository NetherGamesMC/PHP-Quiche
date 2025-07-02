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
use function socket_bind;
use function spl_object_id;
use function strlen;
use const AF_INET;

class QuicheClientSocket extends QuicheSocket{

    private Socket $socket;
    private ?QuicheConnection $connection = null;

    /**
     * @param Closure $acceptCallback function(QuicheConnection $connection, QuicheStream $stream) : void
     */
    public function __construct(
        private SocketAddress $peerAddress,
        Closure $acceptCallback,
        bool $enableDebugLogging = false,
    ){
        parent::__construct($acceptCallback, $enableDebugLogging);

        $socket = socket_create($family = $this->peerAddress->getSocketFamily(), SOCK_DGRAM, SOL_UDP);
        if($socket === false || !socket_bind($socket, $family === AF_INET ? "0.0.0.0" : "::")){
            $errno = socket_last_error();
            $errstr = socket_strerror($errno);
            throw new RuntimeException("Failed to create socket: $errstr ($errno)");
        }

        $this->setupSocketSettings($socket);

        $this->socket = $socket;

        $this->registerSocket($socket, function() : void{
            if(!$this->readSocket()){
                $this->onClosed();
            }
        });
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
    public function connect(string $session = null) : QuicheConnection{
        if($this->connection !== null){
            throw new LogicException("Already connected");
        }

        $info = SocketAddress::createRevcInfo($localAddress = $this->getLocalAddress($this->socket), $this->peerAddress);

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
        while(($length = socket_recvfrom($this->socket, $buffer, $this->config->getMaxRecvUdpPayloadSize(), MSG_DONTWAIT, $peerAddr, $peerPort)) !== false){
            if(!$this->connection?->handleIncoming($buffer, $length, $this->getLocalAddress($this->socket), new SocketAddress($peerAddr, $peerPort))){
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

    public function addSCID(string $scid, QuicheConnection $connection) : void{
        // do nothing
    }

    public function removeSCID(string $scid) : void{
        // do nothing
    }
}