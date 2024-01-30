# PHP-Quiche
A [Quiche](https://github.com/cloudflare/quiche)-based [QUIC](https://quicwg.org/) implementation for PHP

## Installation
```bash
composer require nethergamesmc/quiche
```

Requires FFI to be enabled & the quiche library to be installed.

## Usage

### Client
```php
<?php
$clientSocket = new QuicheClientSocket(
    new SocketAddress("127.0.0.1", 19132),
    function(QuicheConnection $connection, QuicheStream $stream) : void{
    // gets called when a new stream is opened
    }
);
$clientConfig = $clientSocket->getConfig();
$clientConfig->enableBidirectionalStreams();

while(true){
    $clientSocket->tick();
}
```

### Server
```php
<?php
$serverSocket = new QuicheServerSocket(
    [new SocketAddress("127.0.0.1", 19132)],
    function(QuicheConnection $connection, ?QuicheStream $stream) : void{
    // gets called when a new connection is established or a new stream is opened
    }
);
$serverConfig = $serverSocket->getConfig();
$serverConfig->loadPrivKeyFromFile($pathToKey);
$serverConfig->loadCertChainFromFile($pathToCert);
$serverConfig->enableBidirectionalStreams();

while(true){
    $serverSocket->tick();
}
```