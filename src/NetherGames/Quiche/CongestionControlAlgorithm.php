<?php

namespace NetherGames\Quiche;

use NetherGames\Quiche\bindings\Quiche as QuicheBindings;

enum CongestionControlAlgorithm: int{
    case RENO = QuicheBindings::QUICHE_CC_RENO;
    case CUBIC = QuicheBindings::QUICHE_CC_CUBIC;
    case BBR = QuicheBindings::QUICHE_CC_BBR;
    case BBR2 = QuicheBindings::QUICHE_CC_BBR2;
}
