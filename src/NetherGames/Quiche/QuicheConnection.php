<?php

namespace NetherGames\Quiche;

use Closure;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\quiche_send_info_ptr;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_conn_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\event\ClosedPathEvent;
use NetherGames\Quiche\event\Event;
use NetherGames\Quiche\event\FailedValidationPathEvent;
use NetherGames\Quiche\event\NewPathEvent;
use NetherGames\Quiche\event\PeerMigratedPathEvent;
use NetherGames\Quiche\event\ReusedSourceConnectionIdPathEvent;
use NetherGames\Quiche\event\ValidatedPathEvent;
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
use Socket;
use function microtime;
use function random_bytes;
use function socket_sendto;
use function strlen;
use function substr;

class QuicheConnection{
    public const QLOG_FILE_EXTENSION = 'qlog';

    /** @var ?string buffer for datagrams that didn't get processed */
    private ?string $sendBuffer = null;

    private quiche_send_info_ptr $sendInfo;
    private uint8_t_ptr $scidRetirePtr;

    /** @var ?Closure $dgramReadClosure function(string $data, int $length) : int */
    private ?Closure $dgramWriteClosure = null;

    /** @var array<int, QuicheStream> */
    private array $streams = [];

    /** @var Closure $incomingDgramBuffer function(string $data) : int */
    private Closure $incomingDgramBuffer;
    private QueueReader $outgoingDgramBuffer;

    private int $nextUnidirectionalStreamId;
    private int $nextBidirectionalStreamId;

