<?php

namespace NetherGames\Quiche\socket;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\Config;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\SocketAddress;
use Socket;
use function array_filter;
use function bin2hex;
use function random_bytes;
use function socket_recvfrom;
use function socket_sendto;
use function str_starts_with;
use function strlen;
use function substr;
use const MSG_DONTWAIT;

class QuicheServerSocket extends QuicheSocket{

    /** @var array<string, QuicheConnection> */
    private array $connections = [];

    /**
     * @param SocketAddress[] $address
     * @param Closure         $acceptCallback function(QuicheConnection $connection, ?QuicheStream $stream) : void (stream is null for new connections)
     */
    public function __construct(array $address, Closure $acceptCallback, bool $enableDebugLogging = false){
        parent::__construct($acceptCallback, $enableDebugLogging);

        if(empty($address)){
            throw new \InvalidArgumentException("At least one address must be provided for the server socket.");
        }

        $this->registerUDPSockets($address);
    }

    protected function handleOutgoing() : void{
        foreach($this->connections as $dcid => $connection){
            if($connection->isClosed() || !$connection->handleOutgoing()){
                $this->removeConnection($dcid);
            }
        }
    }

    public function addSCID(string $scid, QuicheConnection $connection) : void{
        $this->connections[$scid] = $connection;
    }

    public function removeSCID(string $scid) : void{
        unset($this->connections[$scid]);
    }

    protected function readSocket(int $socketId, Socket $socket) : void{
        while(($bufferLength = socket_recvfrom($socket, $buffer, $this->config->getMaxRecvUdpPayloadSize(), MSG_DONTWAIT, $peerAddr, $peerPort)) !== false){
            $scid = uint8_t_ptr::array($scidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN);
            $dcid = uint8_t_ptr::array($dcidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN);
            $token = uint8_t_ptr::array($tokenLength = 128);

            $success = $this->bindings->quiche_header_info(
                $buffer,
                $bufferLength,
                QuicheBindings::QUICHE_MAX_CONN_ID_LEN,
                [&$version],
                [&$type],
                $scid,
                [&$scidLength],
                $dcid,
                [&$dcidLength],
                $token,
                [&$tokenLength]
            );

            if($success < 0){
                return; // not a QUIC packet
            }

            $peerAddress = new SocketAddress($peerAddr, $peerPort);
            $connection = $this->connections[$dcidString = $dcid->toString($dcidLength)] ?? null;
            if($connection === null && $type === 1){ // connection is new
                $connection = $this->createConnection(
                    $peerAddress,
                    $socketId,
                    $socket,
                    $scid,
                    $scidLength,
                    $dcid,
                    $dcidLength,
                    $token,
                    $tokenLength,
                    $version
                );
            }

            if(!$connection?->handleIncoming(
                $buffer,
                $bufferLength,
                $this->getLocalAddressBySocketId($socketId),
                $peerAddress
            )){
                $this->removeConnection($dcidString);

                if($this->isClosed()){
                    return;
                }
            }
        }
    }

    private function removeConnection(string $dcid) : void{
        unset($this->connections[$dcid]);
    }

    private function createConnection(
        SocketAddress $peerAddr,
        int $socketId,
        Socket $socket,
        uint8_t_ptr $scid,
        int $scidLength,
        uint8_t_ptr $dcid,
        int $dcidLength,
        uint8_t_ptr $token,
        int $tokenLength,
        int $version
    ) : ?QuicheConnection{
        if(!$this->bindings->quiche_version_is_supported($version)){
            $written = $this->bindings->quiche_negotiate_version($scid, $scidLength, $dcid, $dcidLength, $this->tempBuffer, $this->config->getMaxSendUdpPayloadSize());

            socket_sendto($socket, $this->tempBuffer->toString($written), $written, 0, $peerAddr->getAddress(), $peerAddr->getPort());

            return null;
        }

        /** @var string $dcidString */
        $dcidString = $dcid->toString($dcidLength);

        if($this->config->isStatelessRetryEnabled()){
            $tokenPrefix = "quiche" . $peerAddr->getSocketAddress(); // todo: make cryptographically secure

            if($tokenLength === 0){ // stateless retry
                $mintToken = $tokenPrefix . $dcidString;

                $written = $this->bindings->quiche_retry(
                    $scid,
                    $scidLength,
                    $dcid,
                    $dcidLength,
                    random_bytes($scidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN),
                    $scidLength,
                    $mintToken,
                    strlen($mintToken),
                    $version,
                    $this->tempBuffer,
                    $this->config->getMaxSendUdpPayloadSize()
                );

                socket_sendto($socket, $this->tempBuffer->toString($written), $written, 0, $peerAddr->getAddress(), $peerAddr->getPort());

                return null;
            }

            if(!str_starts_with($token->toString($tokenLength), $tokenPrefix)){ // todo: make cryptographically secure
                return null; // invalid token
            }

            if($dcidLength !== QuicheBindings::QUICHE_MAX_CONN_ID_LEN){
                return null; // invalid DCID length
            }

            $originalDcid = substr($token->toString($tokenLength), strlen($tokenPrefix));
        }else{
            $originalDcid = null;
        }

        $localAddr = $this->getLocalAddressBySocketId($socketId);
        $localSocketAddr = $localAddr->getSocketAddressPtr();

        $connection = $this->bindings->quiche_accept(
            $dcid,
            $dcidLength,
            $originalDcid,
            $originalDcid === null ? 0 : strlen($originalDcid),
            $localSocketAddr,
            QuicheBindings::sizeof($localSocketAddr[0]),
            $peerQuicheAddr = $peerAddr->getSocketAddressPtr(),
            QuicheBindings::sizeof($peerQuicheAddr[0]),
            $this->config->getBinding(),
        );

        if($connection === null){
            return null;
        }

        $connection = new QuicheConnection(
            $this->bindings,
            $this,
            $this->tempBuffer,
            $this->config,
            $connection,
            $this->acceptCallback,
            $localAddr,
            $peerAddr,
            $socketId
        );

        $this->connections[$dcidString] = $connection;
        ($this->acceptCallback)($connection, null); // new connection

        return $connection;
    }



    public function getConnection(string $dcid) : ?QuicheConnection{
        return $this->connections[$dcid] ?? null;
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        foreach($this->connections as $dcid => $connection){
            $connection->close($applicationError, $error, $reason);
            unset($this->connections[$dcid]);
        }

        parent::close($applicationError, $error, $reason);
    }

    public function getConfig() : Config{
        return $this->config;
    }

    public function setNonWritableSocket(int $socketId) : void{
        if($this->isRegisteredNonWritableSocket($socketId)){
            return;
        }

        $this->registerNonWritableSocket($this->getSocketById($socketId), function() use ($socketId) : void{
            $connections = array_filter($this->connections, fn(QuicheConnection $connection) => $connection->hasOutgoingQueue($socketId));

            $success = true;
            foreach($connections as $connection){
                if(!$connection->handleOutgoingQueue()){
                    $success = false;
                    break;
                }
            }

            if($success){
                $this->removeNonWritableSocket($socketId);

                foreach($connections as $connection){
                    $connection->handleOutgoing();
                }
            }
        });
    }
}