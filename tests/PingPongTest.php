<?php

namespace tests;

use Closure;
use NetherGames\Quiche\Config;
use NetherGames\Quiche\io\QueueWriter;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\socket\QuicheClientSocket;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\SocketAddress;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . "/../vendor/autoload.php";

class PingPongTest extends TestCase{
    /**
     * @param Closure $closure function(QuicheConnection $connection, ?QuicheStream $stream) : void
     */
    private static function createServer(Closure $closure) : QuicheServerSocket{
        return new QuicheServerSocket(
            [
                new SocketAddress("127.0.0.1", 19133),
            ],
            $closure
        );
    }

    /**
     * @param Closure $closure function(QuicheConnection $connection, QuicheStream $stream) : void
     */
    private static function createClient(Closure $closure) : QuicheClientSocket{
        return new QuicheClientSocket(
            new SocketAddress("127.0.0.1", 19133),
            $closure
        );
    }

    private static function configureBaseSocket(Config $socketConfig) : void{
        $socketConfig->enableBidirectionalStreams();
        $socketConfig->setApplicationProtos(["pingpong"]);
        $socketConfig->setInitialMaxData(10000000);
        $socketConfig->setMaxIdleTimeout(2000);
        $socketConfig->setPingInterval(1000);
    }

    private static function configureClient(QuicheClientSocket $client) : void{
        self::configureBaseSocket($clientConfig = $client->getConfig());
        $clientConfig->setVerifyPeer(false);
    }

    private static function configureServer(QuicheServerSocket $server) : void{
        self::configureBaseSocket($serverConfig = $server->getConfig());
        $serverConfig->loadPrivKeyFromFile(__DIR__ . "/certificates/key.pem");
        $serverConfig->loadCertChainFromFile(__DIR__ . "/certificates/cert.pem");
    }

    private static function checkWriter(QueueWriter $writer) : bool{
        try{
            $writer->write("pong");
        }catch(RuntimeException $e){
            return true;
        }

        self::fail("Writer should throw an exception");
    }

    public function testPingPongCloseConnection() : void{
        $done = false;
        $arrival = false;
        $streamClosedClient = false;
        $streamClosedServer = false;
        $streamWriterServerDisabled = false;

        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$streamClosedServer, &$streamWriterServerDisabled) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedServer) : void{
                    self::assertTrue(!$peerClosed, "Peer closed should be false on server");
                    $streamClosedServer = self::checkWriter($writer);
                });

                $stream->setOnDataArrival(function(string $data) use ($writer, $connection, &$streamWriterServerDisabled) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");
                    $writer->write("pong");
                    $connection->close(false, 0, "Bye");
                    $streamWriterServerDisabled = self::checkWriter($writer);
                });
            }else if($stream === null){
                $connection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) : void{
                    self::fail("Server should not receive a shutdown callback");
                });
            }
        });

        $client = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
            self::fail("Client should not receive a stream");
        });

        self::configureClient($client);
        self::configureServer($server);

        $client->connect();
        $clientConnection = $client->getConnection();
        $clientConnection->setKeylogFilePath(__DIR__ . "/client-keylog.txt");
        $clientConnection->setQLogPath(__DIR__, "client", "qlog", "client");
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
            self::assertTrue($peerClosed, "Peer closed should be false on server");
            $streamClosedClient = self::checkWriter($writer);
        });

        $stream->setOnDataArrival(function(string $data) use ($writer, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use (&$done, $writer) : void{
            self::assertTrue($applicationError === false, "Application error should be false");
            self::assertTrue($error === 0, "Error should be 0");
            self::assertTrue($reason === "Bye", "Reason should be Bye");
            $done = self::checkWriter($writer);
        });

        while(!$done || !$arrival || !$streamClosedClient || !$streamClosedServer || !$streamWriterServerDisabled){
            $client->tick();
            $server->tick();
        }

        $server->close(false, 0, "Bye");
    }

    public function testPingPongCloseStream() : void{
        $arrival = false;
        $streamClosedClient = false;
        $streamClosedServer = false;
        $streamWriterServerDisabled = false;

        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$streamClosedServer, &$streamWriterServerDisabled) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedServer) : void{
                    self::assertTrue(!$peerClosed, "Peer closed should be false on server");
                    $streamClosedServer = self::checkWriter($writer);
                });

                $stream->setOnDataArrival(function(string $data) use ($writer, $stream, &$streamWriterServerDisabled) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");
                    $writer->write("pong");
                    $stream->shutdownReading();
                    $stream->gracefulShutdownWriting();
                    $streamWriterServerDisabled = self::checkWriter($writer);
                });
            }else if($stream === null){
                $connection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) : void{
                    self::fail("Server should not receive a shutdown callback");
                });
            }
        });

        $client = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
            self::fail("Client should not receive a stream");
        });

        self::configureClient($client);
        self::configureServer($server);

        $client->connect();
        $clientConnection = $client->getConnection();
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
            self::assertTrue($peerClosed, "Peer closed should be false on server");
            $streamClosedClient = self::checkWriter($writer);
        });

        $stream->setOnDataArrival(function(string $data) use ($writer, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use ($writer) : void{
            self::fail("Client should not receive a shutdown callback");
        });

        while(!$arrival || !$streamClosedClient || !$streamClosedServer || !$streamWriterServerDisabled){
            $client->tick();
            $server->tick();
        }

        $server->close(false, 0, "Bye");
        $client->close(false, 0, "Bye");
    }

    public function testPingPongCloseServer() : void{
        $done = false;
        $arrival = false;
        $streamClosedClient = false;
        $streamClosedServer = false;
        $streamWriterServerDisabled = false;

        $server = null;
        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$server, &$streamClosedServer, &$streamWriterServerDisabled) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedServer) : void{
                    self::assertTrue(!$peerClosed, "Peer closed should be false on server");
                    $streamClosedServer = self::checkWriter($writer);
                });

                $stream->setOnDataArrival(function(string $data) use ($writer, &$server, &$streamWriterServerDisabled) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");
                    $writer->write("pong");
                    $server->close(false, 0, "Bye");
                    $streamWriterServerDisabled = self::checkWriter($writer);
                });
            }else if($stream === null){
                $connection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) : void{
                    self::fail("Server should not receive a shutdown callback");
                });
            }
        });

        $client = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
            self::fail("Client should not receive a stream");
        });

        self::configureClient($client);
        self::configureServer($server);

        $client->connect();
        $clientConnection = $client->getConnection();
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->setShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
            self::assertTrue($peerClosed, "Peer closed should be false on server");
            $streamClosedClient = self::checkWriter($writer);
        });

        $stream->setOnDataArrival(function(string $data) use ($writer, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use (&$done, $writer) : void{
            self::assertTrue($applicationError === false, "Application error should be false");
            self::assertTrue($error === 0, "Error should be 0");
            self::assertTrue($reason === "Bye", "Reason should be Bye");
            $done = self::checkWriter($writer);
        });

        while(!$done || !$arrival || !$streamClosedClient || !$streamClosedServer || !$streamWriterServerDisabled){
            $client->tick();
            $server->tick();
        }

        $server->close(false, 0, "Bye");
    }
}