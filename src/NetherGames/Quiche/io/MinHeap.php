<?php

namespace NetherGames\Quiche\io;

use SplMinHeap;

/**
 * @extends SplMinHeap<array{int, int}>
 */
class MinHeap extends SplMinHeap{
    public const KEY_TIMEOUT = 0;
    public const KEY_VALUE = 1;

    /**
     * @param array{int, int} $value1
     * @param array{int, int} $value2
     */
    protected function compare(mixed $value1, mixed $value2) : int{
        return $value2[self::KEY_TIMEOUT] <=> $value1[self::KEY_TIMEOUT];
    }
}