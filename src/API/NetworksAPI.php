<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;

class NetworksAPI {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized networks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets/{asset}/networks', [$this, 'getNetworksOfAsset']);
        $rc -> get('/assets/{asset}/networks/{network}', [$this, 'getNetworkOfAsset']);
    }
    
    public function getNetworksOfAsset($path, $query, $body, $auth) {
        $assetid = $this -> assets -> symbolToAssetId($path['asset']);
        
        $pag = new Pagination\Offset(50, 500, $query);
        $search = new Search(
            [
                'netid',
                'description'
            ],
            $query
        );
        
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       EXTRACT(epoch FROM networks.last_ping) AS last_ping,
                       assets.icon_url
                FROM networks,
                     asset_network,
                     asset_network AS an2,
                     assets
                WHERE asset_network.netid = networks.netid
                AND asset_network.assetid = assets.assetid
                AND asset_network.contract IS NULL
                AND an2.netid = networks.netid
                AND an2.assetid = :assetid'
             . $search -> sql()
             .' ORDER BY networks.netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $assets = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            
            $assets[] = [
                'symbol' => $row['assetid'],
                'name' => $row['name'],
                'iconUrl' => $row['icon_url'],
                'maxPrec' => $row['max_prec'],
                'experimental' => $row['experimental']
            ];
        }
        
        return [
            'assets' => $assets,
            'more' => $pag -> more
        ];
    }
    
    public function getAsset($path, $query, $body, $auth) {
        if(!validateAssetSymbol($path['symbol']))
            throw new Error('VALIDATION_ERROR', 'symbol', 400);
        
        $task = [
            ':symbol' => $path['symbol']
        ];
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.experimental,
                       MAX(asset_network.prec) AS max_prec
                FROM assets,
                     asset_network
                WHERE asset_network.assetid = assets.assetid
                AND assets.assetid = :symbol
                GROUP BY assets.assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Asset '.$path['symbol'].' not found', 404);
        
        return [
            'symbol' => $row['assetid'],
            'name' => $row['name'],
            'iconUrl' => $row['icon_url'],
            'maxPrec' => $row['max_prec'],
            'experimental' => $row['experimental']
        ];
    }
    
    function validateNetworkSymbol($symbol) {
        return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
    }
}

?>