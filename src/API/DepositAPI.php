<?php

use Infinex\Exceptions\Error;

class DepositAPI {
    private $log;
    private $pdo;
    private $an;
    
    function __construct($log, $pdo, $an) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized deposit API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/deposit/{asset}/{network}', [$this, 'deposit']);
    }
    
    public function deposit($path, $query, $body, $auth) {
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
                       block_deposits_msg,
                       EXTRACT(epoch FROM last_ping) AS last_ping
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
        
        // Get / set address
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':netid' => $pairing['netid'],
            ':uid' => $auth['uid']
        ];
        
        $sql = 'SELECT address,
                       memo
                FROM deposit_addr
                WHERE uid = :uid
                AND netid = :netid
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoAddr = $q -> fetch();
        
        if(!$infoAddr) {
            $task = [
                ':netid' => $pairing['netid'],
                ':uid' => $auth['uid']
            ];
            
            $sql = 'UPDATE deposit_addr
                    SET uid = :uid
                    WHERE addrid IN (
                        SELECT addrid
                        FROM deposit_addr
                        WHERE netid = :netid
                        AND uid IS NULL
                        LIMIT 1
                    )
                    RETURNING address,
                              memo';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoAddr = $q -> fetch();
            
            if(!$infoAddr) {
                $this -> pdo -> rollBack();
                throw new Error('ASSIGN_ADDR_FAILED', 'Unable to assign new deposit address. Please try again later.', 500);
            }
        }
        
        $this -> pdo -> commit();
        
        $operating = time() - intval($infoNet['last_ping']) <= 5 * 60;
                
        $resp = [
            'confirmTarget' => $infoNet['confirms_target'],
            'memoName' => null,
            'memo' => null,
            'qrCode' => null,
            'warnings' => [],
            'operating' => $operating,
            'contract' => $infoAn['contract'],
            'address' => $infoAddr['address'],
            'memo' => null,
            'minAmount' => $this -> an -> getMinDepositAmount($pairing['assetid'], $pairing['netid'])
        ];
        
        // Memo only if both set
        if($infoNet['memo_name'] !== null && $infoAddr['memo'] !== null) {
            $resp['memoName'] = $infoNet['memo_name'];
            $resp['memo'] = $infoAddr['memo'];
        }
        
        // Qr code for native
        if($infoAn['contract'] === NULL && $infoNet['native_qr_format'] !== NULL) {
            $qrContent = $infoNet['native_qr_format'];
            $qrContent = str_replace('{{ADDRESS}}', $infoAddr['address'], $qrContent);
            $qrContent = str_replace('{{MEMO}}', $infoAddr['memo'], $qrContent);
            $resp['qrCode'] = $qrContent;
        }
        
        // Qr code for token
        else if($infoAn['contract'] !== NULL && $infoNet['token_qr_format'] !== NULL) {
            $qrContent = $infoNet['token_qr_format'];
            $qrContent = str_replace('{{ADDRESS}}', $infoAddr['address'], $qrContent);
            $qrContent = str_replace('{{MEMO}}', $infoAddr['memo'], $qrContent);
            $qrContent = str_replace('{{CONTRACT}}', $infoAn['contract'], $qrContent);
            $resp['qrCode'] = $qrContent;
        }
        
        // Warnings
        if($infoNet['deposit_warning'] !== null)
            $resp['warnings'][] = $infoNet['deposit_warning'];
        if($infoAn['deposit_warning'] !== null)
            $resp['warnings'][] = $infoAn['deposit_warning'];
        
        return $resp;
    }
}

?>