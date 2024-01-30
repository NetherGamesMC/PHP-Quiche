<?php

namespace NetherGames\Quiche\io;

use Closure;
use function strlen;

class BufferUtils{
    /**
     * @param Closure $writer function(string $data, int $length) : int
     */
    public static function tryWrite(QueueReader $reader, Closure $writer) : true|int{
        while(($data = $reader->shift()) !== null){
            $dataLength = strlen($data);
            $written = $writer($data, $dataLength);

            if($written < 0){ // PHP sockets don't go below 0, so we can safely use this on udp socket writes
                return $written;
            }elseif($written !== $dataLength){
                $reader->readd(substr($data, $written));
            }
        }

        return true;
    }
}