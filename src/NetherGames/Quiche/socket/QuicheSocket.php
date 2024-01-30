<?php

namespace NetherGames\Quiche\socket;

use Closure;
use FFI\CData;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\string_;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use NetherGames\Quiche\Config;

abstract class QuicheSocket{
    protected const LOCAL_CONN_ID_LEN = 16;
    public const SEND_BUFFER_SIZE = 65535;

    /** @var uint8_t_ptr shared temp buffer, used for reading/writing */
    protected uint8_t_ptr $tempBuffer;
    protected QuicheFFI $bindings;
    protected Config $config;

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

    abstract public function close(bool $applicationError, int $error, string $reason) : void;

    public function __destruct(){
        $this->config->free();
    }

    abstract public function tick() : void;

    public function getConfig() : Config{
        return $this->config;
    }
}