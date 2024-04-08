<?php

namespace NetherGames\Quiche;

use FFI;
use InvalidArgumentException;
use NetherGames\Quiche\bindings\Quiche as QuicheBinding;
use NetherGames\Quiche\bindings\QuicheFFI;
use NetherGames\Quiche\bindings\struct_quiche_config_ptr;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use function is_dir;

class Config{
    private struct_quiche_config_ptr $config;
    private int $maxRecvUdpPayloadSize = 65527; // https://docs.quic.tech/src/quiche/lib.rs.html#1006
    private int $maxSendUdpPayloadSize = 1200; // https://docs.quic.tech/src/quiche/lib.rs.html#1012
    private int $pingInterval = 0;
    private int $maxIdleTimeout = 0;

    public function __construct(private readonly QuicheFFI $bindings){
        $config = $this->bindings->quiche_config_new(QuicheBinding::QUICHE_PROTOCOL_VERSION);
        if($config === null){
            throw new InvalidArgumentException("Failed to create config");
        }

        $this->config = $config;
    }

    public function loadCertChainFromFile(string $path) : self{
        $this->bindings->quiche_config_load_cert_chain_from_pem_file($this->config, $path);

        return $this;
    }

    public function loadPrivKeyFromFile(string $path) : self{
        $this->bindings->quiche_config_load_priv_key_from_pem_file($this->config, $path);

        return $this;
    }

    public function loadVerifyLocations(string $path) : self{
        if(is_dir($path)){
            $this->bindings->quiche_config_load_verify_locations_from_directory($this->config, $path);
        }else{
            $this->bindings->quiche_config_load_verify_locations_from_file($this->config, $path);
        }

        return $this;
    }

    public function setVerifyPeer(bool $verify) : self{
        $this->bindings->quiche_config_verify_peer($this->config, (int) $verify);

        return $this;
    }

    public function setGrease(bool $grease) : self{
        $this->bindings->quiche_config_grease($this->config, (int) $grease);

        return $this;
    }

    public function enableLogKeys() : self{
        $this->bindings->quiche_config_log_keys($this->config);

        return $this;
    }

    public function setTicketKey(string $key) : self{
        $this->bindings->quiche_config_set_ticket_key($this->config, $key, strlen($key));

        return $this;
    }

    /**
     * @param string[] $protocols
     */
    public function setApplicationProtos(array $protocols) : self{
        $protoLen = array_reduce($protocols, fn($val, $proto) => $val + strlen($proto) + 1, 0);
        $protoStr = uint8_t_ptr::array($protoLen);
        $protoIdx = 0;

        foreach($protocols as $protocol){
            $protoStr[$protoIdx++] = strlen($protocol);
            FFI::memcpy($protoStr->getData() + $protoIdx, $protocol, strlen($protocol));
            $protoIdx += strlen($protocol);
        }

        $this->bindings->quiche_config_set_application_protos($this->config, $protoStr, $protoLen);

        return $this;
    }

    /**
     * @param int $interval 0 = disabled (default)
     */
    public function setPingInterval(int $interval) : self{
        if($interval < 0){
            throw new InvalidArgumentException("PingInterval must be positive");
        }

        $this->pingInterval = $interval;

        return $this;
    }

    /**
     * @return int 0 = disabled
     */
    public function getPingInterval() : int{
        return $this->pingInterval;
    }

    public function setMaxIdleTimeout(int $timeout) : self{
        if($timeout < 0){
            throw new InvalidArgumentException("MaxIdleTimeout must be positive");
        }

        $this->bindings->quiche_config_set_max_idle_timeout($this->config, $timeout);
        $this->maxIdleTimeout = $timeout;

        return $this;
    }

    public function getMaxIdleTimeout() : int{
        return $this->maxIdleTimeout;
    }

    public function setMaxRecvUdpPayloadSize(int $size) : self{
        if($size < 1200){
            throw new InvalidArgumentException("MaxRecvUdpPayloadSize must be at least 1200");
        }

        $this->bindings->quiche_config_set_max_recv_udp_payload_size($this->config, $size);
        $this->maxRecvUdpPayloadSize = $size;

        return $this;
    }

    public function getMaxRecvUdpPayloadSize() : int{
        return $this->maxRecvUdpPayloadSize;
    }

    public function enableBidirectionalStreams(int $maxStreams = 100, int $maxStreamDataLocal = 1000000, int $maxStreamDataRemote = 1000000) : self{
        $this->setInitialMaxStreamsBidi($maxStreams);
        $this->setInitialMaxStreamDataBidiLocal($maxStreamDataLocal);
        $this->setInitialMaxStreamDataBidiRemote($maxStreamDataRemote);

        return $this;
    }

    public function enableUnidirectionalStreams(int $maxStreams = 100, int $maxStreamData = 1000000) : self{
        $this->setInitialMaxStreamsUni($maxStreams);
        $this->setInitialMaxStreamDataUni($maxStreamData);

        return $this;
    }

