<?php

require __DIR__.'/AssetsBalances.php';
require __DIR__.'/WalletLog.php';
require __DIR__.'/CreditDebit.php';
require __DIR__.'/LockMgr.php';

require __DIR__.'/API/AssetsBalancesAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $asb;
    private $wlog;
    private $cd;
    private $locks;
    
    private $asbApi;
    private $rest;
    
    function __construct() {
        parent::__construct('wallet.wallet');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> asb = new AssetsBalances(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> wlog = new WalletLog(
            $this -> log
        );
        
        $this -> cd = new CreditDebit(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> asb,
            $this -> wlog
        );
       
        $this -> lockMgr = new LockMgr(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> asb,
            $this -> wlog
        );
        
        $this -> asbApi = new AssetsBalancesAPI(
            $this -> log,
            $this -> asb
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> asbApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> asb -> start();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> cd -> start(),
                    $th -> lockMgr -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> rest -> stop() -> then(
            function() use($th) {
                return Promise\all([
                    $th -> cd -> stop(),
                    $th -> lockMgr -> stop()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> asb -> stop();
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>