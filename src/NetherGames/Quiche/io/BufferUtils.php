<?php

namespace NetherGames\Quiche\io;

use Closure;
use function strlen;

class BufferUtils{
    /**
     * @param Closure $writer function(string $data, int $length) : int
     */
    public static function tryWrite(QueueReader $reader, Closure $writer) : int{
        while(($data = $reader->shift()) !== null){
            $dataLength = strlen($data);
            $written = $writer($data, $dataLength);

            if($written <= 0){
                $reader->unshift($data);

                return $written;
            }elseif($written !== $dataLength){
                $reader->unshift(substr($data, $written));
            }else{
                continue;
            }

            return $written;
        }

        return 0;
    }
}