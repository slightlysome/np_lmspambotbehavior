<?php
/*
    LMSpamBotBehavior Nucleus plugin
    Copyright (C) 2013-2014 Leo (http://nucleus.slightlysome.net/leo)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
	(http://www.gnu.org/licenses/gpl-2.0.html)
	
	See lmspambotbehavior/help.html for plugin description, install, usage and change history.
*/
class NP_LMSpamBotBehavior extends NucleusPlugin
{
	var $insertFormTicket;
	var $aTicket;
	var $performSpamCheck;
	
	// name of plugin 
	function getName()
	{
		return 'LMSpamBotBehavior';
	}

	// author of plugin
	function getAuthor()
	{
		return 'Leo (http://nucleus.slightlysome.net/leo)';
	}

	// an URL to the plugin website
	// can also be of the form mailto:foo@bar.com
	function getURL()
	{
		return 'http://nucleus.slightlysome.net/plugins/lmspambotbehavior';
	}

	// version of the plugin
	function getVersion()
	{
		return '1.0.0';
	}

	// a description to be shown on the installed plugins listing
	function getDescription()
	{
		return 'Spam check plugin for the LMCommentModerator plugin that check if the comment author behave as a spam bot';
	}

	function supportsFeature ($what)
	{
		switch ($what)
		{
			case 'SqlTablePrefix':
				return 1;
			case 'SqlApi':
				return 1;
			case 'HelpPage':
				return 1;
			default:
				return 0;
		}
	}
	
	function hasAdminArea()
	{
		return 1;
	}
	
	function getMinNucleusVersion()
	{
		return '360';
	}
	
	function getTableList()
	{	
		return 	array($this->getTableTicket());
	}
	
	function getTableTicket()
	{
		// select * from nucleus_plug_lmspambotbehavior_ticket;
		return sql_table('plug_lmspambotbehavior_ticket');
	}

	function getEventList() 
	{ 
		return array('AdminPrePageFoot', 'LMCommentModerator_SpamCheck', 'LMReplacementVars_PreForm', 'FormExtra', 'ValidateForm'); 
	}
	
	function getPluginDep() 
	{
		return array('NP_LMCommentModerator');
	}

	function install()
	{
		$sourcedataversion = $this->getDataVersion();

		$this->upgradeDataPerform(1, $sourcedataversion);
		$this->setCurrentDataVersion($sourcedataversion);
		$this->upgradeDataCommit(1, $sourcedataversion);
		$this->setCommitDataVersion($sourcedataversion);					
	}
	
	function unInstall()
	{
		if ($this->getOption('deldatauninstall') == 'yes')	
		{
			foreach ($this->getTableList() as $table) 
			{
				sql_query("DROP TABLE IF EXISTS ".$table);
			}
		}
	}

	function init()
	{
		$this->insertFormTicket = false;
		$this->aTicket = array();
		$this->performSpamCheck = false;
	}
	
	function event_AdminPrePageFoot(&$data)
	{
		// Workaround for missing event: AdminPluginNotification
		$data['notifications'] = array();
			
		$this->event_AdminPluginNotification($data);
			
		foreach($data['notifications'] as $aNotification)
		{
			echo '<h2>Notification from plugin: '.htmlspecialchars($aNotification['plugin'], ENT_QUOTES, _CHARSET).'</h2>';
			echo $aNotification['text'];
		}
	}
	
