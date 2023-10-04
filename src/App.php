<?php

require __DIR__.'/WalletLog.php';
require __DIR__.'/Assets.php';
require __DIR__.'/Networks.php';
require __DIR__.'/AssetNetwork.php';
require __DIR__.'/CreditDebit.php';
require __DIR__.'/LockMgr.php';

require __DIR__.'/API/AssetsBalancesAPI.php';
require __DIR__.'/API/NetworksAPI.php';
require __DIR__.'/API/DepositAPI.php';
require __DIR__.'/API/WithdrawalAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $wlog;
    private $assets;
    private $networks;
    private $an;
    private $cd;
    private $locks;
    
    private $asbApi;
    private $networksApi;
    private $depositApi;
    private $withdrawalApi;
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
        
        $this -> wlog = new WalletLog(
            $this -> log
        );
        
        $this -> assets = new Assets(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> networks = new Networks(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> an = new AssetNetwork(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> assets,
            $this -> networks
        );
        
        $this -> cd = new CreditDebit(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> wlog
        );
        
        $this -> lockMgr = new LockMgr(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> wlog
        );
        
        $this -> asbApi = new AssetsBalancesAPI(
            $this -> log,
            $this -> pdo,
            $this -> assets
        );
        
        $this -> networksApi = new NetworksAPI(
            $this -> log,
            $this -> pdo,
            $this -> assets,
            $this -> an
        );
        
        $this -> depositApi = new DepositAPI(
            $this -> log,
            $this -> pdo,
            $this -> an
        );
        
        $this -> withdrawalApi = new WithdrawalAPI(
            $this -> log,
            $this -> pdo,
            $this -> an
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> asbApi,
                $this -> networksApi,
                $this -> depositApi 
                $this -> withdrawalApi
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
                return Promise\all([
                    $th -> assets -> start(),
                    $th -> networks -> start(),
                    $th -> cd -> start(),
                    $th -> lockMgr -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> an -> start();
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
                return $th -> an -> stop();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> assets -> stop(),
                    $th -> networks -> stop(),
                    $th -> cd -> stop(),
                    $th -> lockMgr -> stop(),
                ]);
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