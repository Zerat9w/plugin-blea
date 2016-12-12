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
require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

if (!jeedom::apiAccess(init('apikey'), 'blea')) {
	echo 'Clef API non valide, vous n\'etes pas autorisé à effectuer cette action';
	die();
}

if (init('test') != '') {
	echo 'OK';
	die();
}
$result = json_decode(file_get_contents("php://input"), true);
if (!is_array($result)) {
	die();
}
if (isset($result['source'])){
	log::add('blea','debug','This is a message from antenna ' . $result['source']);
}
if (isset($result['learn_mode'])) {
	if ($result['learn_mode'] == 1) {
		config::save('include_mode', 1, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'learn',
			'state' => 1)
		);
	} else {
		config::save('include_mode', 0, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'learn',
			'state' => 0)
		);
	}
	die();
}

if (isset($result['started'])) {
	if ($result['started'] == 1) {
		log::add('blea','info','Antenna ' . $name . ' alive sending known devices');
		blea::sendIdToDeamon();
		if ($result['source'] != 'local'){
			$remotes = blea_remote::all();
			foreach ($remotes as $remote){
				if ($remote->getRemoteName() == $result['source']){
					$remote->setConfiguration('lastupdate',date("Y-m-d H:i:s"));
					$remote->save();
					break;
				}
			}
		}
	}
	die();
}
if (isset($result['heartbeat'])) {
	if ($result['heartbeat'] == 1) {
		log::add('blea','info','This is a heartbeat from antenna ' . $result['source']);
		if ($result['source'] != 'local'){
			$remotes = blea_remote::all();
			foreach ($remotes as $remote){
				if ($remote->getRemoteName() == $result['source']){
					$remote->setConfiguration('lastupdate',date("Y-m-d H:i:s"));
					$remote->save();
					break;
				}
			}
		}
	}
	die();
}

if (isset($result['devices'])) {
	
	foreach ($result['devices'] as $key => $datas) {
		if (!isset($datas['id'])) {
			continue;
		}
		if (isset($datas['source'])){
			log::add('blea','debug','This is a message from antenna ' . $datas['source']);
			if ($datas['source'] != 'local'){
				$remotes = blea_remote::all();
				foreach ($remotes as $remote){
					if ($remote->getRemoteName() == $datas['source']){
						$remote->setConfiguration('lastupdate',date("Y-m-d H:i:s"));
						$remote->save();
						break;
					}
				}
			}
		}
		$blea = blea::byLogicalId($datas['id'], 'blea');
		if (!is_object($blea)) {
			if ($datas['learn'] != 1) {
				continue;
			}
			$blea = blea::createFromDef($datas);
			if (!is_object($blea)) {
				log::add('blea', 'debug', __('Aucun équipement trouvé pour : ', __FILE__) . secureXSS($datas['id']));
				continue;
			}
			event::add('jeedom::alert', array(
				'level' => 'warning',
				'page' => 'blea',
				'message' => '',
			));
			event::add('blea::includeDevice', $blea->getId());
		}
		if (!$blea->getIsEnable()) {
			continue;
		}
		if (isset($datas['rssi'])) {
			$cmdremote = $blea->getCmd(null, 'rssi' . $datas['source']);
			if (!is_object($cmdremote)) {
				$cmdremote = new bleaCmd();
				$cmdremote->setLogicalId('rssi' . $datas['source']);
				$cmdremote->setIsVisible(0);
				$cmdremote->setIsHistorized(1);
				$cmdremote->setName(__('Rssi '. $datas['source'], __FILE__));
				$cmdremote->setType('info');
				$cmdremote->setSubType('numeric');
				$cmdremote->setUnite('dbm');
				$cmdremote->setConfiguration('returnStateValue',-200);
				$cmdremote->setConfiguration('returnStateTime',1);
				$cmdremote->setEqLogic_id($blea->getId());
				$cmdremote->save();
			}
			if ($cmdremote->getConfiguration('returnStateValue') != -200){
				$cmdremote->setConfiguration('returnStateValue',-200);
				$cmdremote->setConfiguration('returnStateTime',1);
				$cmdremote->save();
			}
			$cmdremote->event($datas['rssi']);
			$cmdpresent = $blea->getCmd(null, 'present');
			if (!is_object($cmdpresent)) {
				$cmdpresent = new bleaCmd();
				$cmdpresent->setLogicalId('present');
				$cmdpresent->setIsVisible(0);
				$cmdpresent->setIsHistorized(1);
				$cmdpresent->setName(__('Present', __FILE__));
				$cmdpresent->setType('info');
				$cmdpresent->setSubType('binary');
				$cmdpresent->setConfiguration('returnStateValue',0);
				$cmdpresent->setConfiguration('returnStateTime',1);
				$cmdpresent->setEqLogic_id($blea->getId());
				$cmdpresent->save();
			}
			if ($cmdpresent->getConfiguration('returnStateValue') != 0){
				$cmdpresent->setConfiguration('returnStateValue',0);
				$cmdpresent->setConfiguration('returnStateTime',1);
				$cmdpresent->save();
			}
			$cmdpresent->event(1);
		}
		$remotelist =['rssilocal'];
		$remotes = blea_remote::all();
		foreach ($remotes as $remote){
			$name = $remote->getRemoteName();
			$remotelist[]='rssi' . $name;
		}
		$cmdrssitoremove=[];
		foreach ($blea->getCmd('info') as $cmd) {
			$logicalId = $cmd->getLogicalId();
			if ($logicalId == '' || $logicalId == 'present') {
				continue;
			}
			if (substr($logicalId,0,4) == 'rssi'){
				if (!in_array($logicalId,$remotelist)){
					$cmdrssitoremove[]=$cmd;
				}
			}
			$path = explode('::', $logicalId);
			$value = $datas;
			foreach ($path as $key) {
				if (!isset($value[$key])) {
					continue (2);
				}
				$value = $value[$key];
			}
			if ($logicalId == 'rssi' && $datas['source'] != 'local') {
				continue;
			}
			$antenna = 'local';
			$antennaId = $blea->getConfiguration('antennareceive','local');
			if ($antennaId != 'local'){
				$remote = blea_remote::byId($antennaId);
				$antenna = $remote->getRemoteName();
			}
			if ($logicalId != 'present' && $antenna != $datas['source']){
				log::add('blea','debug','Ignoring this antenna (' . $datas['source'] . ' only allowed ' . $antenna .') must not trigger events except for presence and rssi : ' . $logicalId );
				continue;
			}
			if (!is_array($value)) {
				$cmd->event($value);
			}
			if ($logicalId == 'battery') {
				$blea->batteryStatus($value);
			}
		}
		foreach ($cmdrssitoremove as $cmdremove){
			$cmdremove->remove();
		}
	}
}