	////////////////////////////////////////////////////////////
	//  Events
	function event_AdminPluginNotification(&$data)
	{
		global $member, $manager;
		
		$actions = array('overview', 'pluginlist', 'plugin_LMSpamBotBehavior');
		$text = "";
		
		if(in_array($data['action'], $actions))
		{
			$sourcedataversion = $this->getDataVersion();
			$commitdataversion = $this->getCommitDataVersion();
			$currentdataversion = $this->getCurrentDataVersion();
		
			if($currentdataversion > $sourcedataversion)
			{
				$text .= '<p>An old version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin files are installed. Downgrade of the plugin data is not supported. The correct version of the plugin files must be installed for the plugin to work properly.</p>';
			}
			
			if($currentdataversion < $sourcedataversion)
			{
				$text .= '<p>The version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin data is for an older version of the plugin than the version installed. ';
				$text .= 'The plugin data needs to be upgraded or the source files needs to be replaced with the source files for the old version before the plugin can be used. ';

				if($member->isAdmin())
				{
					$text .= 'Plugin data upgrade can be done on the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' <a href="'.$this->getAdminURL().'">admin page</a>.';
				}
				
				$text .= '</p>';
			}
			
			if($commitdataversion < $currentdataversion && $member->isAdmin())
			{
				$text .= '<p>The version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin data is upgraded, but the upgrade needs to commited or rolled back to finish the upgrade process. ';
				$text .= 'Plugin data upgrade commit and rollback can be done on the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' <a href="'.$this->getAdminURL().'">admin page</a>.</p>';
			}
		}
		
		if($text)
		{
			array_push(
				$data['notifications'],
				array(
					'plugin' => $this->getName(),
					'text' => $text
				)
			);
		}
	}

	function event_LMCommentModerator_SpamCheck(&$data)
	{
		$spamcheck = $data['spamcheck'];

		if(!$spamcheck['result'] && $this->getOption('spamcheckenabled') == 'yes' && $this->performSpamCheck)
		{
			$result = false;
			$message = false;

			$ticketvalue = requestVar('sbbticket');

			if($ticketvalue)
			{
				$ip = $data['spamcheck']['ip'];
				
				$aTicket = $this->_getTicketByTicketValueIp($ticketvalue, $ip);
				if($aTicket === false) { return false; }

				if($aTicket)
				{
					$aTicket = $aTicket['0'];
					
					$ticketid = $aTicket['ticketid'];
					$inputname = $aTicket['inputname'];
					$ticketinputvalue = $aTicket['inputvalue'];
					$imageused = $aTicket['imageused'];

					$ret = $this->_updateTicketUsed($ticketid);
					if(ret === false) { return false; }
					
					$requestinputvalue = requestVar($inputname);
					
					if($requestinputvalue)
					{
						if($requestinputvalue == $ticketinputvalue)
						{
							if($imageused || $this->getOption('botbehaviourimage') == 'no')
							{
								$issuedwhen = strtotime($aTicket['issuedwhen']);
								$now = time();

								if($now > ($issuedwhen + (4 * 60 * 60))) // Ticket valid for 4 hours
								{
									$result = 'S';
									$message = 'Bot behavior (ID:001)'; // Ticket timeout;
								}
								
								if($now <= ($issuedwhen + 5)) // Ticket starts to be valid after 5 seconds
								{
									$result = 'S';
									$message = 'Bot behavior (ID:002)'; // Ticket used too soon
								}
							}
							else
							{
								$result = 'S';
								$message = 'Bot behavior (ID:003)'; // Bot behavior image not used
							}
						}
						else
						{
							$result = 'S';
							$message = 'Bot behavior (ID:004)'; // Unknown secret input value
						}
					}
					else
					{
						$result = 'S';
						$message = 'Bot behavior (ID:005)'; // Missing secret input value
					}
				}
				else
				{
					$result = 'S';
					$message = 'Bot behavior (ID:006)'; // Unknown ticket value
				}
			}
			else
			{
				$result = 'S';
				$message = 'Bot behavior (ID:007)'; // Missing ticket value
			}

			if($result)
			{
				$spamcheck['result'] = $result;
				$spamcheck['message'] = $message;
				$spamcheck['plugin'] = $this->getName();
			}

			// Delete tickets older than 1 day
			$this->_deleteTicketOlderThan(24 * 60 * 60);
		}
	}
	
	function event_LMReplacementVars_PreForm(&$data)
	{
		$type = $data['type'];
		$commentid = $data['commentid'];
		
		$commentedit = intRequestVar('commentedit');

		if(substr($type, 0, 12) == 'commentform-')
		{
			if(!($commentid && $commentedit == $commentid))
			{
				$this->insertFormTicket = true;
			}
		}
	}

