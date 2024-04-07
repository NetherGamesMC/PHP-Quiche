<?php

namespace NetherGames\Quiche\io\promise;

use Closure;

class Promise{
    /**
     * @internal
     */
    public function __construct(private readonly IOData $data){

    }

    public function hasResult() : bool{
        return $this->data->state !== null;
    }

    public function hasSucceeded() : bool{
        return $this->data->state === true;
    }

    public function onResult(?Closure $onSuccess = null, ?Closure $onFailure = null) : void{
        if($this->hasResult()){
            if($this->hasSucceeded()){
                if($onSuccess !== null){
                    $onSuccess();
                }
            }else{
                if($onFailure !== null){
                    $onFailure();
                }
            }
        }else{
            if($onSuccess !== null){
                $this->data->onSuccess[] = $onSuccess;
            }

            if($onFailure !== null){
                $this->data->onFailure[] = $onFailure;
            }
        }
    }
}