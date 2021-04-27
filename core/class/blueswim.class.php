<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../core/php/blueswim.inc.php';
use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class blueswim extends eqLogic {

    private static $_eqLogics = null;
    private static $_credentials = null;
    /*     * *************************Attributs****************************** */
    
  /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
	public static $_widgetPossibility = array();
   */
    
    /*     * ***********************Methode static*************************** */

    /*
     * Fonction exécutée automatiquement toutes les minutes par Jeedom
      public static function cron() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
      public static function cron5() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
      public static function cron10() {
      }
     */
    
    /*
     * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
     * 
     */
    public static function cron15() {
            foreach (eqLogic::byType('blueswim', true) as $eqLogic) {
                    $eqLogic->updateInfo();
            }
    }

    
    /*
     * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
      public static function cron30() {
      }
     */
    
    /*
     * Fonction exécutée automatiquement toutes les heures par Jeedom
      public static function cronHourly() {
      }
     */

    /*
     * Fonction exécutée automatiquement tous les jours par Jeedom
      public static function cronDaily() {
      }
     */
    public static function login($force=false) {
        if((self::$_credentials != null) && (!$force)) {
            log::add('blueswim', 'info', "Utilisation du cache d'authentification");
            return self::$_credentials;
        }
        $request_http = new com_http('https://api.riiotlabs.com/prod/user/login');
        $email = config::byKey('EMAIL', 'blueswim');
        $pass = config::byKey('PASSWORD', 'blueswim');
        log::add('blueswim', 'debug', "Tentative d'authentification");
        $request_http->setPost(json_encode(array("email" => $email, "password" => $pass)));
        try {
            $response = $request_http->exec();
        } catch (Exception $e) {
            log::add('blueswim', 'error', "Impossible de s'identifier".$e->getMessage());
            return false;
        }
        $secrets = json_decode($response, true);
        if(!isset($secrets["credentials"]["access_key"]) || !isset($secrets["credentials"]["secret_key"]) || !isset($secrets["credentials"]["session_token"])) {
            log::add('blueswim', 'error', "Impossible de s'identifier");
            return false;
        }
        self::$_credentials = new Credentials($secrets["credentials"]["access_key"], $secrets["credentials"]["secret_key"], $secrets["credentials"]["session_token"]);
        log::add('blueswim', 'info', "Authentification réussie: ".self::$_credentials->serialize());   
        return self::$_credentials;
    }
    
    public static function sync() {
        $blues_list = array();

        $credentials = self::login();
        if($credentials === false) return false;
        
        $client = new Client();
        $s4 = new SignatureV4("execute-api", 'eu-west-1');

        $request = new Request('GET', "https://api.riiotlabs.com/prod/swimming_pool?deleted=false");
        $signedrequest = $s4->signRequest($request, $credentials);
        $response = $client->send($signedrequest);
        log::add('blueswim', 'debug', "Liste des piscines: ".$response->getBody());
        $swimming_pools = json_decode($response->getBody(), true);
        foreach($swimming_pools["data"] as $swimming_pool) {
            $request = new Request('GET', "https://api.riiotlabs.com/prod/swimming_pool/" . $swimming_pool["swimming_pool_id"] . "/blue");
            $signedrequest = $s4->signRequest($request, $credentials);
            $response = $client->send($signedrequest);
            log::add('blueswim', 'debug', "Liste des équipements: ".$response->getBody());
            $blues = json_decode($response->getBody(), true);
            foreach($blues["data"] as $blue) {
                $blueId = $swimming_pool["name"]."-".$blue["blue_device_serial"];
                $obj_blue = blueswim::byLogicalId($blueId, 'blueswim');
                if (!is_object($obj_blue)) {
                    $eqLogic = new blueswim();
                    $eqLogic->setName($blueId);
                    $eqLogic->setLogicalId($blueId);
                    $eqLogic->setEqType_name("blueswim");
                    if($blue["blue_device"]["hw_type"] == "go") {
                        if($blue["blue_device"]["contract_servicePlan"] == "plus") {
                            $eqLogic->setConfiguration('device','bluego_premium');
                        } else {
                            $eqLogic->setConfiguration('device','bluego');
                        }
                    }
                    else {
                        $eqLogic->setConfiguration('device','blueplus');
                    }
                    $eqLogic->setConfiguration('contrat',$blue["blue_device"]["contract_servicePlan"]);
                    $eqLogic->setConfiguration('firmware',$blue["blue_device"]["fw_version_psoc"]);
                    $eqLogic->setConfiguration('swimming_pool_id',$swimming_pool["swimming_pool_id"]);
                    $eqLogic->setConfiguration('blue_serial',$blue["blue_device_serial"]);
                    $eqLogic->setStatus('lastCommunication',strtotime($blue["blue_device"]["last_measure_message"]));
                    $eqLogic->setIsEnable(1);
                    $eqLogic->setIsVisible(1);
                    $eqLogic->save();
                    $blues_list[] = $eqLogic;
                    log::add('blueswim', 'info', "Ajout d'un équipement: ".$blueId);
                    event::add('jeedom::alert', array(
                        'level' => 'warning',
                        'page' => 'blueswim',
                        'message' => __('Blue Connect '.$blueId.' ajouté avec succès', __FILE__),
                    ));
                }
            }

            event::add('jeedom::alert', array(
                'level' => 'warning',
                'page' => 'blueswim',
                'message' => __('Synchronisation blueswim terminée', __FILE__),
            ));
        }

        return $blues_list;
    }

    /*     * *********************Méthodes d'instance************************* */


    public function getImage() {
        return 'plugins/blueswim/core/config/devices/' . $this->getConfiguration('device') . '/' . $this->getConfiguration('device') . '.jpg';
    }

 // Fonction exécutée automatiquement avant la création de l'équipement 
    public function preInsert() {
        
    }

 // Fonction exécutée automatiquement après la création de l'équipement 
    public function postInsert() {
        
    }

 // Fonction exécutée automatiquement avant la mise à jour de l'équipement 
    public function preUpdate() {
        
    }

 // Fonction exécutée automatiquement après la mise à jour de l'équipement 
    public function postUpdate() {
        
    }

 // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement 
    public function preSave() {
        
    }

 // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement 
    public function postSave() {
        $cmd = $this->getCmd(null, 'temperature');
        if (!is_object($cmd)) {
                $cmd = new blueswimCmd();
                $cmd->setLogicalId('temperature');
                $cmd->setUnite('°C');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setName(__('Temperature', __FILE__));
                $cmd->setConfiguration('minValue' , '0');
                $cmd->setConfiguration('maxValue' , '50');
        }
        $cmd->setType('info');
        $cmd->setSubType('numeric');
        $cmd->setEqLogic_id($this->getId());
        $cmd->setDisplay('generic_type', 'GENERIC');
        $cmd->save();
        
        $cmd = $this->getCmd(null, 'ph');
        if (!is_object($cmd)) {
                $cmd = new blueswimCmd();
                $cmd->setLogicalId('ph');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setName(__('Ph', __FILE__));
                $cmd->setConfiguration('minValue' , '5');
                $cmd->setConfiguration('maxValue' , '10');
        }
        $cmd->setType('info');
        $cmd->setSubType('numeric');
        $cmd->setEqLogic_id($this->getId());
        $cmd->setDisplay('generic_type', 'GENERIC');
        $cmd->save();
        
        $cmd = $this->getCmd(null, 'orp');
        if (!is_object($cmd)) {
                $cmd = new blueswimCmd();
                $cmd->setLogicalId('orp');
                $cmd->setUnite('mV');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setName(__('Redox', __FILE__));
                $cmd->setConfiguration('minValue' , '200');
                $cmd->setConfiguration('maxValue' , '1000');
        }
        $cmd->setType('info');
        $cmd->setSubType('numeric');
        $cmd->setEqLogic_id($this->getId());
        $cmd->setDisplay('generic_type', 'GENERIC');
        $cmd->save();
        
        $cmd = $this->getCmd(null, 'refresh');
        if (!is_object($cmd)) {
                $cmd = new blueswimCmd();
                $cmd->setLogicalId('refresh');
                $cmd->setIsVisible(1);
                $cmd->setName(__('Refresh', __FILE__));
        }
        $cmd->setType('action');
        $cmd->setSubType('other');
        $cmd->setEqLogic_id($this->getId());
        $cmd->setDisplay('generic_type', 'GENERIC');
        $cmd->save();        
        
        $this->updateInfo();
    }

 // Fonction exécutée automatiquement avant la suppression de l'équipement 
    public function preRemove() {
        
    }

 // Fonction exécutée automatiquement après la suppression de l'équipement 
    public function postRemove() {
        
    }

    /*
     * Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
      public function toHtml($_version = 'dashboard') {

      }
     */

    /*
     * Non obligatoire : permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    }
     */

    /*
     * Non obligatoire : permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    }
     */
    
    public static function updateInfo($_eqLogic_id = null, $_cache = null) {
        if (self::$_eqLogics == null) {
            self::$_eqLogics = self::byType('blueswim',true);
        }
        if ($_cache != null) {
            $cache = $_cache;
        }
        foreach (self::$_eqLogics as $blueswim) {
            if ($_eqLogic_id != null && $_eqLogic_id != $blueswim->getId()) {
                    continue;
            }

            $client = new Client();
            $s4 = new SignatureV4("execute-api", 'eu-west-1');
            $request = new Request('GET', "https://api.riiotlabs.com/prod/swimming_pool/" . $blueswim->getConfiguration('swimming_pool_id') . "/blue/" . $blueswim->getConfiguration('blue_serial') . "/lastMeasurements?mode=blue_and_strip");
            $retry = 2;
            $force = false;
            while($retry > 0) {
                $credentials = self::login($force);
                if($credentials === false) return false;
                $signedrequest = $s4->signRequest($request, $credentials);
                $response = $client->send($signedrequest);
                if($response->getStatusCode() == 200) break;
                log::add('blueswim', 'info', "Code erreur ".$response->getStatusCode()." à la récupération des données. Retry.");
                $force = true;
                $retry--;
            }
            if($response->getStatusCode() != 200) {
                log::add('blueswim', 'error', "Code erreur ".$response->getStatusCode()." à la récupération des données.");
                return false;
            }       
            log::add('blueswim', 'info', "Récupération des dernières mesures");
            log::add('blueswim', 'debug', "updateInfo:".$response->getBody());
            $lastMeasures = json_decode($response->getBody(), true);

            foreach($lastMeasures['data'] as $lastMeasure) {
                if( ($lastMeasure['name'] == 'temperature') && ($lastMeasure['issuer'] != 'strip') ) {
                        $blueswim->checkAndUpdateCmd('temperature', $lastMeasure['value'],date('Y-m-d H:i:s',strtotime($lastMeasure['timestamp'])));
                        log::add('blueswim', 'info', "temperature: ".$lastMeasure['value']." en date du ".$lastMeasure['timestamp']);
                }
                if( ($lastMeasure['name'] == 'ph') && ($lastMeasure['issuer'] != 'strip') ) {
                        $blueswim->checkAndUpdateCmd('ph', $lastMeasure['value'],date('Y-m-d H:i:s',strtotime($lastMeasure['timestamp'])));
                        log::add('blueswim', 'info', "ph: ".$lastMeasure['value']." en date du ".$lastMeasure['timestamp']);
                }
                if( ($lastMeasure['name'] == 'orp') && ($lastMeasure['issuer'] != 'strip') ) {
                        $blueswim->checkAndUpdateCmd('orp', $lastMeasure['value'],date('Y-m-d H:i:s',strtotime($lastMeasure['timestamp'])));
                        log::add('blueswim', 'info', "orp: ".$lastMeasure['value']." en date du ".$lastMeasure['timestamp']);
                }
            }
        }
    }

    /*     * **********************Getteur Setteur*************************** */
}

class blueswimCmd extends cmd {
    /*     * *************************Attributs****************************** */
    
    /*
      public static $_widgetPossibility = array();
    */
    
    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
      public function dontRemoveCmd() {
      return true;
      }
     */

  // Exécution d'une commande  
     public function execute($_options = array()) {
        if ($this->getLogicalId() == 'refresh') {
            blueswim::updateInfo($this->getEqLogic_Id());
            return;
        }
     }

    /*     * **********************Getteur Setteur*************************** */
}