	function event_FormExtra(&$data)
	{
		$type = $data['type'];

		if(substr($type, 0, 12) == 'commentform-' && $this->insertFormTicket)
		{
			$aTicket = $this->_getTicketForPage();
			
			echo '<input type="hidden" name="sbbticket" value="'.$aTicket['ticketvalue'].'" />';
			echo '<input type="hidden" name="'.$aTicket['inputname'].'" value="'.$aTicket['inputvalue'].'" />';
		}
	}
	
	function event_ValidateForm(&$data)
	{
		$type = $data['type'];
		
		if($type = 'comment')
		{
			$this->performSpamCheck = true;
		}
	}

	////////////////////////////////////////////////////////////
	//  Handle skin vars
	function doSkinVar($skinType, $vartype, $templatename = '')
	{
		global $manager;

		$aArgs = func_get_args(); 
		$num = func_num_args();

		$aSkinVarParm = array();
		
		for($n = 3; $n < $num; $n++)
		{
			$parm = explode("=", func_get_arg($n));
			
			if(is_array($parm) && count($parm) == 2)
			{
				$aSkinVarParm[$parm['0']] = $parm['1'];
			}
		}

		if($templatename)
		{
			$template =& $manager->getTemplate($templatename);
		}
		else
		{
			$template = array();
		}

		switch (strtoupper($vartype))
		{
			case 'BOTBEHAVIORIMAGE':
				$this->doSkinVar_botbehaviorimage($skinType, $templatename, $aSkinVarParm);
				break;
				
			default:
				echo "Unknown vartype: ".$vartype;
		}
	}

	function doSkinVar_botbehaviorimage($skinType, $templatename, $aSkinVarParm)
	{
		global $CONF;
		
		if($this->insertFormTicket && $this->getOption('botbehaviourimage') == 'yes')
		{
			$aTicket = $this->_getTicketForPage();
			
			echo '<img src="'.$CONF['BlogURL'].'/image.php?imagevalue='.$aTicket['imagevalue'].'" />';
		}
	}

	////////////////////////////////////////////////////////////
	//  doAction functions
	function doAction($actionType)
	{
		switch (strtoupper($actionType))
		{
			case 'IMAGE':
				$error = $this->doAction_image();
				break;
			default:
				$error = 'Unknown action';
				break;
		}
		
		return $error;
	}

	function doAction_image()
	{
		$imagevalue = requestVar('imagevalue');
		
		if($imagevalue)
		{
			$aTicket = $this->_getTicketByImageValueIp($imagevalue, $ip);
			if($aTicket === false) { return false; }

			if($aTicket)
			{
				$aTicket = $aTicket['0'];
			
				$ticketid = $aTicket['ticketid'];

				$this->_updateImageUsed($ticketid);
			}
		}
		
		header('Content-Type: image/png');
		echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=');
		exit();
	}
	
	////////////////////////////////////////////////////////////
	//  Private functions

	function _createTicket($ip)
	{
		$ticketvalue = $this->_genTicketValue();
		$inputname = substr('a'.$this->_genTicketValue(), 0, 16);
		$inputvalue = $this->_genTicketValue();
		$imagevalue = $this->_genTicketValue();
		
		$ticketid = $this->_insertTicket($ticketvalue, $inputname, $inputvalue, $imagevalue, $ip);
		if($ticketid == false) { return false; }
		
		return array('ticketvalue' => $ticketvalue, 'inputname' => $inputname, 'inputvalue' => $inputvalue, 'imagevalue' => $imagevalue );
	}
	
	function _genTicketValue()
	{
		return substr(md5(uniqid(rand(), true)), 0, 16);
	}

	function _getTicketForPage()
	{
		$aTicket = $this->aTicket;
		
		if(!($aTicket))
		{
			$aTicket = $this->_createTicket(serverVar("REMOTE_ADDR"));
			if($aTicket === false) { return false; }
			
			$this->aTicket = $aTicket;
		}
		
		return $aTicket;
	}
	/////////////////////////////////////////////////////
	// Data access and manipulation functions

