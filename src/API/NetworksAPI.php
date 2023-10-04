<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;

class NetworksAPI {
    private $log;
    private $pdo;
    private $networks;
    private $assets;
    
    function __construct($log, $pdo, $networks, $assets) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        $this -> assets = $assets;
        
        $this -> log -> debug('Initialized networks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets/{asset}/networks', [$this, 'getNetworksOfAsset']);
        $rc -> get('/assets/{asset}/networks/{network}', [$this, 'getNetworkOfAsset']);
    }
    
    public function getNetworksOfAsset($path, $query, $body, $auth) {
        $assetid = $this -> assets -> symbolToAssetId($path['asset']);
        
        $pag = new Pagination\Offset(50, 500, $query);
        
        $task = [
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       EXTRACT(epoch FROM networks.last_ping) AS last_ping,
                       assets.icon_url
                FROM networks,
                     asset_network,
                     assets
                WHERE asset_network.netid = networks.netid
                AND asset_network.assetid = :assetid
                AND assets.assetid = networks.native_assetid
                ORDER BY networks.netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $networks = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $networks[] = $this -> rowToRespItem($row);
        }
        
        return [
            'networks' => $networks,
            'more' => $pag -> more
        ];
    }
    
    public function getNetworkOfAsset($path, $query, $body, $auth) {
        $assetid = $this -> assets -> symbolToAssetId($path['asset']);
        $netid = $this -> networks -> symbolToNetId($path['network']);
        
        $task = [
            ':assetid' => $assetid,
            ':netid' => $netid
        ];
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       EXTRACT(epoch FROM networks.last_ping) AS last_ping,
                       assets.icon_url
                FROM networks,
                     asset_network,
                     assets
                WHERE asset_network.netid = networks.netid
                AND asset_network.assetid = :assetid
                AND asset_network.netid = :netid
                AND assets.assetid = networks.native_assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', $path['network'].' is not a valid network for '.$path['asset'], 404);
        
        return $this -> rowToRespItem($row);
    }
    
    private function rowToRespItem($row) {
        $operating = time() - intval($row['last_ping']) <= 5 * 60;
            
        return [
            'symbol' => $row['netid'],
            'name' => $row['description'],
            'iconUrl' => $row['icon_url'],
            'operating' => $operating
        ];
    }
}

?>