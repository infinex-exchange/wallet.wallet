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
                'assetid',
                'name'
            ],
            $query
        );
        
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT assetid,
                       name,
                       icon_url,
                       experimental
                FROM assets
                WHERE 1=1'
             . $search -> sql()
             . ' ORDER BY assetid ASC'
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
                'maxPrec' => $this -> getMaxPrec($row['assetid']),
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