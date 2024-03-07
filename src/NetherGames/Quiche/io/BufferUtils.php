<?php

namespace NetherGames\Quiche\io;

use Closure;
use function strlen;

class BufferUtils{
    /**
     * @param Closure $writer function(string $data, int $length, bool $isLast) : int
     */
    public static function tryWrite(QueueReader $reader, Closure $writer) : int{
        $newData = $reader->shift();
        while($newData !== null){
            $data = $newData;
            $dataLength = strlen($data);
            $newData = $reader->shift();
            $written = $writer($data, $dataLength, $newData === null);

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