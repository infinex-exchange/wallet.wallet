<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class AssetsBalances {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized assets / balances manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'symbolToAssetId',
            [$this, 'symbolToAssetId']
        );
        
        $promises[] = $this -> amqp -> method(
            'assetIdToSymbol',
            [$this, 'assetIdToSymbol']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAssets',
            [$this, 'getAssets']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAsset',
            [$this, 'getAsset']
        );
        
        $promises[] = $this -> amqp -> method(
            'getBalances',
            [$this, 'getBalances']
        );
        
        $promises[] = $this -> amqp -> method(
            'getBalance',
            [$this, 'getBalance']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started assets / balances manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start assets / balances manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('symbolToAssetId');
        $promises[] = $this -> amqp -> unreg('assetIdToSymbol');
        $promises[] = $this -> amqp -> unreg('getAssets');
        $promises[] = $this -> amqp -> unreg('getAsset');
        $promises[] = $this -> amqp -> unreg('getBalances');
        $promises[] = $this -> amqp -> unreg('getBalance');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped assets / balances manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop assets / balances manager: '.((string) $e));
            }
        );
    }
    
    public function symbolToAssetId($body) {
        // Nonsense function, but very important for future changes
        if(!isset($body['symbol']))
            throw new Error('MISSING_DATA', 'symbol', 400);
        
        if(!$this -> validateAssetSymbol($symbol))
            throw new Error('VALIDATION_ERROR', 'symbol', 400);
            
        $task = array(
            ':symbol' => $symbol
        );
        
        $sql = 'SELECT assetid,
                       enabled
                FROM assets
                WHERE assetid = :symbol';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row)
            throw new Error('NOT_FOUND', 'Asset '.$symbol.' not found', 404);
        
        return $row['assetid'];
    }
    
    public function assetIdToSymbol($body) {
        // Nonsense function, but very important for future changes
        if(!isset($body['assetid']))
            throw new Error('MISSING_DATA', 'assetid');
        
        if(!$this -> validateAssetId($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
            
        return $body['assetid'];
    }
    
    public function getAssets($body) {
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'assetid',
                'name'
            ],
            $body
        );
        
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT assetid,
                       name,
                       icon_url,
                       default_prec,
                       enabled,
                       min_deposit,
                       min_withdrawal
                FROM assets
                WHERE 1=1';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
        
        $sql .= $search -> sql()
             . ' ORDER BY assetid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $assets = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $assets[] = $this -> rtrAsset($row);
        }
        
        return [
            'assets' => $assets,
            'more' => $pag -> more
        ];
    }
    
    public function getAsset($body) {
        if(!isset($body['assetid']))
            throw new Error('MISSING_DATA', 'assetid');
        
        if(!$this -> validateAssetId($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        
        $task = [
            ':assetid' => $body['assetid']
        ];
        
        $sql = 'SELECT assetid,
                       name,
                       icon_url,
                       default_prec,
                       enabled,
                       min_deposit,
                       min_withdrawal
                FROM assets
                WHERE assetid = :assetid';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Asset '.$body['assetid'].' not found');
        
        return $this -> rtrAsset($row);
    }
    
    public function getBalances($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'assets.assetid',
                'assets.name'
            ],
            $body
        );
        
        $task = [
            ':uid' => $body['uid']
        ];
        $search -> updateTask($task);
        
        $sql = 'SELECT assets.assetid,
                       assets.name,
                       assets.icon_url,
                       assets.default_prec,
                       assets.enabled,
                       assets.min_deposit,
                       assets.min_withdrawal,
                       wallet_balances.total,
                       wallet_balances.locked
                FROM assets
                LEFT JOIN wallet_balances ON wallet_balances.assetid = assets.assetid
                WHERE uid = :uid';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND assets.enabled = :enabled';
        }
        
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
                $this -> rtrAsset($row),
                $this -> rtrBalance($row)
            );
        }
        
        return [
            'balances' => $balances,
            'more' => $pag -> more
        ];
    }
    
    public function getBalance($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        
        $asset = $this -> getAsset([
            'assetid' => @$body['assetid'],
            'enabled' => @$body['enabled']
        ]);
        
        $task = [
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid']
        ];
        
        $sql = 'SELECT total,
                       locked
                FROM wallet_balances
                WHERE uid = :uid
                AND assetid = :assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return array_merge(
            $asset,
            $this -> rtrBalance($row)
        );
    }
    
    private function rtrAsset($row) {
        return [
            'assetid' => $row['assetid'],
            'symbol' => $row['assetid'],
            'name' => $row['name'],
            'iconUrl' => $row['icon_url'],
            'defaultPrec' => $row['default_prec'],
            'enabled' => $row['enabled'],
            'minDeposit' => trimFloat($row['minDeposit']),
            'minWithdrawal' => trimFloat($row['minWithdrawal'])
        ];
    }
    
    private function rtrBalance($row) {
        if(!isset($row['total']))
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
    
    private function validateAssetId($symbol) {
        return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
    }
    
    private function validateAssetSymbol($symbol) {
        return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
    }
}

?>