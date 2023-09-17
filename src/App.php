<?php

require __DIR__.'/LockManager.php';
require __DIR__.'/CreditDebit.php';

class App extends Infinex\App\Daemon {
    private $pdo;
    private $lm;
    private $cd;
    
    function __construct() {
        parent::__construct('wallet.walletd');
        
        $this -> pdo = new Infinex\Database\PDO($this -> loop, $this -> log);
        $this -> pdo -> start();
        
        $this -> lm = new LockManager($this -> log, $this -> pdo);
        $this -> cd = new CreditDebit($this -> log, $this -> pdo);
        
        $th = $this;
        $this -> amqp -> on('connect', function() use($th) {
            $th -> lm -> bind($th -> amqp);
            $th -> cd -> bind($th -> amqp);
        });
    }
}

?>