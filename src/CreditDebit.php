<?php

use Infinex\AMQP\RPCException;

class CreditDebit {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized credit/debit manager');
    }
    
    public function bind($amqp) {
        $th = $this;
        
        $amqp -> method(
            'wallet_credit',
            function($body) use($th) {
                return $th -> credit($body);
            }
        );
        
        $amqp -> method(
            'wallet_debit',
            function($body) use($th) {
                return $th -> debit($body);
            }
        );
    }
    
    public function credit($body) {
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
            ':amount' => $body['amount']
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
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    uid,
                    assetid,
                    amount,
                    reason,
                    contextid
                )
                VALUES(
                    'CREDIT',
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
    
    public function debit($body) {
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
            throw new RPCException('INSUF_BALANCE', 'Insufficient balance to debit');
        }
        
        $task = array(
            ':uid' => $body['uid'],
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':reason' => $body['reason'],
            ':contextid' => $body['contextId']
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    uid,
                    assetid,
                    amount,
                    reason,
                    contextid
                )
                VALUES(
                    'DEBIT',
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