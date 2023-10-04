<?php

use Infinex\Exceptions\Error;
use React\Promise;

class Networks {
    private $log;
    private $amqp;
    private $pdo;
    private $assets;
    
    function __construct($log, $amqp, $pdo, $assets) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> assets = $assets;
        
        $this -> log -> debug('Initialized networks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'symbolToNetId',
            function($body) use($th) {
                return $th -> symbolToNetId(
                    $body['symbol'],
                    $body['allowDisabled']
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'netIdToSymbol',
            function($body) use($th) {
                return $th -> netIdToSymbol($body['netid']);
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'resolveAssetNetworkPair',
            function($body) use($th) {
                return $th -> resolveAssetNetworkPair(
                    $body['assetSymbol'],
                    $body['networkSymbol'],
                    $body['allowDisabled']
                );
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start networks manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('symbolToNetId');
        $promises[] = $this -> amqp -> unreg('netIdToSymbol');
        $promises[] = $this -> amqp -> unreg('resolveAssetNetworkPair');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop networks manager: '.((string) $e));
            }
        );
    }
    
    public function symbolToNetId($symbol, $allowDisabled) {
        // Nonsense function, but very important for future changes
        
        if(!$this -> validateNetworkSymbol($symbol))
            throw new Error('VALIDATION_ERROR', 'Invalid network symbol', 400);
            
        $task = array(
            ':symbol' => $symbol
        );
        
        $sql = 'SELECT netid,
                       enabled
                FROM networks
                WHERE netid = :symbol';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row)
            throw new Error('NOT_FOUND', 'Network '.$symbol.' not found', 404);
        
        if(!$allowDisabled && !$row['enabled'])
            throw new Error('FORBIDDEN', 'Network '.$symbol.' is disabled', 403);
        
        return $row['netid'];
    }
    
    public function netIdToSymbol($netid) {
        return $netid;
    }
    
    public function resolveAssetNetworkPair($assetSymbol, $networkSymbol, $allowDisabled) {
        $assetid = $this -> assets -> symbolToAssetId($assetSymbol, $allowDisabled);
        $netid = $this -> symbolToNetId($networkSymbol, $allowDisabled);
        
        $task = [
            ':assetid' => $assetid,
            ':netid' => $netid
        ];
        
        $sql = 'SELECT enabled
                FROM asset_network
                WHERE assetid = :assetid
                AND netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', "$networkSymbol is not a valid network for $assetSymbol", 404);
        
        if(!$allowDisabled && !$row['enabled'])
            throw new Error('FORBIDDEN', "Network $networkSymbol is disabled for asset $assetSymbol", 403);
        
        return [
            'assetid' => $assetid,
            'netid' => $netid
        ];
    }
    
    private function validateNetworkSymbol($symbol) {
        return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
    }
}

?>