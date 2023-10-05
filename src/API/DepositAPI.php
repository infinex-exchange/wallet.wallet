<?php

use Infinex\Exceptions\Error;

class DepositAPI {
    private $log;
    private $pdo;
    private $an;
    
    function __construct($log, $amqp, $pdo, $an) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized deposit API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/deposit/{asset}/{network}', [$this, 'deposit']);
    }
    
    public function deposit($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $pairing = $this -> an -> resolveAssetNetworkPair($path['asset'], $path['network'], false);
        
        // Get network details
        
        $task = [
            ':netid' => $pairing['netid']
        ];
        
        $sql = 'SELECT confirms_target,
                       memo_name,
                       native_qr_format,
                       token_qr_format,
                       deposit_warning,
                       block_deposits_msg
                FROM networks
                WHERE netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoNet = $q -> fetch();
        
        if($infoNet['block_deposits_msg'] !== null)
            throw new Error('FORBIDDEN', $infoNet['block_deposits_msg'], 403);
        
        // Get asset_network details
        
        $task = [
            ':assetid' => $pairing['assetid'],
            ':netid' => $pairing['netid']
        ];
        
        $sql = 'SELECT contract,
                       deposit_warning,
                       block_deposits_msg
                FROM asset_network
                WHERE assetid = :assetid
                AND netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoAn = $q -> fetch();
        
        if($infoAn['block_deposits_msg'] !== null)
            throw new Error('FORBIDDEN', $infoAn['block_deposits_msg'], 403);
        
        // Get minimal amount
        
        $minAmount = $this -> an -> getMinDepositAmount($pairing['assetid'], $pairing['netid']);
        
        // Get info from wallet.io
        
        return $this -> amqp -> call(
            'wallet.io',
            'getDepositContext',
            [
                'uid' => $auth['uid'],
                'netid' => $pairing['netid']
            ]
        ) -> then(function($infoIo) use($infoNet, $infoAn, $minAmount) {
            // Prepare response
                
            $resp = [
                'confirmTarget' => $infoNet['confirms_target'],
                'memoName' => null,
                'memo' => null,
                'qrCode' => null,
                'warnings' => [],
                'operating' => $infoIo['operating'],
                'contract' => $infoAn['contract'],
                'address' => $infoIo['address'],
                'minAmount' => $minAmount
            ];
            
            // Memo only if both set
            if($infoNet['memo_name'] !== null && $infoIo['memo'] !== null) {
                $resp['memoName'] = $infoNet['memo_name'];
                $resp['memo'] = $infoIo['memo'];
            }
            
            // Qr code for native
            if($infoAn['contract'] === NULL && $infoNet['native_qr_format'] !== NULL) {
                $qrContent = $infoNet['native_qr_format'];
                $qrContent = str_replace('{{ADDRESS}}', $infoIo['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $infoIo['memo'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Qr code for token
            else if($infoAn['contract'] !== NULL && $infoNet['token_qr_format'] !== NULL) {
                $qrContent = $infoNet['token_qr_format'];
                $qrContent = str_replace('{{ADDRESS}}', $infoIo['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $infoIo['memo'], $qrContent);
                $qrContent = str_replace('{{CONTRACT}}', $infoAn['contract'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Warnings
            if($infoNet['deposit_warning'] !== null)
                $resp['warnings'][] = $infoNet['deposit_warning'];
            if($infoAn['deposit_warning'] !== null)
                $resp['warnings'][] = $infoAn['deposit_warning'];
            $resp['warnings'] = array_merge($resp['warnings'], $infoIo['warnings']);
            
            return $resp;
        });
    }
}

?>