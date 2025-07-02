<?php

namespace tests;

use Closure;
use NetherGames\Quiche\Config;
use NetherGames\Quiche\event\Event;
use NetherGames\Quiche\event\ValidatedPathEvent;
use NetherGames\Quiche\io\QueueWriter;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\socket\QuicheClientSocket;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\SocketAddress;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function file_get_contents;
use function is_file;
use function unlink;

require_once __DIR__ . "/../vendor/autoload.php";

class PingPongTest extends TestCase{
    /**
     * @param Closure $closure function(QuicheConnection $connection, ?QuicheStream $stream) : void
     */
    private static function createServer(Closure $closure) : QuicheServerSocket{
        return new QuicheServerSocket(
            [
                new SocketAddress("127.0.0.1", 19133),
                new SocketAddress("127.0.0.1", 19134),
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
        $socketConfig->discoverPMTUD(true);
        $socketConfig->setEnableActiveMigration(false);
        $socketConfig->setEnableActiveMigration(true);
        $socketConfig->enableStatelessRetry(true);
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

                $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedServer) : void{
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
        $qlogFilePath = $clientConnection->setQLogPath(__DIR__, "client", "qlog", "client");
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
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

        if(is_file(__DIR__ . "/client-keylog.txt")){
            unlink(__DIR__ . "/client-keylog.txt");
        }else{
            self::fail("Keylog file should exist");
        }

        if(is_file($qlogFilePath)){
            unlink($qlogFilePath);
        }else{
            self::fail("QLog file should exist");
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

                $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedServer) : void{
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

        $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
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
        $sendingSucceeded = false;

        $server = null;
        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$server, &$sendingSucceeded, &$streamClosedServer, &$streamWriterServerDisabled) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$sendingSucceeded, &$streamClosedServer) : void{
                    self::assertTrue(!$peerClosed, "Peer closed should be false on server");
                    $streamClosedServer = self::checkWriter($writer);
                });

                $stream->setOnDataArrival(function(string $data) use ($writer, &$server, &$sendingSucceeded, &$streamWriterServerDisabled) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");
                    $writer->writeWithPromise("pong")->onResult(function() use (&$sendingSucceeded) : void{
                        $sendingSucceeded = true;
                    });
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

        $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$streamClosedClient) : void{
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

        while(!$done || !$arrival || !$streamClosedClient || !$streamClosedServer || !$streamWriterServerDisabled || !$sendingSucceeded){
            $client->tick();
            $server->tick();
        }

        $server->close(false, 0, "Bye");
    }

    public function testClientMigration() : void{
        $done = false;
        $arrival = false;
        $streamClosedClient = false;
        $streamClosedServer = false;
        $streamWriterServerDisabled = false;
        $sendingSucceeded = false;
        $pongsCounted = 0;
        $serverConnection = null;

        $server = null;
        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$server, &$serverConnection, &$sendingSucceeded, &$pongsCounted, &$streamClosedServer, &$streamWriterServerDisabled) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->addShutdownCallback(function(bool $peerClosed) use ($writer, &$sendingSucceeded, &$pongsCounted, &$streamClosedServer) : void{
                    self::assertTrue(!$peerClosed, "Peer closed should be false on server");
                    $streamClosedServer = self::checkWriter($writer);
                });

                $stream->setOnDataArrival(function(string $data) use ($writer, &$server, &$sendingSucceeded, &$pongsCounted, &$streamWriterServerDisabled) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");

                    $pongsCounted++;
                    $writer->writeWithPromise("pong")->onResult(function() use (&$sendingSucceeded) : void{
                        $sendingSucceeded = true;
                    });

                    if($pongsCounted === 30){
                        $server->close(false, 0, "Bye");
                        $streamWriterServerDisabled = self::checkWriter($writer);
                    }
                });
            }else if($stream === null){
                $serverConnection = $connection;
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
        $clientConnection->registerEventHandler(function(Event $event) : void{
            if($event instanceof ValidatedPathEvent){
                $event->migrate();
            }
        });
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->addShutdownCallback(function(bool $peerClosed) use ($clientConnection, $writer, &$streamClosedClient) : void{
            self::assertTrue($peerClosed, "Peer closed should be false on server");
            $streamClosedClient = self::checkWriter($writer);
        });

        $stream->setOnDataArrival(function(string $data) use ($clientConnection, $writer, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            if(!$arrival){
                $clientConnection->probePath(to: new SocketAddress("127.0.0.1", 19134));
            }
            $writer->write("ping");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use (&$done, $writer) : void{
            self::assertTrue($applicationError === false, "Application error should be false");
            self::assertTrue($error === 0, "Error should be 0");
            self::assertTrue($reason === "Bye", "Reason should be Bye");
            $done = self::checkWriter($writer);
        });

        while(!$done || !$arrival || !$streamClosedClient || !$streamClosedServer || !$streamWriterServerDisabled || !$sendingSucceeded){
            $client->tick();
            $server->tick();
        }

        self::assertTrue($serverConnection->getLocalAddress()->getPort() === 19134, "Port should be 19134");
        self::assertTrue($clientConnection->getPeerAddress()->getPort() === 19134, "Port should be 19134");

        $server->close(false, 0, "Bye");
    }

    public function testSessionResumption() : void{
        $arrival = false;
        $arrivalResumption = false;
        $clientClosed = false;
        $sendingSucceeded = false;
        $resumedSession = false;
        $newClient = null;

        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$server, &$resumedSession, &$sendingSucceeded) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->setOnDataArrival(function(string $data) use ($writer, $connection, &$server, &$resumedSession, &$sendingSucceeded) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");

                    if($resumedSession){
                        self::assertTrue($connection->isResumed(), "Connection should be resumed");
                        $writer->writeWithPromise("pong")->onResult(function() use ($connection, &$sendingSucceeded) : void{
                            $connection->close(false, 0, "Bye");
                            $sendingSucceeded = true;
                        });
                    }else{
                        self::assertFalse($connection->isResumed(), "Connection should not be resumed yet");
                        $writer->writeWithPromise("pong")->onResult(function() use ($connection, &$resumedSession) : void{
                            $resumedSession = true;
                            $connection->close(false, 0, "Bye");
                        });
                    }
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

        $stream->setOnDataArrival(function(string $data) use ($clientConnection, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            self::assertFalse($clientConnection->isResumed(), "Connection should not be resumed yet");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use ($client, &$arrivalResumption, &$newClient, &$clientClosed, $clientConnection) : void{
            $newClient = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
                self::fail("Client should not receive a stream");
            });

            self::configureClient($newClient);

            $newClient->connect($clientConnection->getSession());

            $newClientConnection = $newClient->getConnection();
            $newClientConnection->setQLogPath(__DIR__, "client", "qlog", "client");
            $newClientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use (&$clientClosed) : void{
                self::assertTrue($applicationError === false, "Application error should be false");
                self::assertTrue($error === 0, "Error should be 0");
                self::assertTrue($reason === "Bye", "Reason should be Bye");
                $clientClosed = true;
            });

            $stream = $newClientConnection->openBidirectionalStream();
            $writer = $stream->setupWriter();
            $writer->write("ping");

            $stream->setOnDataArrival(function(string $data) use ($newClientConnection, $writer, &$arrivalResumption) : void{
                self::assertTrue($data === "pong", "Pong should arrive as pong");
                self::assertTrue($newClientConnection->isResumed(), "Connection should be resumed now");
                $writer->write("ping");
                $arrivalResumption = true;
            });
        });

        while(!$arrival || !$sendingSucceeded || !$arrivalResumption || !$clientClosed){
            $client->tick();
            $server->tick();
            $newClient?->tick();
        }

        $server->close(false, 0, "Bye");
    }

    public function test0RTTResumption() : void{
        $arrival = false;
        $arrivalResumption = false;
        $clientClosed = false;
        $sendingSucceeded = false;
        $resumedSession = false;
        $newClient = null;

        $server = self::createServer(function(QuicheConnection $connection, ?QuicheStream $stream) use (&$server, &$resumedSession, &$sendingSucceeded) : void{
            if($stream instanceof BiDirectionalQuicheStream){
                $writer = $stream->setupWriter();

                $stream->setOnDataArrival(function(string $data) use ($writer, $connection, &$server, &$resumedSession, &$sendingSucceeded) : void{
                    self::assertTrue($data === "ping", "Ping should arrive as ping");

                    if($resumedSession){
                        self::assertTrue($connection->isResumed(), "Connection should be resumed");
                        $writer->writeWithPromise("pong")->onResult(function() use ($connection, &$sendingSucceeded) : void{
                            $connection->close(false, 0, "Bye");
                            $sendingSucceeded = true;
                        });
                    }else{
                        self::assertFalse($connection->isResumed(), "Connection should not be resumed yet");
                        $writer->writeWithPromise("pong")->onResult(function() use ($connection, &$resumedSession) : void{
                            $resumedSession = true;
                            $connection->close(false, 0, "Bye");
                        });
                    }
                });
            }
        });

        $client = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
            self::fail("Client should not receive a stream");
        });

        self::configureClient($client);
        self::configureServer($server);

        $server->getConfig()->enableEarlyData();

        $client->connect();
        $clientConnection = $client->getConnection();
        $stream = $clientConnection->openBidirectionalStream();
        $writer = $stream->setupWriter();
        $writer->write("ping");

        $stream->setOnDataArrival(function(string $data) use ($clientConnection, &$arrival) : void{
            self::assertTrue($data === "pong", "Pong should arrive as pong");
            self::assertFalse($clientConnection->isResumed(), "Connection should not be resumed yet");
            $arrival = true;
        });

        $clientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use ($client, &$arrivalResumption, &$newClient, &$clientClosed, $clientConnection) : void{
            $newClient = self::createClient(function(QuicheConnection $connection, QuicheStream $stream) : void{
                self::fail("Client should not receive a stream");
            });

            self::configureClient($newClient);

            $newClient->getConfig()->enableEarlyData();

            $newClient->connect($clientConnection->getSession());

            $newClientConnection = $newClient->getConnection();
            $path = $newClientConnection->setQLogPath(__DIR__, "client", "qlog", "client");
            $newClientConnection->setPeerCloseCallback(function(bool $applicationError, int $error, ?string $reason) use (&$clientClosed, $path) : void{
                self::assertTrue($applicationError === false, "Application error should be false");
                self::assertTrue($error === 0, "Error should be 0");
                self::assertTrue($reason === "Bye", "Reason should be Bye");
                $clientClosed = true;

                if(is_file($path)){
                    $contents = file_get_contents($path);

                    self::assertStringContainsString('"packet_type":"0RTT"', $contents, "QLog should contain 0RTT packets");
                    self::assertStringNotContainsString('{"packet_type":"1RTT","packet_number":3}', $contents, "QLog should not contain 1RTT frame with packet number 3");

                    unlink($path);
                }
            });

            $stream = $newClientConnection->openBidirectionalStream();
            $writer = $stream->setupWriter();
            $writer->write("ping");

            $stream->setOnDataArrival(function(string $data) use ($newClientConnection, $writer, &$arrivalResumption) : void{
                self::assertTrue($data === "pong", "Pong should arrive as pong");
                self::assertTrue($newClientConnection->isResumed(), "Connection should be resumed now");
                $arrivalResumption = true;
            });
        });

        while(!$arrival || !$sendingSucceeded || !$arrivalResumption || !$clientClosed){
            $client->tick();
            $server->tick();
            $newClient?->tick();
        }

        $server->close(false, 0, "Bye");
    }
}