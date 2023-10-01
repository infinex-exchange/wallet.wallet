<?php
use Infinex\Exceptions\Error;
use React\Promise;

class LockMgr {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized locks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'credit',
            function($body) use($th) {
                return $th -> credit(
                    $body['uid'],
                    $body['assetid'],
                    $body['amount'],
                    $body['reason'],
                    $body['context']
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'debit',
            function($body) use($th) {
                return $th -> debit(
                    $body['uid'],
                    $body['assetid'],
                    $body['amount'],
                    $body['reason'],
                    $body['context']
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
    
    public function lock($uid, $assetid, $amount, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':amount2' => $body['amount']
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
            throw new RPCException('INSUF_BALANCE', 'Insufficient balance to create a lock');
        }
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = 'INSERT INTO wallet_locks(
                    uid,
                    assetid,
                    amount
                )
                VALUES(
                    :uid,
                    :assetid,
                    :amount
                )
                RETURNING lockid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        $lockId = $row['lockid'];
        
        $task = array(
            ':lockid' => $lockId,
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    lockid,
                    uid,
                    assetid,
                    amount,
                    reason,
                    contextid
                )
                VALUES(
                    'LOCK',
                    :lockid,
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :contextid
                )";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
            
        return $lockId;
    }
    
    public function release($lockId, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockId' => $body['lockId']
        );
        
        $sql = 'DELETE FROM wallet_locks
                WHERE lockid = :lockid
                RETURNING uid, assetid, amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new RPCException('LOCK_NOT_FOUND', 'Lock '.$body['lockId'].' not found');
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
            throw new RPCException('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < release amount');
        }
        
        $task = array(
            ':lockid' => $body['lockId'],
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':amount' => $lock['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    lockid,
                    uid,
                    assetid,
                    amount,
                    reason,
                    contextid
                )
                VALUES(
                    'RELEASE',
                    :lockid,
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :contextid
                )";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
    
    public function commit($lockId, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockId' => $body['lockId']
        );
        
        $sql = 'DELETE FROM wallet_locks
                WHERE lockid = :lockid
                RETURNING uid, assetid, amount';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $lock = $q -> fetch();
            
        if(!$lock) {
            $this -> pdo -> rollBack();
            throw new RPCException('LOCK_NOT_FOUND', 'Lock '.$body['lockId'].' not found');
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
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new RPCException('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < commit amount');
        }
        
        $task = array(
            ':lockid' => $body['lockId'],
            ':uid' => $lock['uid'],
            ':assetid' => $lock['assetid'],
            ':amount' => $lock['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    lockid,
                    uid,
                    assetid,
                    amount,
                    reason,
                    contextid
                )
                VALUES(
                    'COMMIT',
                    :lockid,
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :contextid
                )";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
}

?>