<?php

require_once __DIR__.'/validate.php';

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
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.experimental,
                       MAX(asset_network.prec) AS max_prec
                FROM assets,
                     asset_network
                WHERE asset_network.assetid = assets.assetid'
             . $search -> sql()
             .' GROUP BY assets.assetid
                ORDER BY assets.assetid ASC'
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
}

?>