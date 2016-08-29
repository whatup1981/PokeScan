<?php
require_once(__DIR__."/PokeData.inc");
date_default_timezone_set('asia/taipei');
class PokeScan {
    private $_opts;
    private $_debug;
    private $_latitude = "-25.057352";
    private $_longitude = "-121.614871";
    private $_server;
    private $_roomId;
    private $_commonPokemonIds = array();
    private $_rarePokemonIds = array();
    private $_token;
    private $_scanResult;
    private $_pokeData;
    function __construct($opts, $pokeData) {
        $this->_opts = $opts;
        $this->_pokeData = $pokeData;
    }

    function run() {
        $opts = $this->_opts;
        if (isset($opts['d'])) {
            $this->_debug = true;
            $this->_log('DEBUG', 'enable Debug');
        }
        $this->_log('DEBUG', var_export($opts,true));
        if (!sizeof($opts) || isset($opts['h'])) {
            $this->help();
            return;
        }

        if (isset($opts['l'])) {
            $location = explode(",", $opts['l']);
            $this->_latitude = $location[0];
            $this->_longitude = $location[1];
        }

        if (isset($opts['i'])) {
            $this->_roomId = $opts['i'];
        }

        if (isset($opts['s'])) {
            $this->_server = $opts['s'];
        }

        if (isset($opts['t'])) {
            $this->_token = $opts['t'];
        }

        if (isset($opts['u'])) {
            $this->_commonPokemonIds = explode(",", $opts['u']);
        }

        if (isset($opt['r'])) {
            $this->_rarePokemonIds = explode(",", $opts['r']);
        }
        $this->_log('DEBUG', print_r($this,true));
        $this->_scan();
        $this->_parseResult();

    }
    private function _log($type = 'NOTICE' , $msg = '') {
        if ($type == 'DEBUG' && $this->_debug == false) {
            return;
        }
        $d = date("Y/m/d H:m", time());
        error_log(sprintf("[%s] %s : %s", $type, $d, $msg));
    }

