<?php

class WalletLog {
    private $log;
    
    function __construct($log) {
        $this -> log = $log;
        
        $this -> log -> debug('Initialized wallet log');
    }
    
    public function insert(
        $tPdo,
        $op,
        $lockid,
        $uid,
        $assetid,
        $amount,
        $reason,
        $context
    ) {
        $task = array(
            ':operation' => $op,
            ':lockid' => $lockid,
            ':uid' => $uid,
            ':assetid' => $assetid,
            ':amount' => $amount,
            ':reason' => $reason,
            ':context' => $context
        );
        
        $sql = "INSERT INTO wallet_log(
                    operation,
                    lockid,
                    uid,
                    assetid,
                    amount,
                    reason,
                    context
                )
                VALUES(
                    :operation,
                    :lockid,
                    :uid,
                    :assetid,
                    :amount,
                    :reason,
                    :context
                )";
        
        $q = $tPdo -> prepare($sql);
        $q -> execute($task);
    }
}