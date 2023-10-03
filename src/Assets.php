<?php

use Infinex\Exceptions\Error;
use React\Promise;

class Assets {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized assets manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'symbolToAssetId',
            function($body) use($th) {
                return $th -> symbolToAssetId($body['symbol']);
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'assetIdToSymbol',
            function($body) use($th) {
                return $th -> assetIdToSymbol($body['assetid']);
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started assets manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start assets manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('symbolToAssetId');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped assets manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop assets manager: '.((string) $e));
            }
        );
    }
    
    public function symbolToAssetId($symbol) {
        // Nonsense function, but very important for future changes
        
        if(!$this -> validateAssetSymbol($symbol))
            throw new Error('VALIDATION_ERROR', 'Invalid asset symbol', 400);
            
        $task = array(
            ':symbol' => $symbol
        );
        
        $sql = 'SELECT assetid
                FROM assets
                WHERE assetid = :symbol';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row)
            throw new Error('NOT_FOUND', 'Asset '.$symbol.' not found', 404);
        
        return $row['assetid'];
    }
    
    public function assetIdToSymbol($assetid) {
        return $assetid;
    }
    
    private function validateAssetSymbol($symbol) {
        return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
    }
}

?>