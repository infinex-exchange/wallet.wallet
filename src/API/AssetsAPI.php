<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;

class AssetsAPI {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized assets API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets', [$this, 'getAllAssets']);
        $rc -> get('/assets/{symbol}', [$this, 'getAsset']);
    }
    
    public function getAllAssets($path, $query, $body, $auth) {
        $pag = new Pagination\Offset(50, 500, $query);
        $search = new Search(
            [
                'assets.assetid',
                'assets.name'
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
}

?>