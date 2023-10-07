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
                       default_prec,
                FROM assets
                WHERE enabled = TRUE'
             . $search -> sql()
             .' ORDER BY assetid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $assets = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $assets[] = $this -> assetRowToRespItem($row);
        }
        
        return [
            'assets' => $assets,
            'more' => $pag -> more
        ];
    }
    
    public function getAsset($path, $query, $body, $auth) {
        $assetid = $this -> assets -> symbolToAssetId($path['symbol'], false);
        
        $task = [
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT assetid,
                       name,
                       icon_url,
                       default_prec
                FROM assets
                WHERE enabled = TRUE
                AND assetid = :assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        return $this -> assetRowToRespItem($q -> fetch());
    }
    
    public function getAllBalances($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $pag = new Pagination\Offset(50, 500, $query);
        $search = new Search(
            [
                'assets.assetid',
                'assets.name'
            ],
            $query
        );
        
        $task = [
            ':uid' => $auth['uid']
        ];
        $search -> updateTask($task);
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.default_prec,
                       wallet_balances.total,
                       wallet_balances.locked
                FROM assets
                LEFT JOIN wallet_balances ON wallet_balances.assetid = assets.assetid
                WHERE assets.enabled = TRUE
                AND wallet_balances.uid = :uid';
        
        if(isset($query['nonZero']))
            $sql .= ' AND wallet_balances.total IS NOT NULL
                      AND wallet_balances.total != 0';
        
        $sql .= $search -> sql()
             . ' ORDER BY assets.assetid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $balances = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $balances[] = array_merge(
                $this -> assetRowToRespItem($row),
                $this -> balanceRowtoRespItem($row)
            );
        }
        
        return [
            'balances' => $balances,
            'more' => $pag -> more
        ];
    }
    
    public function getBalance($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $assetid = $this -> assets -> symbolToAssetId($path['symbol'], false);
        
        $task = [
            ':uid' => $auth['uid'],
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.default_prec,
                       wallet_balances.total,
                       wallet_balances.locked
                FROM assets
                LEFT JOIN wallet_balances ON wallet_balances.assetid = assets.assetid
                WHERE assets.enabled = TRUE
                AND assets.assetid = :assetid
                AND wallet_balances.uid = :uid';
        
        if(isset($query['nonZero']))
            $sql .= ' AND wallet_balances.total IS NOT NULL
                      AND wallet_balances.total != 0';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Empty balance for asset '.$path['symbol'], 404);
        
        return array_merge(
            $this -> assetRowToRespItem($row),
            $this -> balanceRowToRespItem($row)
        );
    }
    
    private function assetRowToRespItem($row) {
        return [
            'symbol' => $row['assetid'],
            'name' => $row['name'],
            'iconUrl' => $row['icon_url'],
            'defaultPrec' => $row['default_prec']
        ];
    }
    
    private function balanceRowToRespItem($row) {
        if($row['total'] == null)
            return [
                'total' => '0',
                'locked' => '0',
                'avbl' => '0'
            ];
        
        $dTotal = new Decimal($row['total']);
        $dLocked = new Decimal($row['locked']);
        $dAvbl = $dTotal - $dLocked;
        
        return [
            'total' => trimFloat($row['total']),
            'locked' => trimFloat($row['locked']),
            'avbl' => trimFloat($dAvbl -> toFixed(32))
        ];
    }
}

?>