    public function setMaxSendUdpPayloadSize(int $size) : self{
        if($size < 1200){
            throw new InvalidArgumentException("MaxSendUdpPayloadSize must be at least 1200");
        }

        $this->bindings->quiche_config_set_max_send_udp_payload_size($this->config, $size);
        $this->maxSendUdpPayloadSize = $size;

        return $this;
    }

    public function getMaxSendUdpPayloadSize() : int{
        return $this->maxSendUdpPayloadSize;
    }

    public function setInitialMaxData(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxData must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_data($this->config, $v);

        return $this;
    }

    private function setInitialMaxStreamDataBidiLocal(int $v) : void{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxStreamDataBidiLocal must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_stream_data_bidi_local($this->config, $v);
    }

    private function setInitialMaxStreamDataBidiRemote(int $v) : void{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxStreamDataBidiRemote must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_stream_data_bidi_remote($this->config, $v);
    }

    private function setInitialMaxStreamDataUni(int $v) : void{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxStreamDataUni must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_stream_data_uni($this->config, $v);
    }

    private function setInitialMaxStreamsBidi(int $v) : void{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxStreamsBidi must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_streams_bidi($this->config, $v);
    }

    private function setInitialMaxStreamsUni(int $v) : void{
        if($v < 0){
            throw new InvalidArgumentException("InitialMaxStreamsUni must be positive");
        }

        $this->bindings->quiche_config_set_initial_max_streams_uni($this->config, $v);
    }

    public function setAckDelayExponent(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("AckDelayExponent must be positive");
        }

        $this->bindings->quiche_config_set_ack_delay_exponent($this->config, $v);

        return $this;
    }

    public function setMaxAckDelay(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("MaxAckDelay must be positive");
        }

        $this->bindings->quiche_config_set_max_ack_delay($this->config, $v);

        return $this;
    }

    public function setActiveConnectionIdLimit(int $v) : self{
        if($v < 2){
            throw new InvalidArgumentException("ActiveConnectionIdLimit must be at least 2");
        }

        $this->bindings->quiche_config_set_active_connection_id_limit($this->config, $v);

        return $this;
    }

    public function setEnableActiveMigration(bool $v) : self{
        $this->bindings->quiche_config_set_disable_active_migration($this->config, (int) !$v);

        return $this;
    }

    public function setCCAlgorithm(CongestionControlAlgorithm $v) : self{
        $this->bindings->quiche_config_set_cc_algorithm($this->config, $v->value);

        return $this;
    }

    public function setInitialCongestionWindowPackets(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("InitialCongestionWindow must be positive");
        }

        $this->bindings->quiche_config_set_initial_congestion_window_packets($this->config, $v);

        return $this;
    }

    public function setEnableHystart(bool $v) : self{
        $this->bindings->quiche_config_enable_hystart($this->config, (int) $v);

        return $this;
    }

    public function setEnablePacing(bool $v) : self{
        $this->bindings->quiche_config_enable_pacing($this->config, (int) $v);

        return $this;
    }

    public function setMaxPacingRate(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("MaxPacingRate must be positive");
        }

        $this->bindings->quiche_config_set_max_pacing_rate($this->config, $v);

        return $this;
    }

    /**
     * @internal This method is used by QuicheConnection directly
     * @see QuicheConnection::enableDatagrams
     */
    public function enableDgram(bool $enabled, int $recvQueueLen, int $sendQueueLen) : self{
        if($recvQueueLen < 0){
            throw new InvalidArgumentException("RecvQueueLen must be positive");
        }

        if($sendQueueLen < 0){
            throw new InvalidArgumentException("SendQueueLen must be positive");
        }

        $this->bindings->quiche_config_enable_dgram($this->config, (int) $enabled, $recvQueueLen, $sendQueueLen);

        return $this;
    }

    public function setMaxConnectionWindow(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("MaxConnectionWindow must be positive");
        }

        $this->bindings->quiche_config_set_max_connection_window($this->config, $v);

        return $this;
    }

    public function setMaxStreamWindow(int $v) : self{
        if($v < 0){
            throw new InvalidArgumentException("MaxStreamWindow must be positive");
        }

        $this->bindings->quiche_config_set_max_stream_window($this->config, $v);

        return $this;
    }

    public function setStatelessResetToken(string $token) : self{
        $this->bindings->quiche_config_set_stateless_reset_token($this->config, $token);

        return $this;
    }

    public function setDisableDCIDReuse(bool $v) : self{
        $this->bindings->quiche_config_set_disable_dcid_reuse($this->config, (int) $v);

        return $this;
    }

    public function getBinding() : struct_quiche_config_ptr{
        return $this->config;
    }

    public function free() : void{
        $this->bindings->quiche_config_free($this->config);
    }

    public function hasKeepAliveEnabled() : bool{
        return $this->pingInterval > 0;
    }
}