    /** @var array<int, Closure> */
    private array $eventHandlers = [];

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
        private SocketAddress $localAddress,
        private SocketAddress $peerAddress,
        private int $socketId,
    ){
        $isClient = $this->isClient();

        // 0x0  | Client-Initiated, Bidirectional
        // 0x1  | Server-Initiated, Bidirectional
        // 0x2  | Client-Initiated, Unidirectional
        // 0x3  | Server-Initiated, Unidirectional

        $this->nextUnidirectionalStreamId = $isClient ? -2 : -1;
        $this->nextBidirectionalStreamId = $isClient ? -4 : -3;
        $this->sendInfo = quiche_send_info_ptr::array();
        $this->scidRetirePtr = uint8_t_ptr::array(QuicheBindings::QUICHE_MAX_CONN_ID_LEN);
    }

    public function isServer() : bool{
        return $this->socket instanceof QuicheServerSocket;
    }

    public function isClient() : bool{
        return $this->socket instanceof QuicheClientSocket;
    }

    public function getTraceId() : string{
        $traceId = uint8_t_ptr::array($length = QuicheBindings::QUICHE_MAX_CONN_ID_LEN * 2);
        $this->bindings->quiche_conn_trace_id(
            $this->connection,
            [&$traceId],
            [&$length],
        );

        return $traceId->toString($length);
    }

    public function setQLogPath(string $logDir, string $logTitle, string $logDesc, string $prefix = '') : string{
        $filePath = $logDir . '/' . (strlen($prefix) > 0 ? $prefix . '-' : '') . $this->getTraceId() . '.' . self::QLOG_FILE_EXTENSION;
        if(!$this->bindings->quiche_conn_set_qlog_path($this->connection, $filePath, $logTitle, $logDesc)){
            throw new RuntimeException('Failed to set qlog path');
        }

        return $filePath;
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

    /**
     * @param Closure $handler function(Event $event) : void
     */
    public function registerEventHandler(Closure $handler) : void{
        $this->eventHandlers[] = $handler;
    }

    public function getPeerAddress() : SocketAddress{
        return $this->peerAddress;
    }

    public function getLocalAddress() : SocketAddress{
        return $this->localAddress;
    }

    /**
     * @param Closure $incomingBuffer function(string $data) : int
     */
    public function enableDatagrams(Closure $incomingBuffer, int $recvQueueLen, int $sendQueueLen) : QueueWriter{
        $this->config->enableDgram(true, $recvQueueLen, $sendQueueLen);

        [$read, $write] = Buffer::create(fn() => $this->sendDatagrams());
        $this->outgoingDgramBuffer = $read;
        $this->incomingDgramBuffer = $incomingBuffer;

        return $write;
    }

    public function handleIncoming(
        string $buffer,
        int $length,
        SocketAddress $local,
        SocketAddress $peer,
    ) : bool{
        $info = SocketAddress::createRevcInfo($peer, $local);

        $done = $this->bindings->quiche_conn_recv($this->connection, $buffer, $length, $info);
        if($done < 0){
            return true; // failed to process packet
        }

        $this->localAddress = $local;
        $this->peerAddress = $peer;

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

        if($this->config->hasActiveMigration()){
            $this->handlePathEvents();
            $this->handleSCIDs();
        }

        return !$this->closed;
    }

    private function handleSCIDs() : void{
        while(($this->bindings->quiche_conn_retired_scid_next($this->connection, [&$this->scidRetirePtr], [&$length])) > 0){
            $this->socket->removeSCID($this->scidRetirePtr->toString($length));
        }

        while(($this->bindings->quiche_conn_scids_left($this->connection)) > 0){
            $scid = random_bytes($scidLength = QuicheBindings::QUICHE_MAX_CONN_ID_LEN);

            $this->socket->addSCID($scid, $this);

            $this->bindings->quiche_conn_new_scid(
                $this->connection,
                $scid,
                $scidLength,
                random_bytes(16),
                false,
                [&$seq]
            );
        }
    }

    public function probePath(SocketAddress $from = null, SocketAddress $to = null) : bool{
        $from = ($from ?? $this->localAddress);
        $to = ($to ?? $this->peerAddress);

        $this->bindings->quiche_conn_probe_path(
            $this->connection,
            $local = $from->getSocketAddressPtr(),
            QuicheBindings::sizeof($local[0]),
            $peer = $to->getSocketAddressPtr(),
            QuicheBindings::sizeof($peer[0]),
            [&$seq]
        );

        return $seq === 0;
    }

    private function callEvent(Event $event) : void{
        foreach($this->eventHandlers as $handler){
            $handler($event);
        }
    }

    private function handlePathEvents() : void{
        while(($event = $this->bindings->quiche_conn_path_event_next($this->connection)) > 0){
            $type = $this->bindings->quiche_path_event_type($event);

            switch($type){
                case QuicheBindings::QUICHE_PATH_EVENT_NEW:
                    $this->bindings->quiche_path_event_new($event, [&$local], [&$localLength], [&$peer], [&$peerLength]);

                    $this->callEvent(new NewPathEvent(
                        SocketAddress::createFromFFI($local),
                        SocketAddress::createFromFFI($peer)
                    ));
                    break;
                case QuicheBindings::QUICHE_PATH_EVENT_VALIDATED:
                    $this->bindings->quiche_path_event_validated($event, [&$from], [&$fromLength], [&$to], [&$toLength]);

                    $this->callEvent($ev = new ValidatedPathEvent(
                        SocketAddress::createFromFFI($from),
                        SocketAddress::createFromFFI($to)
                    ));

                    if($ev->shouldMigrate()){
                        $this->bindings->quiche_conn_migrate(
                            $this->connection,
                            $local = $ev->getLocalAddress()->getSocketAddressPtr(),
                            QuicheBindings::sizeof($local[0]),
                            $peer = $ev->getPeerAddress()->getSocketAddressPtr(),
                            QuicheBindings::sizeof($peer[0]),
                            [&$seq]
                        );
                    }
                    break;
                case QuicheBindings::QUICHE_PATH_EVENT_FAILED_VALIDATION:
                    $this->bindings->quiche_path_event_failed_validation($event, [&$local], [&$localLength], [&$peer], [&$peerLength]);

                    $this->callEvent(new FailedValidationPathEvent(
                        SocketAddress::createFromFFI($local),
                        SocketAddress::createFromFFI($peer)
                    ));
                    break;
                case QuicheBindings::QUICHE_PATH_EVENT_CLOSED:
                    $this->bindings->quiche_path_event_closed($event, [&$local], [&$localLength], [&$peer], [&$peerLength]);

                    $this->callEvent(new ClosedPathEvent(
                        SocketAddress::createFromFFI($local),
                        SocketAddress::createFromFFI($peer)
                    ));
                    break;
                case QuicheBindings::QUICHE_PATH_EVENT_REUSED_SOURCE_CONNECTION_ID:
                    $this->bindings->quiche_path_event_reused_source_connection_id($event, [&$id], [&$oldLocal], [&$oldLocalLength], [&$oldPeer], [&$oldPeerLength], [&$local], [&$localLength], [&$peer], [&$peerLength]);

                    $this->callEvent(new ReusedSourceConnectionIdPathEvent(
                        $id,
                        SocketAddress::createFromFFI($oldLocal),
                        SocketAddress::createFromFFI($oldPeer),
                        SocketAddress::createFromFFI($local),
                        SocketAddress::createFromFFI($peer)
                    ));
                    break;
                case QuicheBindings::QUICHE_PATH_EVENT_PEER_MIGRATED:
                    $this->bindings->quiche_path_event_peer_migrated($event, [&$local], [&$localLength], [&$peer], [&$peerLength]);

                    $this->callEvent(new PeerMigratedPathEvent(
                        SocketAddress::createFromFFI($local),
                        SocketAddress::createFromFFI($peer)
                    ));
                    break;
            }

            $this->bindings->quiche_path_event_free($event);
        }
    }

    /**
     * Called when the connection is closed by the peer.
     */
    private function onClosedByPeer() : void{
        $this->closed = true;

        foreach($this->streams as $stream){
            $stream->onConnectionClose(true);
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

    private function getPHPSocket() : Socket{
        return $this->socket->getSocketById($this->socketId);
    }

    private function sendToSocket(string $data, int $length) : int|false{
        return socket_sendto($this->getPHPSocket(), $data, $length, 0, $this->peerAddress->getAddress(), $this->peerAddress->getPort());
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

    private function ping() : void{
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
                    ($this->streams[$streamId] ?? null)?->handleOutgoing();
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
                    min($this->config->getMaxSendUdpPayloadSize(), $this->bindings->quiche_conn_send_quantum($this->connection)),
                    $this->sendInfo,
                ))){

                $this->localAddress = SocketAddress::createFromFFI($this->sendInfo->from);
                $this->peerAddress = SocketAddress::createFromFFI($this->sendInfo->to);

                if($this->socket instanceof QuicheServerSocket){
                    $this->socketId = $this->socket->getSocketIdBySocketAddress($this->localAddress);
                }

                $writtenLength = $this->sendToSocket($data = $this->tempBuffer->toString($written), $written);

                if($writtenLength === false){
                    $this->sendBuffer = $data;
                }elseif($writtenLength !== $written){
                    $this->sendBuffer = substr($data, $writtenLength);
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

    public function getSession() : string{
        $buffer = uint8_t_ptr::array($length = 65535);
        $this->bindings->quiche_conn_session($this->connection, [&$buffer], [&$length]);

        return $buffer->toString($length);
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        $this->closed = true;

        $this->handleOutgoing(); // send the data asap
        $this->bindings->quiche_conn_close($this->connection, (int) $applicationError, $error, $reason, strlen($reason));
        $this->send(); // send the close packet

        foreach($this->streams as $stream){
            $stream->onConnectionClose(false);
        }
    }

    public function openBidirectionalStream() : BiDirectionalQuicheStream{
        return $this->openBidirectionalStreamById($this->nextBidirectionalStreamId += 4);
    }

    private function openBidirectionalStreamById(int $streamId) : BiDirectionalQuicheStream{
        $this->streams[$streamId] = $stream = new BiDirectionalQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
            $this->tempBuffer
        );

        $stream->addShutdownCallback(function(bool $peerClosed) use ($streamId) : void{
            unset($this->streams[$streamId]);
        });

        return $stream;
    }

    public function openUnidirectionalStream() : QuicheStream{
        $this->streams[$streamId = $this->nextUnidirectionalStreamId += 4] = $stream = new WriteableQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
        );

        $stream->addShutdownWritingCallback(function(bool $peerClosed) use ($streamId) : void{
            unset($this->streams[$streamId]);
        });

        return $stream;
    }

    private function openUnidirectionalStreamById(int $streamId) : QuicheStream{
        $this->streams[$streamId] = $stream = new ReadableQuicheStream(
            $this->bindings,
            $streamId,
            $this->connection,
            $this->tempBuffer
        );

        $stream->addShutdownReadingCallback(function(bool $peerClosed) use ($streamId) : void{
            unset($this->streams[$streamId]);
        });

        return $stream;
    }

    private function receiveStreams() : void{
        while(($streamId = $this->bindings->quiche_conn_stream_readable_next($this->connection)) >= 0){
            if(($stream = $this->streams[$streamId] ?? null) === null){
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