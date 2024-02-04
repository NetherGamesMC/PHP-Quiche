<?php

namespace NetherGames\Quiche;

use Closure;
use LogicException;
use NetherGames\Quiche\bindings\quiche_recv_info_ptr;
use NetherGames\Quiche\bindings\quiche_send_info_ptr;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\io\Buffer;
use NetherGames\Quiche\io\BufferUtils;
use NetherGames\Quiche\io\QueueReader;
use NetherGames\Quiche\socket\QuicheClientSocket;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\socket\QuicheSocket;
use NetherGames\Quiche\stats\QuicheStats;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use NetherGames\Quiche\stream\ReadableQuicheStream;
use NetherGames\Quiche\stream\WriteableQuicheStream;
use RuntimeException;
use function array_filter;
use function is_int;
use function microtime;
use function strlen;

class QuicheConnection{
    public const QLOG_FILE_EXTENSION = 'qlog';

    /** @var ?string buffer for datagrams that didn't get processed */
    private ?string $sendBuffer = null;
    private quiche_send_info_ptr $sendInfo;

    /** @var ?Closure $dgramReadClosure function(string $data, int $length) : int */
    private ?Closure $dgramWriteClosure = null;

    /** @var array<int, QuicheStream> */
    private array $streams = [];

    /** @var ?Closure $incomingDgramBuffer function(string $data) : int */
    private ?Closure $incomingDgramBuffer = null;
    private ?QueueReader $outgoingDgramBuffer = null;

    private int $nextUnidirectionalStreamId;
    private int $nextBidirectionalStreamId;

    private ?float $timeoutTime = null;
    private ?float $pingTime = null;

    private bool $closed = false;
    /** @var ?Closure $onPeerClose function(bool $applicationError, int $error, ?string $reason) : void */
    private ?Closure $onPeerClose = null;

    /**
     * @param Closure $acceptCallback function(QuicheConnection $connection, QuicheStream $stream) : void
     */
    public function __construct(
        private readonly QuicheFFI $bindings,
        private readonly QuicheSocket $socket,
        private readonly uint8_t_ptr $tempBuffer,
        private readonly Config $config,
        private readonly struct_quiche_conn_ptr $connection,
        private readonly Closure $acceptCallback,
        private SocketAddress $peerAddress,
        private int $socketId = -1,
    ){
        $isClient = $socket instanceof QuicheClientSocket;

        // 0x0  | Client-Initiated, Bidirectional
        // 0x1  | Server-Initiated, Bidirectional
        // 0x2  | Client-Initiated, Unidirectional
        // 0x3  | Server-Initiated, Unidirectional

        $this->nextUnidirectionalStreamId = $isClient ? -2 : -1;
        $this->nextBidirectionalStreamId = $isClient ? -4 : -3;
        $this->sendInfo = quiche_send_info_ptr::array();
    }

    public function setQLogPath(string $logDir, string $logTitle, string $logDesc, string $prefix = '') : void{
        $this->bindings->quiche_conn_trace_id(
            $this->connection,
            [&$traceId],
            [&$traceIdLength],
        );

        $filePath = $logDir . '/' . (strlen($prefix) > 0 ? $prefix . '-' : '') . $traceId->toString($traceIdLength) . '.' . self::QLOG_FILE_EXTENSION;
        if(!$this->bindings->quiche_conn_set_qlog_path($this->connection, $filePath, $logTitle, $logDesc)){
            throw new RuntimeException('Failed to set qlog path');
        }
    }

    public function setKeylogFilePath(string $keylogFilePath) : void{
        if($this->bindings->quiche_conn_set_keylog_path($this->connection, $keylogFilePath)){
            $this->config->enableLogKeys();
        }else{
            throw new RuntimeException('Failed to set keylog path');
        }
    }

    /**
     * Callable to be called when the connection is closed by the peer.
     *
     * @param Closure $onPeerClose function(bool $applicationError, int $error, ?string $reason) : void
     */
    public function setPeerCloseCallback(Closure $onPeerClose) : void{
        $this->onPeerClose = $onPeerClose;
    }

    public function getPeerAddress() : SocketAddress{
        return $this->peerAddress;
    }

