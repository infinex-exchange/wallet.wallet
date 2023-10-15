<?php

class AssetsBalancesAPI {
    private $log;
    private $asb;
    
    function __construct($log, $asb) {
        $this -> log = $log;
        $this -> asb = $asb;
        
        $this -> log -> debug('Initialized assets / balances API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets', [$this, 'getAllAssets']);
        $rc -> get('/assets/{symbol}', [$this, 'getAsset']);
        $rc -> get('/balances', [$this, 'getAllBalances']);
        $rc -> get('/balances/{symbol}', [$this, 'getBalance']);
    }
    
    public function getAllAssets($path, $query, $body, $auth) {
        $resp = $this -> asb -> getAssets([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$query['limit'],
            'q' => @$query['q']
        ]);
        
        foreach($resp['assets'] as $k => $v)
            $resp['assets'][$k] = $this -> ptpAsset($v);
        
        return $resp;
    }
    
    public function getAsset($path, $query, $body, $auth) {
        $assetid = $this -> asb -> symbolToAssetId([
            'symbol' => @$body['symbol'],
            'enabled' => true
        ]);
        
        $asset = $this -> asb -> getAsset([
            'assetid' => $assetid
        ]);
        
        return $this -> ptpAsset($asset);
    }
    
    public function getAllBalances($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
            
        $resp = $this -> asb -> getBalances([
            'uid' => $auth['uid'],
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$query['limit'],
            'q' => @$query['q'],
            'nonZero' => @$query['nonZero']
        ]);
        
        foreach($resp['balances'] as $k => $v)
            $resp['balances'][$k] = $this -> ptpBalance($v);
        
        return $resp;
    }
    
    public function getSession($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $assetid = $this -> asb -> symbolToAssetId([
            'symbol' => @$body['symbol'],
            'enabled' => true
        ]);
        
        return $this -> ptpBalance(
            $this -> asb -> getBalance([
                'uid' => $auth['uid'],
                'assetid' => $assetid
            ])
        );
    }
    
    private function ptpAsset($record) {
        return [
            'symbol' => $record['symbol'],
            'name' => $record['name'],
            'iconUrl' => $record['iconUrl'],
            'defaultPrec' => $record['defaultPrec']
        ];
    }
    
    private function ptpBalance($record) {
        return [
            'symbol' => $record['symbol'],
            'name' => $record['name'],
            'iconUrl' => $record['iconUrl'],
            'defaultPrec' => $record['defaultPrec'],
            'total' => $record['total'],
            'locked' => $record['locked'],
            'avbl' => $record['avbl']
        ];
    }
}

?>