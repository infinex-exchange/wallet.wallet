<?php

use Infinex\Exceptions\Error;
use React\Promise;

class CreditDebit {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized credit / debit');
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
                $th -> log -> info('Started credit / debit');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start credit / debit: '.((string) $e));
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
                $th -> log -> info('Stopped credit / debit');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop credit / debit: '.((string) $e));
            }
        );
    }
    
    public function credit($uid, $assetid, $amount, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $assetid,
            ':amount' => $amount
        );
        
        $sql = 'UPDATE wallet_balances
                SET total = total + :amount
                WHERE uid = :uid
                AND assetid = :assetid
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $sql = 'INSERT INTO wallet_balances(
                        uid,
                        assetid,
                        total
                    )
                    VALUES(
                        :uid,
                        :assetid,
                        :amount
                    )';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
        }
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $assetid,
            ':amount' => $amount,
            ':reason' => $reason,
            ':context' => $context
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    uid,
                    assetid,
                    amount,
                    reason,
                    context
                )
                VALUES(
                    'CREDIT',
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :context
                )";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
    
    public function debit($uid, $assetid, $amount, $reason, $context) {
        $this -> pdo -> beginTransaction();
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $assetid,
            ':amount' => $amount,
            ':amount2' => $amount
        );
        
        $sql = 'UPDATE wallet_balances
                SET total = total - :amount
                WHERE uid = :uid
                AND assetid = :assetid
                AND total - locked >= :amount2
                RETURNING uid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('INSUF_BALANCE', 'Insufficient balance to debit');
        }
        
        $task = array(
            ':uid' => $uid,
            ':assetid' => $assetid,
            ':amount' => $amount,
            ':reason' => $reason,
            ':context' => $context
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    uid,
                    assetid,
                    amount,
                    reason,
                    context
                )
                VALUES(
                    'DEBIT',
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :context
                )";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
}

?>