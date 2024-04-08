<?php

namespace NetherGames\Quiche;

use Closure;
use NetherGames\Quiche\bindings\quiche_recv_info_ptr;
use NetherGames\Quiche\bindings\quiche_send_info_ptr;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\io\Buffer;
use NetherGames\Quiche\io\BufferUtils;
use NetherGames\Quiche\io\QueueReader;
use NetherGames\Quiche\io\QueueWriter;
use NetherGames\Quiche\socket\QuicheClientSocket;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\socket\QuicheSocket;
use NetherGames\Quiche\stats\QuicheStats;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use NetherGames\Quiche\stream\ReadableQuicheStream;
use NetherGames\Quiche\stream\WriteableQuicheStream;
use RuntimeException;
use function microtime;
use function socket_send;
use function socket_sendto;
use function strlen;
use function substr;

class QuicheConnection{
    public const QLOG_FILE_EXTENSION = 'qlog';

    /** @var ?string buffer for datagrams that didn't get processed */
    private ?string $sendBuffer = null;
    private quiche_send_info_ptr $sendInfo;

    /** @var ?Closure $dgramReadClosure function(string $data, int $length) : int */
    private ?Closure $dgramWriteClosure = null;

    /** @var array<int, QuicheStream> */
    private array $streams = [];

    /** @var Closure $incomingDgramBuffer function(string $data) : int */
    private Closure $incomingDgramBuffer;
    private QueueReader $outgoingDgramBuffer;

    private int $nextUnidirectionalStreamId;
    private int $nextBidirectionalStreamId;

    private ?float $timeoutTime = null;
    private ?float $pingTime = null;

    private bool $closed = false;
    private bool $isEstablished = false;

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
        private int $socketId,
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
    public function enableDatagrams(Closure $incomingBuffer, int $recvQueueLen, int $sendQueueLen) : QueueWriter{
        $this->config->enableDgram(true, $recvQueueLen, $sendQueueLen);

        [$read, $write] = Buffer::create();
        $this->outgoingDgramBuffer = $read;
        $this->incomingDgramBuffer = $incomingBuffer;

        return $write;
    }

    public function handleIncoming(
        string $buffer,
        int $length,
        quiche_recv_info_ptr $recvInfo,
    ) : bool{
        $done = $this->bindings->quiche_conn_recv($this->connection, $buffer, $length, $recvInfo);
        if($done < 0){
            return true; // failed to process packet
        }

        $this->scheduleTimeout();
        $this->schedulePing();

        if($this->bindings->quiche_conn_is_closed($this->connection)){
            $this->onClosedByPeer();

            return false;
        }

        if($this->bindings->quiche_conn_is_established($this->connection) || $this->bindings->quiche_conn_is_in_early_data($this->connection)){
            $this->receiveStreams();
            $this->receiveDatagrams();
        }

        return !$this->closed;
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

    public function isClosed() : bool{
        return $this->closed;
    }

    private function sendToSocket(string $data, int $length) : int|false{
        $socket = $this->socket->getSocketById($this->socketId);

        if($this->socket instanceof QuicheServerSocket){
            return socket_sendto($socket, $data, $length, 0, $this->peerAddress->getAddress(), $this->peerAddress->getPort());
        }else{
            return socket_send($socket, $data, $length, 0);
        }
    }

    public function hasOutgoingQueue(int $socketId = null) : bool{
        return ($socketId === null || $socketId === $this->socketId) && $this->sendBuffer !== null;
    }

    /**
     * @return bool true if all datagrams were sent
     */
    public function handleOutgoingQueue() : bool{
        if($this->sendBuffer !== null){
            $written = $this->sendToSocket($this->sendBuffer, strlen($this->sendBuffer));

            if($written === false){
                return false;
            }elseif($written === strlen($this->sendBuffer)){
                $this->sendBuffer = null;
            }else{
                $this->sendBuffer = substr($this->sendBuffer, $written);

                return false;
            }
        }

        return true;
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

        if($this->bindings->quiche_conn_is_established($this->connection) || $this->bindings->quiche_conn_is_in_early_data($this->connection)){
            if($this->isEstablished){
                while(($streamId = $this->bindings->quiche_conn_stream_writable_next($this->connection)) >= 0){
                    $stream = $this->streams[$streamId] ?? null;

                    if($stream !== null && !$stream->handleOutgoing() && $stream->isClosed()){
                        unset($this->streams[$streamId]);
                    }
                }
            }else{
                foreach($this->streams as $stream){
                    $stream->handleOutgoing();
                }

                $this->isEstablished = true;
            }

            $this->sendDatagrams();
        }

        $this->send();

        return true;
    }

    private function send() : void{
        if($this->sendBuffer === null){
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

                $writtenLength = $this->sendToSocket($this->tempBuffer->toString($written), $written);

                if($writtenLength === false){
                    $this->sendBuffer = $this->tempBuffer->toString($written);
                }elseif($writtenLength !== $written){
                    $this->sendBuffer = substr($this->tempBuffer->toString($written), $writtenLength);
                }else{
                    continue;
                }

                $this->socket->setNonWritableSocket($this->socketId);
                break;
            }
        }
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
            $this->send(); // send the timeout packet
            $this->scheduleTimeout();
        }

        if($this->pingTime !== null && microtime(true) > $this->pingTime){
            $this->pingTime = null;
            $this->ping();
            $this->send(); // send the ping packet
            $this->schedulePing();
        }
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->closed = true;

        $this->handleOutgoing(); // send the data asap
        $this->bindings->quiche_conn_close($this->connection, (int) $applicationError, $error, $reason, strlen($reason));
        $this->send(); // send the close packet

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
            $stream = $this->streams[$streamId] ?? null;

            if($stream === null){
                if($streamId % 4 === 0 || $streamId % 4 === 1){ // Client-Initiated or Server-Initiated Bidirectional
                    $stream = $this->openBidirectionalStreamById($streamId);
                }else{
                    $stream = $this->openUnidirectionalStreamById($streamId);
                }

                ($this->acceptCallback)($this, $stream);
            }

            if(!$stream->handleIncoming() && $stream->isClosed()){
                unset($this->streams[$streamId]);
            }
        }
    }

    private function receiveDatagrams() : void{
        if(!isset($this->incomingDgramBuffer)){
            return;
        }

        if(($written = $this->bindings->quiche_conn_dgram_recv(
                $this->connection,
                $this->tempBuffer,
                QuicheSocket::SEND_BUFFER_SIZE,
            )) > 0){
            ($this->incomingDgramBuffer)($this->tempBuffer->toString($written));
        }
    }

    private function sendDatagrams() : void{
        if(!isset($this->outgoingDgramBuffer)){
            return;
        }

        BufferUtils::tryWrite(
            $this->outgoingDgramBuffer,
            $this->dgramWriteClosure ??= fn(string $data, int $length, bool $isLast) : int => $this->bindings->quiche_conn_dgram_send($this->connection, $data, $length)
        );
    }

    public function getStats() : QuicheStats{
        return QuicheStats::getConnectionStats($this->bindings, $this->connection);
    }
}