    /**
     * @param Closure $incomingBuffer function(string $data) : int
     */
    public function setDatagramBuffers(Buffer $outgoingBuffer, Closure $incomingBuffer) : void{
        if(!$this->config->hasDgramEnabled()){
            throw new LogicException("Datagrams are not enabled");
        }

        $this->outgoingDgramBuffer = new QueueReader($outgoingBuffer);
        $this->incomingDgramBuffer = $incomingBuffer;
    }

    public function handleIncoming(
        string $buffer,
        quiche_recv_info_ptr $recvInfo,
    ) : bool{
        $done = $this->bindings->quiche_conn_recv($this->connection, $buffer, strlen($buffer), $recvInfo);
        if($done < 0){
            return true; // failed to process packet
        }

        $this->scheduleTimeout();
        $this->schedulePing();

        if($this->bindings->quiche_conn_is_closed($this->connection)){
            $this->onClosedByPeer();

            return false;
        }

        if($this->bindings->quiche_conn_is_established($this->connection)){
            $this->receiveStreams();
            $this->receiveDatagrams();
        }

        return true;
    }

    /**
     * Called when the connection is closed by the peer.
     */
    private function onClosedByPeer() : void{
        $this->closed = true;

        foreach($this->streams as $streamId => $stream){
            $stream->onConnectionClose(true);
            unset($this->streams[$streamId]);
        }

        $this->bindings->quiche_conn_peer_error(
            $this->connection,
            [&$isApp],
            [&$code],
            [&$reason],
            [&$reasonLength],
        );

        if($this->onPeerClose !== null){
            ($this->onPeerClose)($isApp, $code, $reason?->toString($reasonLength));
        }
    }

    public function getStreamCount() : int{
        return count(array_filter($this->streams, fn(QuicheStream $stream) : bool => !$stream->isClosed()));
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    private function getTargetAddress() : string{
        return $this->socket instanceof QuicheServerSocket ? $this->peerAddress->getSocketAddress() : "";
    }

    /**
     * @return bool true if all datagrams were sent
     */
    private function handleOutgoingQueue() : bool{
        if($this->sendBuffer !== null){
            $written = stream_socket_sendto($this->getSocket(), $this->sendBuffer, 0, $this->getTargetAddress());

            if($written === false){
                return false;
            }elseif($written === strlen($this->sendBuffer)){
                $this->sendBuffer = null;
            }else{
                $this->sendBuffer = substr($this->sendBuffer, $written);
            }
        }

        return true;
    }

    /**
     * @return resource
     */
    private function getSocket(){
        if($this->socket instanceof QuicheServerSocket){
            $socket = $this->socket->getSocketById($this->socketId);
        }else if($this->socket instanceof QuicheClientSocket){
            $socket = $this->socket->getSocket();
        }else{
            throw new LogicException("Unknown socket type");
        }

        if($socket === null){
            throw new LogicException("Socket is null");
        }

        return $socket;
    }

    public function ping() : void{
        $this->bindings->quiche_conn_send_ack_eliciting($this->connection);
    }

    /**
     * @return bool whether the connection is still alive
     */
    public function handleOutgoing() : bool{
        if($this->bindings->quiche_conn_is_closed($this->connection)){
            $this->onClosedByPeer();

            return false;
        }

        $this->checkTimers();

        if($this->handleOutgoingQueue()){
            if($this->bindings->quiche_conn_is_established($this->connection)){
                foreach($this->streams as $streamId => $stream){
                    if($stream->isClosed()){
                        unset($this->streams[$streamId]);
                    }else{
                        $stream->handleOutgoing();
                    }
                }

                $this->sendDatagrams();
            }

            while(0 < ($written = $this->bindings->quiche_conn_send(
                    $this->connection,
                    $this->tempBuffer,
                    $this->config->getMaxSendUdpPayloadSize(),
                    $this->sendInfo,
                ))){
                if($this->socket instanceof QuicheServerSocket){
                    $targetAddress = SocketAddress::createFromFFI($this->sendInfo->to);

                    if($targetAddress !== $this->peerAddress){
                        $this->peerAddress = $targetAddress;
                    }

                    $sourceAddress = SocketAddress::createFromFFI($this->sendInfo->from);
                    $socketId = $this->socket->getSocketIdBySocketAddress($sourceAddress);

                    if($socketId !== $this->socketId){
                        $this->socketId = $socketId;
                    }
                }

                $writtenLength = stream_socket_sendto($this->getSocket(), $this->tempBuffer->toString($written), 0, $this->getTargetAddress());

                if($writtenLength === false){
                    $this->sendBuffer = $this->tempBuffer->toString($written);
                    break;
                }elseif($writtenLength !== $written){
                    $this->sendBuffer = substr($this->tempBuffer->toString($written), $writtenLength);
                    break;
                }
            }
        }

        return true;
    }

    public function schedulePing() : void{
        if(($pingInterval = $this->config->getPingInterval()) !== 0){
            $this->pingTime = microtime(true) + ($pingInterval / 1e3);
        }
    }

    public function scheduleTimeout() : void{
        if(($maxIdleTimeout = $this->config->getMaxIdleTimeout()) !== 0){
            $this->timeoutTime = microtime(true) + ($maxIdleTimeout / 1e3);
        }
    }

    public function checkTimers() : void{
        if($this->timeoutTime !== null && microtime(true) > $this->timeoutTime){
            $this->timeoutTime = null;
            $this->bindings->quiche_conn_on_timeout($this->connection);
            $this->handleOutgoing(); // send the timeout packet
            $this->scheduleTimeout();
        }

        if($this->pingTime !== null && microtime(true) > $this->pingTime){
            $this->pingTime = null;
            $this->ping();
            $this->handleOutgoing(); // send the ping packet
            $this->schedulePing();
        }
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->closed = true;

        $this->handleOutgoing(); // send the data asap
        $this->bindings->quiche_conn_close($this->connection, (int) $applicationError, $error, $reason, strlen($reason));
        $this->handleOutgoing(); // send the close packet

        foreach($this->streams as $streamId => $stream){
            $stream->onConnectionClose(false);
            unset($this->streams[$streamId]);
        }
    }

    public function openBidirectionalStream() : BiDirectionalQuicheStream{
        return $this->openBidirectionalStreamById($this->nextBidirectionalStreamId += 4);
    }

    private function openBidirectionalStreamById(int $streamId) : BiDirectionalQuicheStream{
        return $this->streams[$streamId] = new BiDirectionalQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
            $this->tempBuffer
        );
    }

