<?php
use Infinex\Exceptions\Error;
use React\Promise;
use Decimal\Decimal;

class LockMgr {
    private $log;
    private $amqp;
    private $pdo;
    private $wlog;
    
    function __construct($log, $amqp, $pdo, $wlog) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> wlog = $wlog;
        
        $this -> log -> debug('Initialized locks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'simpleLock',
            function($body) use($th) {
                return $th -> simpleLock(
                    $body['uid'],
                    $body['asset'],
                    $body['amount'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'simpleRelease',
            function($body) use($th) {
                return $th -> simpleRelease(
                    $body['lockid'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'simpleCommit',
            function($body) use($th) {
                return $th -> simpleCommit(
                    $body['lockid'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'delayedLock',
            function($body) use($th) {
                return $th -> delayedLock(
                    $body['uid'],
                    $body['asset'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'delayedRelease',
            function($body) use($th) {
                return $th -> delayedRelease(
                    $body['lockid'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'delayedCommit',
            function($body) use($th) {
                return $th -> delayedCommit(
                    $body['lockid'],
                    $body['amount'],
                    $body['reason'],
                    isset($body['context']) ? $body['context'] : null
                );
            }
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
        
        $promises[] = $this -> amqp -> unreg('credit');
        $promises[] = $this -> amqp -> unreg('debit');
        
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
    
    public function simpleLock($uid, $asset, $amount, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $asset,
            ':amount' => $amount,
            ':amount2' => $amount
        );
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked + :amount
                WHERE uid = :uid
                AND assetid = :assetid
                AND total - locked >= :amount2
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        
        $lockid = $this -> insertLock(
            $this -> pdo,
            'SIMPLE',
            $uid,
            $asset,
            $amount
        );
        
        $this -> wlog -> insert(
            $this -> pdo,
            'LOCK_SIMPLE',
            $lockid,
            $uid,
            $asset,
            $amount,
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
            
        return $lockid;
    }
    
    public function simpleRelease($lockid, $reason, $context) {
        return $this -> commonRelease(
            'SIMPLE',
            $lockid,
            $reason,
            $context
        );
    }
    
    public function simpleCommit($lockid, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $lockid
        );
        
        $sql = "DELETE FROM wallet_locks
                WHERE lockid = :lockid
                AND type = 'SIMPLE'
                RETURNING uid, assetid, amount";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', "Lock $lockid not found");
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
            'COMMIT_SIMPLE',
            $lockid,
            $lock['uid'],
            $lock['assetid'],
            $lock['amount'],
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
    }
    
    public function delayedLock($uid, $asset, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $asset
        );
        
        $sql = 'UPDATE wallet_balances new
                SET locked = old.locked + old.avbl
                FROM (
                    SELECT uid,
                           assetid,
                           locked,
                           total - locked AS avbl
                    FROM wallet_balances
                    WHERE uid = :uid
                    AND assetid = :assetid
                ) old
                WHERE new.uid = old.uid
                AND new.assetid = old.assetid
                AND old.avbl > 0
                RETURNING old.avbl';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        $amount = $row['avbl'];
        
        $lockid = $this -> insertLock(
            $this -> pdo,
            'DELAYED',
            $uid,
            $asset,
            $amount
        );
        
        $this -> wlog -> insert(
            $this -> pdo,
            'LOCK_DELAYED',
            $lockid,
            $uid,
            $asset,
            $amount,
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
            
        return [
            'lockid' => $lockid,
            'amount' => $amount
        ];
    }
    
    public function delayedRelease($lockid, $reason, $context) {
        return $this -> commonRelease(
            'DELAYED',
            $lockid,
            $reason,
            $context
        );
    }
    
    public function delayedCommit($lockid, $amount, $reason, $context) {
        $dAmount = new Decimal($amount);
        
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $lockid
        );
        
        $sql = "DELETE FROM wallet_locks
                WHERE lockid = :lockid
                AND type = 'DELAYED'
                RETURNING uid, assetid, amount";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', "Lock $lockid not found");
        }
        
        $dLockAmount = new Decimal($lock['amount']);
        if($dAmount > $dLockAmount) {
            $this -> pdo -> rollBack();
            throw new Error('OUT_OF_RANGE', "Commit amount > lock amount");
        }
        
        $task = array(
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':lock_amount' => $lock['amount'],
            ':lock_amount2' => $lock['amount'],
            ':commit_amount' => $amount
        );
        
        $sql = 'UPDATE wallet_balances
                SET locked = locked - :lock_amount,
                    total = total - :commit_amount
                WHERE uid = :uid
                AND assetid = :assetid
                AND locked >= :lock_amount2
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
            'COMMIT_DELAYED',
            $lockid,
            $lock['uid'],
            $lock['assetid'],
            $amount,
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
    }
    
    private function insertLock($tPdo, $type, $uid, $assetid, $amount) {
        $task = array(
            ':uid' => $uid,
            ':type' => $type,
            ':assetid' => $assetid,
            ':amount' => $amount
        );
        
        $sql = 'INSERT INTO wallet_locks(
                    uid,
                    type,
                    assetid,
                    amount
                )
                VALUES(
                    :uid,
                    :type,
                    :assetid,
                    :amount
                )
                RETURNING lockid';
        
        $q = $tPdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return $row['lockid'];
    }
    
    private function commonRelease($type, $lockid, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $lockid,
            ':type' => $type
        );
        
        $sql = 'DELETE FROM wallet_locks
                WHERE lockid = :lockid
                AND type = :type
                RETURNING uid, assetid, amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', "Lock $lockid not found");
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
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < release amount');
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'RELEASE_'.$type,
            $lockid,
            $lock['uid'],
            $lock['assetid'],
            $lock['amount'],
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
    }
}

?>