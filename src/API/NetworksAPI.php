<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;

class NetworksAPI {
    private $log;
    private $pdo;
    private $assets;
    private $an;
    
    function __construct($log, $pdo, $assets, $an) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> assets = $assets;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized networks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets/{asset}/networks', [$this, 'getNetworksOfAsset']);
        $rc -> get('/assets/{asset}/networks/{network}', [$this, 'getNetworkOfAsset']);
    }
    
    public function getNetworksOfAsset($path, $query, $body, $auth) {
        $assetid = $this -> assets -> symbolToAssetId($path['asset'], false);
        
        $pag = new Pagination\Offset(50, 500, $query);
        
        $task = [
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       assets.icon_url
                FROM networks,
                     asset_network,
                     assets
                WHERE networks.enabled = TRUE
                AND asset_network.netid = networks.netid
                AND asset_network.assetid = :assetid
                AND asset_network.enabled = TRUE
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
        $pairing = $this -> an -> resolveAssetNetworkPair($path['asset'], $path['network'], false);
        
        $task = [
            ':netid' => $pairing['netid']
        ];
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       assets.icon_url
                FROM networks,
                     assets
                WHERE networks.netid = :netid
                AND assets.assetid = networks.native_assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return $this -> rowToRespItem($row);
    }
    
    private function rowToRespItem($row) {
        return [
            'symbol' => $row['netid'],
            'name' => $row['description'],
            'iconUrl' => $row['icon_url']
        ];
    }
}

?>