<?php

namespace NetherGames\Quiche\io;

use NetherGames\Quiche\bindings\timer\TimerFd as TimerFdBindings;
use NetherGames\Quiche\bindings\timer\int_ptr;
use NetherGames\Quiche\bindings\timer\struct_itimerspec_ptr;
use NetherGames\Quiche\bindings\timer\TimerFdFFI;
use NetherGames\Quiche\socket\QuicheSocket;
use RuntimeException;

class TimerFd
{
    public const TFD_TIMER_ABSTIME = (1 << 0);

    /** @var TimerFdFFI */
    private TimerFdFFI $ffi;
    /** @var int */
    private int $fd = -1;
    /** @var resource */
    private $stream;
    /** @var int_ptr */
    private int_ptr $buffer;
    /** @var struct_itimerspec_ptr */
    private struct_itimerspec_ptr $timer;

    public function __construct(QuicheSocket $instance)
    {
        // TimerFd is not supported on other platforms than Linux
        if (php_uname("s") !== 'Linux') return;

        $this->ffi = TimerFdBindings::ffi();
        $this->fd = $this->ffi->timerfd_create(TimerFdBindings::CLOCK_MONOTONIC, TimerFdBindings::TFD_NONBLOCK | TimerFdBindings::TFD_CLOEXEC);
        if ($this->fd === -1) {
            throw new RuntimeException("Failed to create timerfd");
        }

        if (!($stream = fopen("php://fd/" . $this->fd, "rb"))) {
            throw new RuntimeException("Failed to open timerfd stream");
        }

        $this->stream = $stream;

        // Initialize timer and buffer once; it_interval defines how often the timer should trigger,
        // while it_value defines when the timer should trigger next (We don't want the timer to restart in an interval)
        $this->buffer = int_ptr::array(8);
        $this->timer = struct_itimerspec_ptr::array();
        $this->timer->it_interval->tv_sec = 0;
        $this->timer->it_interval->tv_nsec = 0;

        // Register stream
        $instance->registerStream($this->getStream(), function (): void {
            $this->read();
        });
    }

    public function setTimeout(int $timeoutMs): void
    {
        if ($this->fd === -1) return;

        $timer = $this->timer;
        if ($timeoutMs === 0) {
            // Set it to absolute time in the past to trigger immediately
            $timer->it_value->tv_sec = 1;
            $timer->it_value->tv_nsec = 0;
            $flags = self::TFD_TIMER_ABSTIME;
        } else {
            $timer->it_value->tv_sec = (int)($timeoutMs / 1000);
            $timer->it_value->tv_nsec = ($timeoutMs % 1000) * 1000000;
            $flags = 0;
        }

        if ($this->ffi->timerfd_settime($this->fd, $flags, $timer, null) === -1) {
            throw new RuntimeException("Failed to set timerfd time");
        }
    }

    public function getTimerFdId(): int
    {
        return $this->fd;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    private function read(): void
    {
        if (isset($this->fd) && $this->fd !== -1) {
            $this->ffi->read($this->fd, $this->buffer, 8);
        }
    }

    public function close(): void
    {
        if (isset($this->fd) && $this->fd !== -1) {
            $this->ffi->close($this->fd);
            $this->fd = -1;
        }
    }
}
