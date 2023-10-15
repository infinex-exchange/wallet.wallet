<?php
use Infinex\Exceptions\Error;
use function Infinex\Math\validateFloat;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class LockMgr {
    private $log;
    private $amqp;
    private $pdo;
    private $asb;
    private $wlog;
    
    function __construct($log, $amqp, $pdo, $asb, $wlog) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> asb = $asb;
        $this -> wlog = $wlog;
        
        $this -> log -> debug('Initialized locks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'lock',
            [$this, 'lock']
        );
        
        $promises[] = $this -> amqp -> method(
            'release',
            [$this, 'release']
        );
        
        $promises[] = $this -> amqp -> method(
            'commit',
            [$this, 'commit']
        );
        
        $promises[] = $this -> amqp -> method(
            'relock',
            [$this, 'relock']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started locks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start locks manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('lock');
        $promises[] = $this -> amqp -> unreg('release');
        $promises[] = $this -> amqp -> unreg('commit');
        $promises[] = $this -> amqp -> unreg('relock');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped locks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop locks manager: '.((string) $e));
            }
        );
    }
    
    public function lock($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        
        if(isset($body['amount']) && !validateFloat($body['amount']))
            throw new Error('VALIDATION_ERROR', 'amount', 400);
        
        $this -> asb -> assetIdToSymbol([
            'assetid' => @$body['assetid']
        ]);
        
        $amount = @$body['amount'];
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid']
        );
        
        if($amount === null) {
            $task = [
                ':uid' => $body['uid'],
                ':assetid' => $body['assetid']
            ];
            
            $sql = 'SELECT total-locked AS avbl
                    FROM wallet_balances
                    WHERE uid = :uid
                    AND assetid = :assetid
                    FOR UPDATE';
            
            $q = $this -> pdo -> prepare($sql);
            $this -> pdo -> execute($task);
            $row = $q -> fetch();
            
            if(!$row) {
                $this -> pdo -> rollBack();
                throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
            }
            
            $amount = $row['avbl'];
        }
        
        $task = [
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $amount,
            ':amount2' => $amount
        ];
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked + :amount
                WHERE uid = :uid
                AND assetid = :assetid
                AND total - locked >= :amount2
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $amount,
            ':reason' => $body['reason'],
            ':context' => @$body['context']
        );
        
        $sql = 'INSERT INTO wallet_locks(
                    uid,
                    assetid,
                    amount,
                    reason,
                    context
                )
                VALUES(
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :context
                )
                RETURNING lockid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        $lockid = $row['lockid']
        
        $this -> wlog -> insert(
            $this -> pdo,
            'LOCK',
            $lockid,
            $body['uid'],
            $body['assetid'],
            $amount,
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
            
        return [
            'lockid' => $lockid,
            'amount' => $amount
        ];
    }
    
    private function release($body) {
        if(!isset($body['lockid']))
            throw new Error('MISSING_DATA', 'lockid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        
        if(!$this -> validateLockid($body['lockid']))
            throw new Error('VALIDATION_ERROR', 'lockid');
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $body['lockid']
        );
        
        $sql = 'DELETE FROM wallet_locks
                WHERE lockid = :lockid
                RETURNING uid, assetid, amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Lock '.$body['lockid'].' not found');
        }
        
        $task = array(
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':amount' => $lock['amount'],
            ':amount2' => $lock['amount']
        );
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked - :amount
                WHERE uid = :uid
                AND assetid = :assetid
                AND locked >= :amount2
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < release amount');
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'RELEASE',
            $body['lockid'],
            $lock['uid'],
            $lock['assetid'],
            $lock['amount'],
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
    }
    
    public function commit($body) {
        if(!isset($body['lockid']))
            throw new Error('MISSING_DATA', 'lockid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        
        if(!$this -> validateLockid($body['lockid']))
            throw new Error('VALIDATION_ERROR', 'lockid');
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $body['lockid']
        );
        
        $sql = 'DELETE FROM wallet_locks
                WHERE lockid = :lockid
                RETURNING uid, assetid, amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Lock '.$body['lockid'].' not found');
        }
        
        $task = array(
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':amount' => $lock['amount'],
            ':amount2' => $lock['amount'],
            ':amount3' => $lock['amount']
        );
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked - :amount,
                    total = total - :amount2
                WHERE uid = :uid
                AND assetid = :assetid
                AND locked >= :amount3
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < commit amount');
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'COMMIT',
            $body['lockid'],
            $lock['uid'],
            $lock['assetid'],
            $lock['amount'],
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
    }
    
    public function relock($body) {
        if(!isset($body['lockid']))
            throw new Error('MISSING_DATA', 'lockid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        
        if(!$this -> validateLockid($body['lockid']))
            throw new Error('VALIDATION_ERROR', 'lockid');
        
        if(isset($body['abs'])) {
            if(!validateFloat($body['abs']))
                throw new Error('VALIDATION_ERROR', 'abs');
        }
        else if(isset($body['rel'])) {
            if(!validateFloat($body['rel'], true))
                throw new Error('VALIDATE_ERROR', 'rel');
        }
        else
            throw new Error('MISSING_DATA', 'abs or rel');
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $body['lockid']
        );
        
        $sql = 'SELECT uid,
                       assetid,
                       amount
                FROM wallet_locks
                WHERE lockid = :lockid
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Lock '.$body['lockid'].' not found');
        }
        
        if(isset($body['abs'])) {
            $decDelta = new Decimal($body['abs']);
            $decDelta -= $lock['amount'];
            $strDelta = trimFloat($decDelta -> toFixed(32));
        }
        else {
            $decDelta = new Decimal($body['rel']);
            $strDelta = $body['rel'];
        }
        
        if($decDelta -> isNegative() && $decDelta -> abs() > $lock['amount']) {
            $this -> pdo -> rollBack();
            throw new Error('OUT_OF_RANGE', 'Delta > lock amount');
        }
        
        $task = [
            ':lockid' => $body['lockid'],
            ':delta' => $strDelta
        ];
        
        $sql = 'UPDATE wallet_locks
                SET amount = amount + :delta
                WHERE lockid = :lockid
                RETURNING amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $updatedLock = $q -> fetch();
        
        $task = [
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':delta' => $strDelta,
            ':delta2' => $strDelta
        ];
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked + :delta
                WHERE uid = :uid
                AND assetid = :assetid
                AND total - locked >= :delta2
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'RELOCK',
            $body['lockid'],
            $lock['uid'],
            $lock['assetid'],
            $strDelta,
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
    }
    
    private function validateLockid($lockid) {
        if(!is_int($lockid)) return false;
        if($lockid < 1) return false;
        return true;
    }
}

?>