<?php

namespace NetherGames\Quiche\event;

class ValidatedPathEvent extends PathEvent{
    private bool $migrate = false;

    public function migrate(bool $migrate = true) : void{
        $this->migrate = $migrate;
    }

    public function shouldMigrate() : bool{
        return $this->migrate;
    }
}