<?php

namespace NetherGames\Quiche\io\promise;

use Closure;

/**
 * @internal
 */
class IOData{
    /** @var Closure[] */
    public array $onFailure = [];
    /** @var Closure[] */
    public array $onSuccess = [];
    public ?bool $state = null;
}