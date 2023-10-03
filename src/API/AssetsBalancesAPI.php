<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use Decimal\Decimal;
use function Infinex\Math\trimFloat;

class AssetsBalancesAPI {
    private $log;
    private $pdo;
    private $assets;
    
    function __construct($log, $pdo, $assets) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> assets = $assets;
        
        $this -> log -> debug('Initialized assets / balances API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets', [$this, 'getAllAssets']);
        $rc -> get('/assets/{symbol}', [$this, 'getAsset']);
        $rc -> get('/balances', [$this, 'getAllBalances']);
        $rc -> get('/balances/{symbol}', [$this, 'getBalance']);
    }
    
    public function getAllAssets($path, $query, $body, $auth) {
        return $this -> commonGetAll(false, $path, $query, $body, $auth);
    }
    
    public function getAsset($path, $query, $body, $auth) {
        return $this -> commonGet(false, $path, $query, $body, $auth);
    }
    
    public function getAllBalances($path, $query, $body, $auth) {
        return $this -> commonGetAll(true, $path, $query, $body, $auth);
    }
    
    public function getBalance($path, $query, $body, $auth) {
        return $this -> commonGet(true, $path, $query, $body, $auth);
    }
    
    private function commonGetAll($balances, $path, $query, $body, $auth) {
        if($balances && !$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
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
                       assets.default_prec,
                       MAX(asset_network.prec) AS max_prec
                FROM assets,
                     asset_network
                WHERE asset_network.assetid = assets.assetid'
             . $search -> sql()
             .' GROUP BY assets.assetid
                ORDER BY assets.assetid ASC'
             . $pag -> sql();
        
        if($balances) {
            $task[':uid'] = $auth['uid'];
            
            $sql = 'SELECT inn.*,
                           wallet_balances.total,
                           wallet_balances.locked
                    FROM ('
                 . $sql
                 .' ) AS inn
                    LEFT JOIN wallet_balances ON wallet_balances.assetid = inn.assetid
                    WHERE wallet_balances.uid = :uid';
            
            if(isset($query['nonZero']))
                $sql .= ' AND wallet_balances.total IS NOT NULL
                          AND wallet_balances.total != 0';
            
            $sql .= ' ORDER BY inn.assetid ASC';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $items = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $items[] = $this -> commonRowToRespItem($balances, $row);
        }
        
        $resp = [];
        if($balances)
            $resp['balances'] = $items;
        else
            $resp['assets'] = $items;
        $resp['more'] = $pag -> more;
        return $resp;
    }
    
    private function commonGet($balances, $path, $query, $body, $auth) {
        if($balances && !$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $assetid = $this -> assets -> symbolToAssetId($path['symbol']);
        
        $task = [
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.default_prec,
                       MAX(asset_network.prec) AS max_prec
                FROM assets,
                     asset_network
                WHERE asset_network.assetid = assets.assetid
                AND assets.assetid = :assetid
                GROUP BY assets.assetid';
        
        if($balances) {
            $task[':uid'] = $auth['uid'];
            
            $sql = 'SELECT inn.*,
                           wallet_balances.total,
                           wallet_balances.locked
                    FROM ('
                 . $sql
                 .' ) AS inn
                    LEFT JOIN wallet_balances ON wallet_balances.assetid = inn.assetid
                    WHERE wallet_balances.uid = :uid';
            
            if(isset($query['nonZero']))
                $sql .= ' AND wallet_balances.total IS NOT NULL
                          AND wallet_balances.total != 0';
            
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Empty balance for asset '.$path['symbol'], 404);
        
        return $this -> commonRowToRespItem($balances, $row);
    }
    
    private function commonRowToRespItem($balances, $row) {
        $item = [
            'symbol' => $row['assetid'],
            'name' => $row['name'],
            'iconUrl' => $row['icon_url'],
            'defaultPrec' => $row['default_prec'],
            'maxPrec' => $row['max_prec']
        ];
            
        if($balances) {
            if($row['total'] == null) {
                $item['total'] = '0';
                $item['locked'] = '0';
                $item['avbl'] = '0';
            } else {
                $dTotal = new Decimal($row['total']);
                $dLocked = new Decimal($row['locked']);
                $dAvbl = $dTotal - $dLocked;
                
                $item['total'] = trimFloat($row['total']);
                $item['locked'] = trimFloat($row['locked']);
                $item['avbl'] = trimFloat($dAvbl -> toFixed($row['max_prec']));
            }
        }
    
        return $item;
    }
}

?>