    public function openUnidirectionalStream() : QuicheStream{
        return $this->streams[$streamId = $this->nextUnidirectionalStreamId += 4] = new WriteableQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
        );
    }

    private function openUnidirectionalStreamById(int $streamId) : QuicheStream{
        return $this->streams[$streamId] = new ReadableQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
            $this->tempBuffer
        );
    }

    private function receiveStreams() : void{
        while(($streamId = $this->bindings->quiche_conn_stream_readable_next($this->connection)) >= 0){
            $stream = ($this->streams[$streamId] ?? null);

            if($stream === null){
                if($streamId % 4 === 0 || $streamId % 4 === 1){ // Client-Initiated or Server-Initiated Bidirectional
                    $stream = $this->openBidirectionalStreamById($streamId);
                }else{
                    $stream = $this->openUnidirectionalStreamById($streamId);
                }

                ($this->acceptCallback)($this, $stream);
            }

            $stream->handleIncoming();
        }
    }

    private function receiveDatagrams() : void{
        if(!$this->config->hasDgramEnabled()){
            return;
        }

        if($this->incomingDgramBuffer === null){
            throw new LogicException("Datagram buffer is not set");
        }

        if(($written = $this->bindings->quiche_conn_dgram_recv(
                $this->connection,
                $this->tempBuffer,
                QuicheSocket::SEND_BUFFER_SIZE,
            )) > 0){
            ($this->incomingDgramBuffer)($this->tempBuffer->toString($written));
        }
    }

    private function sendDatagrams() : bool{
        if(!$this->config->hasDgramEnabled()){
            return false;
        }

        if($this->outgoingDgramBuffer === null){
            throw new LogicException("Datagram buffer is not set");
        }

        $success = BufferUtils::tryWrite(
            $this->outgoingDgramBuffer,
            $this->dgramWriteClosure ??= fn(string $data, int $length) : int => $this->bindings->quiche_conn_dgram_send($this->connection, $data, $length)
        );

        if(is_int($success)){
            throw new RuntimeException("Failed to send datagram: " . $success);
        }

        return $success;
    }

    public function getStats() : QuicheStats{
        return QuicheStats::getConnectionStats($this->bindings, $this->connection);
    }
}