	/////////////////////////////////////////////////////////
	// Data access functions on Ticket
	function _getTicketByImageValueIp($imagevalue, $ip)
	{
		return $this->_getTicket(false, false, $imagevalue, $ip);
	}

	function _getTicketByTicketValueIp($ticketvalue, $ip)
	{
		return $this->_getTicket(false, $ticketvalue, false, $ip);
	}
	
	function _getTicket($ticketid, $ticketvalue, $imagevalue, $ip)
	{
		$ret = array();
		
		$query = "SELECT ticketid, ticketvalue, inputname, inputvalue, ip, issuedwhen, used, imageused FROM ".$this->getTableTicket()." ";
		
		if($ticketid)
		{
			$query .= "WHERE ticketid = ".IntVal($ticketid)." ";
		}
		elseif($ticketvalue)
		{
			$query .= "WHERE ticketvalue = '".sql_real_escape_string($ticketvalue)."' ";
			
			if($ip)
			{
				$query .= "AND ip = '".sql_real_escape_string($ip)."' ";
			}
		}
		elseif($imagevalue)
		{
			$query .= "WHERE imagevalue = '".sql_real_escape_string($imagevalue)."' ";
			
			if($ip)
			{
				$query .= "AND ip = '".sql_real_escape_string($ip)."' ";
			}
		}

		$res = sql_query($query);
		
		if($res)
		{			
			while ($ticket = sql_fetch_assoc($res)) 
			{
				array_push($ret, $ticket);
			}
		}
		else
		{
			return false;
		}
		return $ret;
	}

	function _insertTicket($ticketvalue, $inputname, $inputvalue, $imagevalue, $ip)
	{
		$query = "INSERT ".$this->getTableTicket()." (ticketvalue, inputname, inputvalue, imagevalue, ip, issuedwhen, used, imageused) "
				."VALUES ('".sql_real_escape_string($ticketvalue)."', '".sql_real_escape_string($inputname)."', '".sql_real_escape_string($inputvalue)."', '".sql_real_escape_string($imagevalue)
				."', '".sql_real_escape_string($ip)."', now(), 0, 0)";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;
		}
		
		$ticketid = sql_insert_id();
		
