<?php
/**
    cp.php
    Copyright (C) 2012 Javier Pardo Blasco

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

*/


require('globals.inc');
require("captiveportal.inc");
require_once("voucher.inc");
define("BASE_URL", '/cp.php');
//define("VOUCHER_BIN", '/usr/local/bin/voucher');

global $cpzone, $g;


define("SECRET_KEY", 'michorizo');


class RollNotFoundException extends Exception{}
class CantWritePrivateKeyException extends Exception{}




function auth() {
	//file_put_contents('/tmp/trace', $_SERVER);
    if(
        $_SERVER['auth'] == SECRET_KEY ||
        $_SERVER['Auth'] == SECRET_KEY ||
        $_SERVER['AUTH'] == SECRET_KEY ||
        $_SERVER['HTTP_AUTH'] == SECRET_KEY
        ) return true;

    return false;
}

if(!auth()){
    header("Not authorized", false, 403);
    return;
}

/**
 * Given the roll number, returns the the roll index in the persistence array.
 * @param Array $db The roll database as it arrives from the persistence
 * @param int $number The roll number
 */
function get_rol_index_by_number($db, $number){
	//print "Get rol index by number:";
	//print_r($db);
	//print "Number: ".$number;
    foreach($db as $key => $rol)
        if($rol["number"] == $number)return $key;
    throw new RollNotFoundException("Number:".$number);
}

/**
 * Given a roll number, returns the vouchers for that roll
 * @param integer $roll_number
 * @param integer $count The number of vouchers to return
 */
function retrieve_vouchers($roll_number, $count){
    global $g, $config, $cpzone;

    $privkey = base64_decode($config['voucher'][$cpzone]['privatekey']);
    if (!strstr($privkey,"BEGIN RSA PRIVATE KEY")) throw new CantWritePrivateKeyException("There is no RSA key info");

    $fd = fopen("{$g['varetc_path']}/voucher.private","w");

    if (!$fd) throw new CantWritePrivateKeyException("Cant write RSA key to temp disk");

    chmod("{$g['varetc_path']}/voucher.private", 0600);
    fwrite($fd, $privkey);
    fclose($fd);
    $cmd = VOUCHER_BIN." -c {$g['varetc_path']}/voucher.cfg -p {$g['varetc_path']}/voucher.private $roll_number $count";
    #print "cmd: $cmd";
    exec($cmd, $out);
    unlink("{$g['varetc_path']}/voucher.private");

    $vs = Array();
    foreach( $out as $line){
        if(!preg_match('/^\"\s(.*)\"$/', $line, $voucher)) continue;
        $vs[] = $voucher[1];

    }

    return $vs;
}


/**
 * parses a roll to json
 * @param Array $roll The roll info as it arrives from the pfSense persistence
 */
function to_json($roll){
    $format = '{"number": "%s", "minutes": "%s", "comment": "%s", "count": "%s"';
    $data = sprintf($format, $roll["number"], $roll["minutes"], $roll["comment"], $roll["count"]);
    #print_r($roll);
    if(isset($roll["active"]) && sizeof($roll["active"]) && is_array($roll["active"])){
            $acts =  $roll["active"];
            $data .= ', "active": {';
            foreach($acts as $key => $vo){
                $data .= sprintf('"%s": ', $key);
                $data .= sprintf('{"voucher": "%s", "timestamp": "%s"},', $vo["voucher"], $vo["timestamp"]);
            }
            $data = str_replace("},", "}}", $data);
    }
    $data .= sprintf(', "used": "%s"', voucher_used_count($roll["number"]));
    $vouchers = retrieve_vouchers($roll["number"], $roll["count"]);

    $tdata = "";
    foreach( $vouchers as $voucher)
        $tdata .= sprintf('"%s", ', $voucher);
    //print_r($vouchers);
    if(sizeof($vouchers) > 0){
        $tdata = preg_replace( "/,\s$/", "", $tdata );
        $data .= ', "vouchers": ['.$tdata."]";
    }


    $data .= "}";


    retrieve_vouchers($roll["number"], $roll["count"]);
    return $data;
}



/**
 * Validates the user input for creating new posts
 */
