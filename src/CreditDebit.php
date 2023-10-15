<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\validateFloat;
use React\Promise;

class CreditDebit {
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
        
        $this -> log -> debug('Initialized credit / debit');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'credit',
            [$this, 'credit']
        );
        
        $promises[] = $this -> amqp -> method(
            'debit',
            [$this, 'debit']
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
    
    public function credit($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        if(!isset($body['amount']))
            throw new Error('MISSING_DATA', 'amount', 400);
        
        if(!validateFloat($body['amount']))
            throw new Error('VALIDATION_ERROR', 'amount', 400);
        
        $this -> asb -> assetIdToSymbol([
            'assetid' => @$body['assetid']
        ]);
        
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
        
        $this -> wlog -> insert(
            $this -> pdo,
            'CREDIT',
            null,
            $body['uid'],
            $body['assetid'],
            $body['amount'],
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
    }
    
    public function debit($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['reason']))
            throw new Error('MISSING_DATA', 'reason');
        if(!isset($body['amount']))
            throw new Error('MISSING_DATA', 'amount', 400);
        
        if(!validateFloat($body['amount']))
            throw new Error('VALIDATION_ERROR', 'amount', 400);
        
        $this -> asb -> assetIdToSymbol([
            'assetid' => @$body['assetid']
        ]);
        
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
            throw new Error('INSUF_BALANCE', 'Insufficient account balance', 400);
        }
        
        $this -> wlog -> insert(
            $this -> pdo,
            'DEBIT',
            null,
            $body['uid'],
            $body['assetid'],
            $body['amount'],
            $body['reason'],
            @$body['context']
        );
        
        $this -> pdo -> commit();
    }
}

?>