<?php
use Infinex\Exceptions\Error;
use React\Promise;

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
                    $body['assetid'],
                    $body['amount'],
                    $body['reason'],
                    $body['context']
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'simpleRelease',
            function($body) use($th) {
                return $th -> simpleRelease(
                    $body['lockid'],
                    $body['reason'],
                    $body['context']
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'simpleCommit',
            function($body) use($th) {
                return $th -> simpleCommit(
                    $body['lockid'],
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
    
    public function simpleLock($uid, $assetid, $amount, $reason, $context) {
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
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_locks(
                    uid,
                    type,
                    assetid,
                    amount
                )
                VALUES(
                    :uid,
                    'SIMPLE',
                    :assetid,
                    :amount
                )
                RETURNING lockid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        $lockid = $row['lockid'];
        
        $this -> wlog -> insert(
            $this -> pdo,
            'SIMPLE_LOCK',
            $lockid,
            $uid,
            $assetid,
            $amount,
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
            
        return $lockid;
    }
    
    public function simpleRelease($lockid, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $body['lockid']
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
            'SIMPLE_RELEASE',
            $lockid,
            $lock['uid'],
            $lock['assetid'],
            $lock['amount'],
            $reason,
            $context
        );
        
        $this -> pdo -> commit();
    }
    
    public function simpleCommit($lockid, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':lockid' => $body['lockid']
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
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('DATA_INTEGRITY_ERROR', 'No balance entry or locked balance < commit amount');
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'SIMPLE_COMMIT',
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