function valid_post(){
    global $a_roll, $config, $cpzone;

    // Look for duplicate roll #
    foreach($a_roll as $re) {
            if($re['number'] == $_POST['number']) {

                    header("Resource exists", fall, 409);
                    print "Roll number %s already exists." % $_POST['number'];
                    return false;
            }
    }
    $maxnumber = (1<<$config['voucher'][$cpzone]['rollbits']) -1;    // Highest Roll#
    $maxcount = (1<<$config['voucher'][$cpzone]['ticketbits']) -1;   // Highest Ticket#

    if(!isset($_POST['number']))
        $input_errors[] = "Roll number is missing";
    if(!isset($_POST['count']))
        $input_errors[] = "Roll vouchers count is missing";
    if(!isset($_POST['minutes']))
        $input_errors[] = "Roll minutes is missing";


    if (!is_numeric($_POST['number']) || $_POST['number'] >= $maxnumber)
        $input_errors[] = sprintf(gettext("Roll number must be numeric and less than %s"), $maxnumber);

    if (!is_numeric($_POST['count']) || $_POST['count'] < 1 || $_POST['count'] > $maxcount)
        $input_errors[] = sprintf(gettext("A roll has at least one voucher and less than %s."), $maxcount);

    if (!is_numeric($_POST['minutes']) || $_POST['minutes'] < 1)
        $input_errors[] = gettext("Each voucher must be good for at least 1 minute.");

    if($input_errors){
        header("Errors found", fall, 400);
        foreach($input_errors as $error){

	    $out .= $error."\n";
        }
        return false;
    }
    return true;
}

/*
 * Checks if the config is correctly initialized
 */
function load_cfg(){
        global $config, $cpzone;
        //print_r( $config["voucher"]);
        if (!is_array($config['voucher'])) {
            print "No existe config de voucher";
            $config['voucher'] = array();
        }

        if (!is_array($config['voucher'][$cpzone]['roll'])) {
            print "no existe info de roles";
            $config['voucher'][$cpzone]['roll'] = array();
        }
        $a_roll = &$config['voucher'][$cpzone]['roll'];
        return $a_roll;

}

#print( "has hecho:".$_SERVER['REQUEST_METHOD']."--->");

switch($_SERVER['REQUEST_METHOD']){
    case GET:

        if(preg_match('/^\/roll\/(\w+)\/(\d+)\/?$/', $_SERVER['PATH_INFO'], $matches)){
            $cpzone = $matches[1];
            $number = $matches[2];
            
        }else{
            header("Bad URI", false, 400);
            print "Bad URI";
            return;
        }

        $a_roll = load_cfg();
        #print_r($a_roll);
        try{
            $id = get_rol_index_by_number($a_roll, $number);
        }catch(RollNotFoundException $ex){
            header("Not found", false, 404);
            print "Not found";
            return;
        }
        $rol = $a_roll[$id];

        header("Content-Type: application/json");
        $data = to_json($rol);
        print $data;


        break;

    case DELETE:
        $id = -1;

        if(preg_match('/^\/roll\/(\w+)\/(\d+)\/?$/', $_SERVER['PATH_INFO'], $matches)){
            $cpzone = $matches[1];
            $number = $matches[2];            
        }else{
            header("Bad URI", false, 400);
            print "Bad URI";
            return;
        }

        $a_roll = load_cfg();
        #END load_cfg

        try{
            $id = get_rol_index_by_number($a_roll, $number);
        }catch(RollNotFoundException $ex){
            header("Not found", false, 404);
            print "Not found";
            return;
        }
        
        
        $roll = $a_roll[$id]['number'];
        $voucherlck = lock("voucher{$cpzone}", LOCK_EX);
        unset($config['voucher'][$cpzone]['roll'][$id]);
        voucher_unlink_db($roll);
        unlock($voucherlck);
        write_config();
        header("Roll $id deleted", false, 204);

        break;
    case POST:
  #      print "POST";
      //  print $_SERVER['PATH_INFO'];
        if(preg_match('/^\/roll\/(\w+)\/?$/', $_SERVER['PATH_INFO'], $matches)){
            $cpzone = $matches[1];
        }else{
            header("Not found", false, 404);
            print "Not found";
            return;
        }

        $a_roll = load_cfg();
        if(!valid_post())return;
        
       
        
        $voucherlck = lock("voucher{$cpzone}");
		
        $rollent['zone']  = $cpzone;
        $rollent['number']  = $_POST['number'];
        $rollent['minutes'] = $_POST['minutes'];
        $rollent['comment'] = $_POST['comment'];
        
        $rollent['count'] = $_POST['count'];
        $len = ($rollent['count']>>3) + 1;   // count / 8 +1
        $rollent['used'] = base64_encode(str_repeat("\000",$len)); // 4 bitmask
        $rollent['active'] = array();
        voucher_write_used_db($rollent['number'], $rollent['used']);
        voucher_write_active_db($rollent['number'], array());   // create empty DB
        voucher_log(LOG_INFO,sprintf(gettext('All %1$s vouchers from Roll %2$s marked unused'), $rollent['count'], $rollent['number']));
            
                         
        unlock($voucherlck);
        
        $config['voucher'][$cpzone]['roll'][] = $rollent; 
        //print_r($a_roll);
        //print($g['conf_path']);   
        write_config();

        header("Location: ".BASE_URL."/roll/".$cpzone."/".$_POST["number"], false, 303);
        print BASE_URL."/roll/".$cpzone."/".$_POST["number"];
        break;

}

?>