		return $ticketid;
	}

	function _updateTicketUsed($ticketid)
	{
		$query = "UPDATE ".$this->getTableTicket()." SET "
				."used = used + 1 "
				."WHERE ticketid = ".intVal($ticketid)." ";
					
		$res = sql_query($query);
		
		if(!$res) { return false; }
				
		return true;
	}

	function _updateImageUsed($ticketid)
	{
		$query = "UPDATE ".$this->getTableTicket()." SET "
				."imageused = imageused + 1 "
				."WHERE ticketid = ".intVal($ticketid)." ";
					
		$res = sql_query($query);
		
		if(!$res) { return false; }
				
		return true;
	}

	function _deleteTicketOlderThan($oldseconds)
	{
		$oldtime = time() - $oldseconds;

		$query = "DELETE FROM ".$this->getTableTicket(). " WHERE issuedwhen < '".date('Y-m-d H:i:s',$oldtime)."' ";

		$res = sql_query($query);
		
		if(!$res) { return false; }
				
		return true;
	}
	
	////////////////////////////////////////////////////////////////////////
	// Plugin Upgrade handling functions
	function getCurrentDataVersion()
	{
		$currentdataversion = $this->getOption('currentdataversion');
		
		if(!$currentdataversion)
		{
			$currentdataversion = 0;
		}
		
		return $currentdataversion;
	}

	function setCurrentDataVersion($currentdataversion)
	{
		$res = $this->setOption('currentdataversion', $currentdataversion);
		$this->clearOptionValueCache(); // Workaround for bug in Nucleus Core
		
		return $res;
	}

	function getCommitDataVersion()
	{
		$commitdataversion = $this->getOption('commitdataversion');
		
		if(!$commitdataversion)
		{
			$commitdataversion = 0;
		}

		return $commitdataversion;
	}

	function setCommitDataVersion($commitdataversion)
	{	
		$res = $this->setOption('commitdataversion', $commitdataversion);
		$this->clearOptionValueCache(); // Workaround for bug in Nucleus Core
		
		return $res;
	}

	function getDataVersion()
	{
		return 1;
	}
	
	function upgradeDataTest($fromdataversion, $todataversion)
	{
		// returns true if rollback will be possible after upgrade
		$res = true;
				
		return $res;
	}
	
	function upgradeDataPerform($fromdataversion, $todataversion)
	{
		// Returns true if upgrade was successfull
		
		for($ver = $fromdataversion; $ver <= $todataversion; $ver++)
		{
			switch($ver)
			{
				case 1:
					$this->createOption('currentdataversion', 'currentdataversion', 'text','0', 'access=hidden');
					$this->createOption('commitdataversion', 'commitdataversion', 'text','0', 'access=hidden');

					$this->createOption('deldatauninstall', 'Delete NP_LMSpamBotBehavior data tables on uninstall?', 'yesno','no');
					$this->createOption('spamcheckenabled', 'Enable spam bot behavior check?', 'yesno','yes');
					$this->createOption('botbehaviourimage', 'Enable bot behavior image?', 'yesno','no');

					$this->_createTableTicket();
					
					$res = true;
					break;
					
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}
		
		return true;
	}
	
	function upgradeDataRollback($fromdataversion, $todataversion)
	{
		// Returns true if rollback was successfull
		for($ver = $fromdataversion; $ver >= $todataversion; $ver--)
		{
			switch($ver)
			{
				case 1:
					$res = true;
					break;
				
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}

		return true;
	}

	function upgradeDataCommit($fromdataversion, $todataversion)
	{
		// Returns true if commit was successfull
		for($ver = $fromdataversion; $ver <= $todataversion; $ver++)
		{
			switch($ver)
			{
				case 1:
					$res = true;
					break;
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}
		return true;
	}

	function _createTableTicket()
	{
		$query  = "CREATE TABLE IF NOT EXISTS ".$this->getTableTicket();
		$query .= "( ";
		$query .= "ticketid int(11) NOT NULL auto_increment, ";
		$query .= "ticketvalue char(16) NOT NULL, ";
		$query .= "inputname char(16) NOT NULL, ";
		$query .= "inputvalue char(16) NOT NULL, ";
		$query .= "imagevalue char(16) NOT NULL, ";
		$query .= "ip varchar(16) NOT NULL, ";
		$query .= "issuedwhen datetime NOT NULL, ";
		$query .= "used int(11) NOT NULL, ";
		$query .= "imageused int(11) NOT NULL, ";
		$query .= "PRIMARY KEY (ticketid) ";
		$query .= ") ";
		
		sql_query($query);

		if($this->_checkIndexIfExists($this->getTableTicket(), 'ticketvalue_idx') == 0)
		{
			$query  = "CREATE INDEX ticketvalue_idx ON ".$this->getTableTicket()." (ticketvalue)";
			sql_query($query);
		}

		if($this->_checkIndexIfExists($this->getTableTicket(), 'imagevalue_idx') == 0)
		{
			$query  = "CREATE INDEX imagevalue_idx ON ".$this->getTableTicket()." (imagevalue)";
			sql_query($query);
		}

		if($this->_checkIndexIfExists($this->getTableTicket(), 'issuedwhen_idx') == 0)
		{
			$query  = "CREATE INDEX issuedwhen_idx ON ".$this->getTableTicket()." (issuedwhen)";
			sql_query($query);
		}
	}

	function _checkIndexIfExists($table, $index)
	{
		// Retuns: 0: Not found , 1: Found, false: error
		$found = false;
		
		$res = sql_query("SELECT Count(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS "
						."WHERE table_name = '".$table."' AND index_name = '".$index."' ");

		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				$found = $o->cnt;
			}
		}
		
		return $found;
	}
}
?>
