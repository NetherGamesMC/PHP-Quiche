<?php

namespace NetherGames\Quiche;

enum PacketType: int{
    case INITIAL = 1;
    case RETRY = 2;
    case HANDSHAKE = 3;
    case ZERO_RTT = 4;
    case SHORT = 5;
    case VERSION_NEGOTIATION = 6;
}
