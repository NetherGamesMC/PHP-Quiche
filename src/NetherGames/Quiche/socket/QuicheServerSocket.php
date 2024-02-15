<?php

namespace NetherGames\Quiche\socket;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\struct_sockaddr_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\Config;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\SocketAddress;
use RuntimeException;
use TypeError;
use function fclose;
use function random_bytes;
use function str_starts_with;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function strlen;
use function substr;

class QuicheServerSocket extends QuicheSocket{


    /** @var array<int, resource> */
    private array $udpSockets = [];
    /** @var array<int, SocketAddress> */
    private array $udpSocketAddresses = [];
    /** @var array<string, int> */
    private array $udpSocketIds = [];
    /** @var array<string, QuicheConnection> */
    private array $connections = [];

    private bool $closed = false;

    /**
     * @param SocketAddress[] $address
     * @param Closure         $acceptCallback function(QuicheConnection $connection, ?QuicheStream $stream) : void (stream is null for new connections)
     */
    public function __construct(array $address, Closure $acceptCallback, bool $enableDebugLogging = false){
        parent::__construct($acceptCallback, $enableDebugLogging);

        $this->registerUDPSockets($address);
    }

    public function tick() : void{
        if($this->closed){
            return;
        }

        $read = $this->udpSockets;
        $write = $except = null;

        $select = stream_select($read, $write, $except, 0, 0);
        if($select !== false && $select > 0){
            foreach($read as $socketId => $socket){
                try{
                    $this->readSocket($socketId, $socket);
                }catch(TypeError $e){
                    // happens when the server is closed due to arrived data using callbacks
                }
            }
        }

        foreach($this->connections as $dcid => $connection){
            if($connection->isClosed() || !$connection->handleOutgoing()){
                $this->removeConnection($dcid);
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function readSocket(int $socketId, $socket) : void{
        if(($buffer = stream_socket_recvfrom($socket, $this->config->getMaxRecvUdpPayloadSize(), 0, $peerAddr)) !== false){
            if(($bufferLength = strlen($buffer)) === 0){
                return;
            }

            $scid = uint8_t_ptr::array($scidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN);
            $dcid = uint8_t_ptr::array($dcidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN);
            $token = uint8_t_ptr::array($tokenLength = 128);

            $success = $this->bindings->quiche_header_info(
                $buffer,
                $bufferLength,
                self::LOCAL_CONN_ID_LEN,
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

            $peerAddress = SocketAddress::createFromAddress($peerAddr);
            $connection = $this->connections[$dcidString = $dcid->toString($dcidLength)] ?? null;
            if($connection === null){ // connection is new
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
                SocketAddress::createRevcInfo($peerAddress, $this->udpSocketAddresses[$socketId])
            )){
                $this->removeConnection($dcidString);
            }
        }
    }

    private function removeConnection(string $dcid) : void{
        unset($this->connections[$dcid]);
    }

    /**
     * @param resource $socket
     */
    private function createConnection(
        SocketAddress $peerAddr,
        int $socketId,
        $socket,
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

            stream_socket_sendto($socket, $this->tempBuffer->toString($written), 0, $peerAddr->getSocketAddress());

            return null;
        }

        /** @var string $dcidString */
        $dcidString = $dcid->toString($dcidLength);
        $tokenPrefix = "quiche" . $peerAddr->getSocketAddress();
        if($tokenLength === 0){ // stateless retry
            $mintToken = $tokenPrefix . $dcidString;

            $written = $this->bindings->quiche_retry(
                $scid,
                $scidLength,
                $dcid,
                $dcidLength,
                random_bytes(self::LOCAL_CONN_ID_LEN),
                self::LOCAL_CONN_ID_LEN,
                $mintToken,
                strlen($mintToken),
                $version,
                $this->tempBuffer,
                $this->config->getMaxSendUdpPayloadSize()
            );

            stream_socket_sendto($socket, $this->tempBuffer->toString($written), 0, $peerAddr->getSocketAddress());

            return null;
        }

        if(!str_starts_with($token->toString($tokenLength), $tokenPrefix)){
            return null; // invalid token
        }

        $localAddr = $this->udpSocketAddresses[$socketId]->getSocketAddressFFI();
        $originalDcid = substr($token->toString($tokenLength), strlen($tokenPrefix));
        $connection = $this->bindings->quiche_accept(
            $dcid,
            $dcidLength,
            $originalDcid,
            strlen($originalDcid),
            struct_sockaddr_ptr::castFrom($localAddr),
            QuicheBindings::sizeof($localAddr[0]),
            struct_sockaddr_ptr::castFrom($peerQuicheAddr = $peerAddr->getSocketAddressFFI()),
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
            $peerAddr,
            $socketId
        );

        $this->connections[$dcidString] = $connection;
        ($this->acceptCallback)($connection, null); // new connection

        return $connection;
    }

    /**
     * @param SocketAddress[] $address
     */
    private function registerUDPSockets(array $address) : void{
        foreach($address as $socketId => $socketAddress){
            $socket = stream_socket_server("udp://" . $socketAddress->getSocketAddress(), $errno, $errstr, STREAM_SERVER_BIND);

            if($socket === false){
                throw new RuntimeException("Failed to bind to {$socketAddress->getSocketAddress()}: $errstr ($errno)");
            }

            stream_set_blocking($socket, false);

            $this->udpSockets[$socketId] = $socket;
            $this->udpSocketAddresses[$socketId] = $socketAddress;
            $this->udpSocketIds[$socketAddress->getSocketAddress()] = $socketId;
        }
    }

    public function getSocketIdBySocketAddress(SocketAddress $socketAddress) : int{
        return $this->udpSocketIds[$socketAddress->getSocketAddress()];
    }

    /**
     * @return ?resource
     */
    public function getSocketById(int $socketId){
        return $this->udpSockets[$socketId] ?? null;
    }

    public function getConnection(string $dcid) : ?QuicheConnection{
        return $this->connections[$dcid] ?? null;
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        foreach($this->connections as $dcid => $connection){
            $connection->close($applicationError, $error, $reason);
            unset($this->connections[$dcid]);
        }

        foreach($this->udpSockets as $index => $socket){
            unset($this->udpSockets[$index]);
            fclose($socket);
        }

        $this->closed = true;
    }

    public function getConfig() : Config{
        return $this->config;
    }
}