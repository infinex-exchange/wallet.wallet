<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;

class AssetsAPI {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized assets API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/assets', [$this, 'getAllAssets']);
    }
    
    public function getAllAssets($path, $query, $body, $auth) {
        /*$pag = new Pagination\Offset(50, 500, $query);
        
        $task = [];
        
        $sql = "SELECT sid,
                       wa_remember,
                       EXTRACT(epoch FROM wa_lastact) AS wa_lastact,
                       wa_browser,
                       wa_os,
                       wa_device
               FROM sessions
               WHERE uid = :uid
               AND origin = 'WEBAPP'
               ORDER BY sid DESC"
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $sessions = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            
            $sessions[] = [
                'sid' => $row['sid'],
                'lastAct' => $row['wa_lastact'] ? intval($row['wa_lastact']) : null,
                'browser' => $row['wa_browser'],
                'os' => $row['wa_os'],
                'device' => $row['wa_device'],
                'current' => ($auth['sid'] == $row['sid']) 
            ];
        }
        
        return [
            'sessions' => $sessions,
            'more' => $pag -> more
        ];*/
    }
}

?>