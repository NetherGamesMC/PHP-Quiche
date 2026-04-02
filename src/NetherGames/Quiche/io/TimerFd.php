<?php

namespace NetherGames\Quiche\io;

use NetherGames\Quiche\bindings\timer\TimerFd as TimerFdBindings;
use NetherGames\Quiche\bindings\timer\int_ptr;
use NetherGames\Quiche\bindings\timer\struct_itimerspec_ptr;
use NetherGames\Quiche\bindings\timer\TimerFdFFI;
use RuntimeException;

class TimerFd
{
    /** @var TimerFdFFI */
    private TimerFdFFI $ffi;
    /** @var int */
    private int $fd;
    /** @var resource */
    private $stream;

    public function __construct()
    {
        $this->ffi = TimerFdBindings::ffi();
        $this->fd = $this->ffi->timerfd_create(TimerFdBindings::CLOCK_MONOTONIC, TimerFdBindings::TFD_NONBLOCK | TimerFdBindings::TFD_CLOEXEC);
        if ($this->fd === -1) {
            throw new RuntimeException("Failed to create timerfd");
        }

        $this->stream = fopen("php://fd/" . $this->fd, "rb");
    }

    public function setTimeout(int $timeoutMs): void
    {
        static $TFD_TIMER_ABSTIME = (1 << 0);

        $timer = struct_itimerspec_ptr::array();
        if ($timeoutMs === 0) {
            // Set it to absolute time in the past to trigger immediately
            $timer->it_value->tv_sec = 1;
            $timer->it_value->tv_nsec = 0;
            $flags = $TFD_TIMER_ABSTIME;
        } else {
            $timer->it_value->tv_sec = (int)($timeoutMs / 1000);
            $timer->it_value->tv_nsec = ($timeoutMs % 1000) * 1000000;
            $flags = 0;
        }

        $timer->it_interval->tv_sec = 0;
        $timer->it_interval->tv_nsec = 0;

        if ($this->ffi->timerfd_settime($this->fd, $flags, $timer, null) === -1) {
            throw new RuntimeException("Failed to set timerfd time");
        }
    }

    public function getTimerFdId(): int
    {
        return $this->fd;
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function read(): void
    {
        $buf = int_ptr::array();
        $this->ffi->read($this->fd, $buf, 8);
    }

    public function close(): void
    {
        if (isset($this->fd) && $this->fd !== -1) {
            $this->ffi->close($this->fd);
            $this->fd = -1;
        }
    }
}
