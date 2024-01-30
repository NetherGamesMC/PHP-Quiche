<?php

namespace NetherGames\Quiche;

use FFI;
use InvalidArgumentException;
use NetherGames\Quiche\bindings\Quiche as QuicheBindings;
use NetherGames\Quiche\bindings\quiche_recv_info_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_in;
use NetherGames\Quiche\bindings\struct_sockaddr_in6;
use NetherGames\Quiche\bindings\struct_sockaddr_in6_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_in_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_ptr;
use NetherGames\Quiche\bindings\struct_sockaddr_storage;
use NetherGames\Quiche\bindings\uint8_t_ptr;
use function inet_ntop;
use function inet_pton;
use function pack;
use const STREAM_PF_INET;
use const STREAM_PF_INET6;

class SocketAddress{
    private struct_sockaddr_in_ptr|struct_sockaddr_in6_ptr $socketAddress;

    public function __construct(private readonly string $address, private readonly int $port){
        $this->socketAddress = self::getQuicheSocketAddress($this->getSocketAddress());
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

    public function getSocketAddressFFI() : struct_sockaddr_in6_ptr|struct_sockaddr_in_ptr{
        return $this->socketAddress;
    }

    private static function getQuicheIPv4SocketAddress(string $socketAddress, int $lastColon) : struct_sockaddr_in_ptr{
        $port = (int) substr($socketAddress, $lastColon + 1);
        $address = substr($socketAddress, 0, $lastColon);

        $socketAddress = struct_sockaddr_in_ptr::array();
        $socketAddress->sin_family = STREAM_PF_INET;
        $socketAddress->sin_port = (($port & 0xFF) << 8) | ($port >> 8); // convert to big endian
        FFI::memcpy($socketAddress->sin_addr->getData(), inet_pton($address), 4);

        return $socketAddress;
    }

    private static function getQuicheIPv6SocketAddress(string $socketAddress, int $lastColon) : struct_sockaddr_in6_ptr{
        $port = (int) substr($socketAddress, $lastColon + 1);
        $address = substr($socketAddress, 1, $lastColon - 2); // remove brackets

        $socketAddress = struct_sockaddr_in6_ptr::array();
        $socketAddress->sin6_family = STREAM_PF_INET6;
        $socketAddress->sin6_port = (($port & 0xFF) << 8) | ($port >> 8); // convert to big endian
        FFI::memcpy($socketAddress->sin6_addr->getData(), inet_pton($address), 16);

        return $socketAddress;
    }

    private static function getQuicheSocketAddress(string $socketAddress) : struct_sockaddr_in6_ptr|struct_sockaddr_in_ptr{
        $lastColon = strrpos($socketAddress, ":");
        $firstColon = strpos($socketAddress, ":");
        $ipVersion = $firstColon === $lastColon ? STREAM_PF_INET : STREAM_PF_INET6;

        if($lastColon === false){
            throw new InvalidArgumentException("Invalid socket address {$socketAddress}");
        }

        if($ipVersion === STREAM_PF_INET){
            return self::getQuicheIPv4SocketAddress($socketAddress, $lastColon);
        }else{
            return self::getQuicheIPv6SocketAddress($socketAddress, $lastColon);
        }
    }

    public static function createRevcInfo(self $from, self $to) : quiche_recv_info_ptr{
        $recvInfo = quiche_recv_info_ptr::array();
        $recvInfo->from = struct_sockaddr_ptr::castFrom($fromAddr = $from->getSocketAddressFFI());
        $recvInfo->from_len = QuicheBindings::sizeof($fromAddr[0]);
        $recvInfo->to = struct_sockaddr_ptr::castFrom($toAddr = $to->getSocketAddressFFI());
        $recvInfo->to_len = QuicheBindings::sizeof($toAddr[0]);

        return $recvInfo;
    }

    public static function createFromAddress(string $socketAddress) : self{
        $lastColon = strrpos($socketAddress, ":");
        $firstColon = strpos($socketAddress, ":");
        $ipVersion = $firstColon === $lastColon ? STREAM_PF_INET : STREAM_PF_INET6;

        if($lastColon === false){
            throw new InvalidArgumentException("Invalid socket address {$socketAddress}");
        }

        if($ipVersion === STREAM_PF_INET){
            $port = (int) substr($socketAddress, $lastColon + 1);
            $address = substr($socketAddress, 0, $lastColon);
        }else{
            $port = (int) substr($socketAddress, $lastColon + 1);
            $address = substr($socketAddress, 1, $lastColon - 2); // remove brackets
        }

        return new self($address, $port);
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

        return new self($address, (($port & 0xFF) << 8) | ($port >> 8)); // convert to little endian
    }


    public function equals(string $localAddress, int $localPort) : bool{
        return $this->address === $localAddress && $this->port === $localPort;
    }
}