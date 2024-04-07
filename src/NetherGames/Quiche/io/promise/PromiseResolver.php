<?php

namespace NetherGames\Quiche\io\promise;

use LogicException;

class PromiseResolver{
    private IOData $data;
    private Promise $promise;

    public function __construct(){
        $this->data = new IOData();
        $this->promise = new Promise($this->data);
    }

    private function setState(bool $success) : void{
        if($this->data->state !== null){
            throw new LogicException("Promise state has already been set!");
        }

        $this->data->state = $success;
    }

    public function failure() : void{
        $this->setState(false);

        foreach($this->data->onFailure as $onFailure){
            $onFailure();
        }

        $this->cleanup();
    }

    public function success() : void{
        $this->setState(true);

        foreach($this->data->onSuccess as $onSuccess){
            $onSuccess();
        }

        $this->cleanup();
    }

    private function cleanup() : void{
        $this->data->onFailure = [];
        $this->data->onSuccess = [];
    }

    public function getPromise() : Promise{
        return $this->promise;
    }
}