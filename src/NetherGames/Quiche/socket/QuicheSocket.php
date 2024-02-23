<?php

namespace NetherGames\Quiche\socket;

use Closure;
use FFI\CData;
use InvalidArgumentException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\string_;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\Config;
use RuntimeException;
use Socket;
use function socket_select;
use function spl_object_id;

abstract class QuicheSocket{
    protected const LOCAL_CONN_ID_LEN = 16;
    public const SEND_BUFFER_SIZE = 65535;

    /** @var uint8_t_ptr shared temp buffer, used for reading/writing */
    protected uint8_t_ptr $tempBuffer;
    protected QuicheFFI $bindings;
    protected Config $config;

    /** @var array<int, Socket> */
    private array $sockets = [];
    /** @var array<int, Socket> */
    private array $nonWritableSockets = [];
    /** @var array<int, Closure> */
    private array $nonWritableCallbacks = [];
    /** @var array<int, Closure> */
    private array $socketCallbacks = [];

    private bool $closed = false;

    /**
     * @param Closure $acceptCallback function(QuicheConnection $connection, QuicheStream $stream) : void
     */
    public function __construct(protected Closure $acceptCallback, bool $enableDebugLogging = false){
        $this->bindings = QuicheBindings::ffi();
        $this->config = new Config($this->bindings);
        $this->tempBuffer = uint8_t_ptr::array(self::SEND_BUFFER_SIZE);

        if($enableDebugLogging){
            $this->bindings->getFFI()->quiche_enable_debug_logging(function(CData $a){
                print ((new string_($a))->toString()) . "\n";
            }, null);
        }
    }

    protected function setupSocketSettings(Socket $socket) : void{
        if(php_uname('s') !== 'Darwin'){
            if(!socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 8 * 1024 * 1024) || !socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 8 * 1024 * 1024)){
                throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($socket)));
            }
        }
    }

    public function close(bool $applicationError, int $error, string $reason) : void{
        foreach($this->sockets as $socket){
            socket_close($socket);
        }

        $this->sockets = [];
        $this->socketCallbacks = [];

        $this->closed = true;
    }

    public function __destruct(){
        $this->config->free();
    }

    abstract protected function handleOutgoing() : void;

    /**
     * Called when the socket is no longer writable
     */
    abstract public function setNonWritableSocket(int $socketId) : void;

    public function selectSockets(int $timeout) : void{
        $read = $this->sockets;
        $write = $this->nonWritableSockets;
        $except = null;

        $select = socket_select($read, $write, $except, 0, $timeout);
        if($select !== false && $select > 0){
            foreach($read as $socketId => $socket){
                $this->socketCallbacks[$socketId]();
            }
            foreach($write as $socketId => $socket){
                $this->nonWritableCallbacks[$socketId]();
            }
        }

        $this->handleOutgoing();
    }

    public function isRegisteredNonWritableSocket(int $socketId) : bool{
        return isset($this->nonWritableSockets[$socketId]);
    }

    public function removeNonWritableSocket(int $socketId) : void{
        unset($this->nonWritableSockets[$socketId], $this->nonWritableCallbacks[$socketId]);
    }

    /**
     * @param Closure $callback function() : void
     *
     * * @return bool whether the socket was registered
     */
    public function registerNonWritableSocket(Socket $socket, Closure $callback) : bool{
        if(isset($this->nonWritableSockets[$socketId = spl_object_id($socket)])){
            return false;
        }

        $this->nonWritableSockets[$socketId] = $socket;
        $this->nonWritableCallbacks[$socketId] = $callback;

        return true;
    }

    public function getSocketById(int $socketId) : Socket{
        return $this->sockets[$socketId] ?? throw new InvalidArgumentException("Invalid socket ID");
    }

    /**
     * @param Closure $callback function() : void
     *
     * @return bool whether the socket was registered
     */
    public function registerSocket(Socket $socket, Closure $callback) : bool{
        if(isset($this->nonWritableSockets[$socketId = spl_object_id($socket)])){
            return false;
        }

        $this->sockets[$socketId] = $socket;
        $this->socketCallbacks[$socketId] = $callback;

        return true;
    }

    public function tick() : void{
        if($this->closed){
            return;
        }

        $this->selectSockets(0);
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    public function getConfig() : Config{
        return $this->config;
    }
}