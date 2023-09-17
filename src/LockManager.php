<?php

use Infinex\AMQP\RPCException;

class LockManager {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized balance lock manager');
    }
    
    public function bind($amqp) {
        $th = $this;
        
        $amqp -> method(
            'wallet_lock',
            function($body) use($th) {
                return $th -> lock($body);
            }
        );
        
        $amqp -> method(
            'wallet_release',
            function($body) use($th) {
                return $th -> release($body);
            }
        );
        
        $amqp -> method(
            'wallet_commit',
            function($body) use($th) {
                return $th -> commit($body);
            }
        );
    }
    
    public function lock($body) {
        if(!isset($body['uid']))
            throw new RPCException('MISSING_DATA', 'uid');
        if(!isset($body['assetid']))
            throw new RPCException('MISSING_DATA', 'assetid');
        if(!isset($body['amount']))
            throw new RPCException('MISSING_DATA', 'amount');
        if(!isset($body['reason']))
            throw new RPCException('MISSING_DATA', 'reason');
        if(!isset($body['contextId']))
            throw new RPCException('MISSING_DATA', 'contextId');
        
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
    
    public function release($body) {
        if(!isset($body['lockId']))
            throw new RPCException('MISSING_DATA', 'lockId');
        if(!isset($body['reason']))
            throw new RPCException('MISSING_DATA', 'reason');
        if(!isset($body['contextId']))
            throw new RPCException('MISSING_DATA', 'contextId');
        
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
    
    public function release($body) {
        if(!isset($body['lockId']))
            throw new RPCException('MISSING_DATA', 'lockId');
        if(!isset($body['reason']))
            throw new RPCException('MISSING_DATA', 'reason');
        if(!isset($body['contextId']))
            throw new RPCException('MISSING_DATA', 'contextId');
        
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