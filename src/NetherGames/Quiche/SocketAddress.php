<?php

namespace NetherGames\Quiche;

require_once __DIR__ . "/bindings/generate.php";

use FFI;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\quiche_recv_info_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_in;
use NetherGames\Quiche\bindings\struct_sockaddr_in6;
use NetherGames\Quiche\bindings\struct_sockaddr_in6_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_in_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_storage;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use RuntimeException;
use function inet_ntop;
use function inet_pton;
use function pack;
use const STREAM_PF_INET;
use const STREAM_PF_INET6;

class SocketAddress{
    private struct_sockaddr_ptr $socketAddressPtr;
    private struct_sockaddr_in_ptr|struct_sockaddr_in6_ptr $socketAddressInPtr;
    private string $hostname;

    public function __construct(private string $address, private readonly int $port){
        // check if it's a domain name
        if(filter_var($this->address, FILTER_VALIDATE_IP) === false){
            $this->hostname = $this->address;
            $this->address = gethostbyname($this->address);
        }else{
            $this->hostname = $this->address;
        }

        if(!str_contains($this->address, ":")){
            $this->socketAddressInPtr = $this->getQuicheIPv4SocketAddress();
        }else{
            $this->socketAddressInPtr = $this->getQuicheIPv6SocketAddress();
        }

        $this->socketAddressPtr = struct_sockaddr_ptr::castFrom($this->socketAddressInPtr);
    }

    public function getHostname() : string{
        return $this->hostname;
    }

    public function getSocketFamily() : int{
        return $this->socketAddressPtr->sa_family;
    }

    public function getAddress() : string{
        return $this->address;
    }

    public function getPort() : int{
        return $this->port;
    }

    public function getSocketAddress() : string{
        return "{$this->address}:{$this->port}";
    }

    public function getSocketAddressPtr() : struct_sockaddr_ptr{
        return $this->socketAddressPtr;
    }

    private function getQuicheIPv4SocketAddress() : struct_sockaddr_in_ptr{
        $socketAddress = struct_sockaddr_in_ptr::array();
        $socketAddress->sin_family = STREAM_PF_INET;
        $socketAddress->sin_port = (($this->port & 0xFF) << 8) | ($this->port >> 8); // convert to big endian
        FFI::memcpy($socketAddress->sin_addr->getData(), inet_pton($this->address), 4);

        return $socketAddress;
    }

    private function getQuicheIPv6SocketAddress() : struct_sockaddr_in6_ptr{
        $socketAddress = struct_sockaddr_in6_ptr::array();
        $socketAddress->sin6_family = STREAM_PF_INET6;
        $socketAddress->sin6_port = (($this->port & 0xFF) << 8) | ($this->port >> 8); // convert to big endian
        FFI::memcpy($socketAddress->sin6_addr->getData(), inet_pton($this->address), 16);

        return $socketAddress;
    }

    public static function createRevcInfo(self $from, self $to) : quiche_recv_info_ptr{
        $recvInfo = quiche_recv_info_ptr::array();
        $recvInfo->from = $fromAddr = $from->getSocketAddressPtr();
        $recvInfo->from_len = QuicheBindings::sizeof($fromAddr[0]);
        $recvInfo->to = $toAddr = $to->getSocketAddressPtr();
        $recvInfo->to_len = QuicheBindings::sizeof($toAddr[0]);

        return $recvInfo;
    }

    public static function createFromFFI(struct_sockaddr_storage $socketAddress) : self{
        if($socketAddress->ss_family === STREAM_PF_INET){
            $sin6 = struct_sockaddr_in::castFrom($socketAddress);
            $port = $sin6->sin_port;
            $address = inet_ntop(pack("V", $sin6->sin_addr->s_addr));
        }else{
            $sin6 = struct_sockaddr_in6::castFrom($socketAddress);
            $port = $sin6->sin6_port;
            $address = inet_ntop(uint8_t_ptr::castFrom($sin6->sin6_addr->addr())->toString(16));
        }

        if($address === false){
            throw new RuntimeException("Failed to convert address");
        }

        return new self($address, (($port & 0xFF) << 8) | ($port >> 8)); // convert to little endian
    }

    public function equals(self $socketAddress) : bool{
        return $this->address === $socketAddress->getAddress() && $this->port === $socketAddress->getPort();
    }
}