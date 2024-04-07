<?php

namespace NetherGames\Quiche\io;

use Closure;
use function strlen;

class BufferUtils{
    /**
     * @param Closure $writer function(string $data, int $length, bool $isLast) : int
     */
    public static function tryWrite(QueueReader $reader, Closure $writer) : int{
        $empty = $reader->isEmpty();
        while(!$empty){
            [$data, $promise] = $reader->shift();
            $dataLength = strlen($data);
            $written = $writer($data, $dataLength, $empty = $reader->isEmpty());

            if($written <= 0){
                $reader->unshift($data, $promise);
            }elseif($written !== $dataLength){
                $reader->unshift(substr($data, $written), $promise);
            }else{
                $promise?->success();
                continue;
            }

            return $written;
        }

        return 0;
    }
}