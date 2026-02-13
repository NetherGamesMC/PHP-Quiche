<?php

namespace NetherGames\Quiche;

use NetherGames\Quiche\bindings\Quiche as QuicheBindings;

enum CongestionControlAlgorithm: int{
    case RENO = QuicheBindings::QUICHE_CC_RENO;
    case CUBIC = QuicheBindings::QUICHE_CC_CUBIC;
    case BBR2_GCONGESTION = QuicheBindings::QUICHE_CC_BBR2_GCONGESTION;
}
