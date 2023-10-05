<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;
use Decimal\Decimal;

class WithdrawalAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $an;
    private $networks;
    
    function __construct($log, $amqp, $pdo, $an, $networks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> an = $an;
        $this -> networks = $networks;
        
        $this -> log -> debug('Initialized withdrawal API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/withdrawal/{network}/{asset}', [$this, 'preflight']);
        $rc -> post('/withdrawal/{network}', [$this, 'validate']);
    }
    
    public function preflight($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $pairing = $this -> an -> resolveAssetNetworkPair($path['asset'], $path['network'], false);
        
        // Get network details
        
        $task = [
            ':netid' => $pairing['netid']
        ];
        
        $sql = 'SELECT memo_name,
                       withdrawal_warning,
                       block_withdrawals_msg
                FROM networks
                WHERE netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoNet = $q -> fetch();
        
        if($infoNet['block_withdrawals_msg'] !== null)
            throw new Error('FORBIDDEN', $infoNet['block_withdrawals_msg'], 403);
        
        // Get asset_network details
        
        $task = [
            ':assetid' => $pairing['assetid'],
            ':netid' => $pairing['netid']
        ];
        
        $sql = 'SELECT contract,
                       prec,
                       wd_fee_base,
                       wd_fee_min,
                       wd_fee_max,
                       withdrawal_warning,
                       block_withdrawals_msg
                FROM asset_network
                WHERE assetid = :assetid
                AND netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoAn = $q -> fetch();
        
        if($infoAn['block_withdrawals_msg'] !== null)
            throw new Error('FORBIDDEN', $infoAn['block_withdrawals_msg'], 403);
        
        // Get min amount
        
        $minAmount = $this -> an -> getMinWithdrawalAmount($pairing['assetid'], $pairing['netid']);
        
        // Get info from wallet.io
        
        return $this -> amqp -> call(
            'wallet.io',
            'getWithdrawalContext',
            [
                'netid' => $pairing['netid']
            ]
        ) -> then(function($infoIo) use($infoNet, $infoAn, $minAmount) {
            // Prepare response
        
            $dFeeBase = new Decimal($infoAn['wd_fee_base']);
            
            $dFeeMin = new Decimal($infoAn['wd_fee_min']);
            $dFeeMin += $dFeeBase;
            
            $dFeeMax = new Decimal($infoAn['wd_fee_max']);
            $dFeeMax += $dFeeBase;
                    
            $resp = [
                'memoName' => $infoNet['memo_name'],
                'warnings' => [],
                'operating' => $infoIo['operating'],
                'contract' => $infoAn['contract'],
                'minAmount' => $minAmount,
                'prec' => $infoAn['prec'],
                'feeMin' => trimFloat($dFeeMin -> toFixed($infoAn['prec'])),
                'feeMax' => trimFloat($dFeeMax -> toFixed($infoAn['prec']))
            ];
            
            // Warnings
            if($infoNet['withdrawal_warning'] !== null)
                $resp['warnings'][] = $infoNet['withdrawal_warning'];
            if($infoAn['withdrawal_warning'] !== null)
                $resp['warnings'][] = $infoAn['withdrawal_warning'];
            
            return $resp;
        });
    }
    
    public function validate($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $netid = $this -> networks -> symbolToNetId($path['network'], false);
        
        if(!isset($body['address']) && !isset($body['memo']))
            throw new Error('MISSING_DATA', 'At least one is required: address or memo', 400);
        
        return $this -> amqp -> call(
            'wallet.io',
            'validateWithdrawalTarget',
            [
                'address' => isset($body['address']) ? $body['addresss'] : null,
                'memo' => isset($body['memo']) ? $body['memo'] : null
            ]
        );
    }
}

?>