    function sendHipchat($msg)
    {
        $auth = 'auth_token='.$this->_token;
        $serviceUrl = 'https://yahoo.hipchat.com';
        $ch = curl_init();
        $url = $this->_server . "/v2/room/{$this->_roomId}/notification?$auth";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($msg));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $this->_log('DEBUG', "url: $url , msg => ". json_encode($msg));
        if (!$this->_debug) {
            $ret = curl_exec($ch);
        }



    }
    function help() {
        echo <<<DOC
php PokeScan.php [-h] [-d] [-L] [-l latitude,longitude] [-u 1,2] [-r 3,4,5] [-s hiptchat server name] [-i room id] [-t token]
    -h Help page
    -d Debug message
    -L
    -l latitude,longitude, ex 25.057352,121.614871
    -u common pokemon ids, split by comma. ex: 16,17
    -r rare pokemon ids, split by comma. ex: 19,13
    -i hipchat room id
    -t hipchat token
    -s hipchat server url
DOC;
    }

    function _parseResult() {
        $uniq = array();
        $canDelete = true;
        $hasCommonPokemonIds = count($this->_commonPokemonIds) ? true : false;
        $hasRarePokemonIds = count($this->_rarePokemonIds) ? true : false;
        foreach ($this->_scanResult['data'] as $pokemon) {
            if ((!$hasRarePokemonIds && !$hasCommonPokemonIds) ||
                ($hasCommonPokemonIds && !in_array($pokemon['pokemonId'], $this->_commonPokemonIds)) ||
                ($hasRarePokemonIds && in_array($pokemon['pokemonId'], $this->_rarePokemonIds))) {


                if ($pokemon['trainerName'] != '(Poke Radar Prediction)') continue;
                if (isset($uniq[$pokemon['created'].'-'.$pokemon['pokemonId']]))  continue;
                if (!isset($this->_pokeData[$pokemon['pokemonId']])) continue;
                $this->_log("DEBUG", print_r($pokemon, true));
                $canDelete = false;
                $id = $pokemon['id'];
                if (file_exists('/tmp/data/'.$id)) {
                    $msg = sprintf("發過了 - 有 %s - %d", $this->_pokeData[$pokemon['pokemonId']], $pokemon['pokemonId']);
                    $this->_log('NOTICE', $msg);

                    continue;
                }
                error_log(time(), 3, '/tmp/data/'.$id);
                $uniq[$pokemon['created'].'-'.$pokemon['pokemonId']] = 1;
                $distance  = $this->_distance($this->_latitude, $this->_longitude, $pokemon['latitude'], $pokemon['longitude'], "M");
                $t = $pokemon["created"]+15*60 - time();

                $map = "https://www.google.com.tw/maps?q=loc:{$pokemon['latitude']},{$pokemon['longitude']}";
                $msg = sprintf("有 %s - %d, 距離 %d公尺, 剩下 %d 秒, %s", $this->_pokeData[$pokemon['pokemonId']], $pokemon['pokemonId'], $distance, $t, $map);
                $this->_log('NOTICE', $msg);
                if ($t < 60) continue;
                $msg = $this->_buildMsg($pokemon, $map);
                $this->sendHipchat($msg);

            }
        }
          $this->_deleteLog($canDelete);
        }


    function _deleteLog($canDelete = false) {
      if ($canDelete) {
          $files = glob('/tmp/data/*');
          if (sizeof($files) > 0) {
              $this->_log('DEBUG', 'clean log');
          }
          foreach($files as $file){
              if(is_file($file)){
                  unlink($file);
              }
          }
    }
  }
  function _buildMsg($pokemon, $map) {
        $msg = array();
        $msg['from'] = 'Bot';
        $msg['color'] = 'red';
        $msg['notify'] = true;
        $st = time();
        $t = $pokemon["created"]+15*60 - time();
        $id = microtime(true);
        $img = "http://veekun.com/dex/media/pokemon/main-sprites/omegaruby-alphasapphire/{$pokemon['pokemonId']}.png";
        $distance  = $this->_distance($this->_latitude, $this->_longitude, $pokemon['latitude'], $pokemon['longitude'], "M");
        $str = sprintf(" 距離 %d 公尺, 剩下 %d 秒, ", $distance, $t);
        $card = <<<DOC
    {
      "style": "link",
      "url": "$map",
      "id": "$id",
      "title": "{$this->_pokeData[$pokemon['pokemonId']]}",
      "description": "$str",
      "date": {$st}000,
      "thumbnail": {
        "url": "$img"
      }
    }
DOC;
        $msg['card'] = json_decode($card, true);
        $msg['message'] = {$this->_pokeData[$pokemon['pokemonId']]} .' - '.$str;
        return $msg;

  }
    function _scan() {
      $minLatitude = $this->_latitude - 0.003;
      $maxLatitude = $this->_latitude + 0.003;
      $minLongitude = $this->_longitude - 0.004;
      $maxLongitude = $this->_longitude + 0.004;


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.pokeradar.io/api/v1/submissions?deviceId=b4e86700643611e6959339917e5b2c63&minLatitude={$minLatitude}&maxLatitude={$maxLatitude}&minLongitude={$minLongitude}&maxLongitude={$maxLongitude}&pokemonId=0");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        $headers = array();
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'Origin: https://www.pokemonradargo.com';
        $headers[] = 'Accept-Encoding: gzip, deflate, sdch, br';
        $headers[] = 'Accept-Language: zh-TW,zh;q=0.8,en-US;q=0.6,en;q=0.4';
        $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36';
        $headers[] = 'Accept: https://df48mbt4ll5mz.cloudfront.net/application/json, text/javascript, */*; q=0.01';
        $headers[] = 'Referer: https://www.pokemonradargo.com/';
        $headers[] = 'Connection: keep-alive';
        $headers[] = 'Cache-Control: no-cache';

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch,CURLOPT_ENCODING , "gzip");

        $ret = curl_exec ($ch);
        $this->_scanResult = json_decode($ret, true);
    }

    function _distance($lat1, $lon1, $lat2, $lon2, $unit) {

      $theta = $lon1 - $lon2;
      $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
      $dist = acos($dist);
      $dist = rad2deg($dist);
      $miles = $dist * 60 * 1.1515;
      $unit = strtoupper($unit);

      if ($unit == "K") {
          return ($miles * 1.609344);
      } else if ($unit == "M") {
          return ($miles * 1.609344*1000);
      } else if ($unit == "N") {
          return ($miles * 0.8684);
      } else {
          return $miles;
      }
    }
}
$opts = getopt("ht:l:du::r::Li:s:");
$p = new PokeScan($opts, $pokeData) ;
$p->run();
