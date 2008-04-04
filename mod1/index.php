<?php
/***************************************************************
*  Copyright notice
*  
*  (c) 2004 Ren� Fritz (r.fritz@colorcube.de)
*  (c) 2008 Francois Suter (support@cobweb.ch)
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is 
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
* 
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
* 
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/** 
 * Module 'DevLog' for the 'devlog' extension.
 *
 * @author	Ren� Fritz <r.fritz@colorcube.de>
 * @author	Francois Suter <support@cobweb.ch>
 */

	// this is a hack to prevent logging while initialization inside of this module
$EXTCONF['devlog']['nolog'] = TRUE;

	// DEFAULT initialization of a module [BEGIN]
unset($MCONF);	
require ('conf.php');
require ($BACK_PATH.'init.php');

$TYPO3_CONF_VARS['EXTCONF']['devlog']['nolog'] = TRUE;

require ($BACK_PATH.'template.php');
$GLOBALS['LANG']->includeLLFile('EXT:devlog/mod1/locallang.xml');
require_once (PATH_t3lib.'class.t3lib_scbase.php');
$BE_USER->modAccess($MCONF,1);	// This checks permissions and exits if the users has no permission for entry.
	// DEFAULT initialization of a module [END]

class tx_devlog_module1 extends t3lib_SCbase {
	var $pageinfo;

	var $logRuns = array(); // List of recent log runs
	var $selectedLog; // Flag for the number of logs to display
	var $maxLogRuns = 15; // Maximum number of log runs to get from the database for the log run list
		// TODO: make max logs per page configurable
	var $maxLogsPerPage = 25; // When displaying all log runs, limit of items per page
	var $totalLogEntries; // Total number of log entries in the database
	var $filters = array(); // List of possible values for the log filters
	var $extConf = array(); // Extension configuration

	/**
	 * Initialise the plugin
	 */
	function init()	{
//		global $BE_USER,$LANG,$BACK_PATH,$TCA_DESCR,$TCA,$CLIENT,$TYPO3_CONF_VARS;
		global $MCONF;

			// get log run list
		$this->getLogRuns();
		
			// GC
		$this->logGC();
		
		parent::init();
//t3lib_div::debug($this->MOD_SETTINGS);

		$this->selectLog();

		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$MCONF['extKey']]);
	}

	/**
	 * Adds items to the ->MOD_MENU array. Used for the function menu selector.
	 */
	function menuConfig()	{

			// Load the list of values that can be used as filters (filters are used only when all entries are being displayed)
		$this->getLogFilters();

		$this->MOD_MENU = array(
			'function' => array(
				'showlog' => $GLOBALS['LANG']->getLL('showlog'),
				'setup' => $GLOBALS['LANG']->getLL('setup'),
			),
			'logrun' => array(
				'1000' => $GLOBALS['LANG']->getLL('latest_run'),
				'25' => $GLOBALS['LANG']->getLL('latest_25'),
				'50' => $GLOBALS['LANG']->getLL('latest_50'),
				'100' => $GLOBALS['LANG']->getLL('latest_100'),
				'-1' => $GLOBALS['LANG']->getLL('all_entries'),
			),
			'autorefresh' => 0,
			'page' => 0,
			'filter_extkey' => $this->filters['extkey'],
			'filter_severity' => $this->filters['severity'],
		);
		$this->MOD_MENU['logrun'] = t3lib_div::array_merge($this->logRuns,$this->MOD_MENU['logrun']);
		
		parent::menuConfig();
	}

		// If you chose 'web' as main module, you will need to consider the $this->id parameter which will contain the uid-number of the page clicked in the page tree
	/**
	 * Main function of the module. Write the content to $this->content
	 */
	function main()	{
		global $BE_USER, $BACK_PATH, $TYPO3_CONF_VARS;
		
		// Access check! Allow only admin user to view this content
		if ($BE_USER->user['admin'])	{
	
				// Draw the header.
			$this->doc = t3lib_div::makeInstance('bigDoc');
			$this->doc->backPath = $BACK_PATH;
			$this->doc->form='<form name="options" action="" method="POST">';

				// JavaScript
				// Load Prototype library (check if it exists in the TYPO3 source, otherwise get it from extension configuration)
			$pathToPrototype = '';
			if (file_exists($BACK_PATH.'contrib/prototype/prototype.js')) {
				$pathToPrototype = $BACK_PATH.'contrib/prototype/prototype.js';
			}
			elseif (isset($this->extConf['prototypePath'])) {
				$testPath = t3lib_div::getFileAbsFileName($this->extConf['prototypePath']);
				if (file_exists($testPath)) $pathToPrototype = $testPath;
			}
			if (!empty($pathToPrototype)) $this->doc->JScode .= '<script type="text/javascript" src="'.$pathToPrototype.'"></script>'."\n";

				// Define function for switching visibility of extra data field on or off
			$this->doc->JScodeArray[] .= 'var imageExpand = \'<img'.t3lib_iconWorks::skinImg($BACK_PATH,'gfx/plusbullet_list.gif','width="18" height="12"').' alt="+" />\';';
			$this->doc->JScodeArray[] .= 'var imageCollapse = \'<img'.t3lib_iconWorks::skinImg($BACK_PATH,'gfx/minusbullet_list.gif','width="18" height="12"').' alt="-" />\';';
			$this->doc->JScodeArray[] .= '
					function toggleExtraData(theID) {
						var theLink = $(\'debug-link-\' + theID);
						var theElement = $(\'debug-row-\' + theID);
						if (theElement.visible()) {
							theElement.hide();
							theLink.update(imageExpand);
							theLink.title = \''.$GLOBALS['LANG']->getLL('show_extra_data').'\';
						}
						else {
							theElement.show();
							theLink.update(imageCollapse);
							theLink.title = \''.$GLOBALS['LANG']->getLL('hide_extra_data').'\';
						}
					}
			';

				// JavaScript for menu switching
			$this->doc->JScodeArray[] = '
				script_ended = 0;
				function jumpToUrl(URL)	{
					document.location = URL;
				}';

				// JavaScript for automatic reloading of log window
			$this->doc->JScodeArray[] = '
				var reloadTimer = null;
				
				window.onload = function() {
				  if(window.name=="devlog") {
					document.getElementById("openview").style.visibility = "hidden";
				  }
				  setReloadTime('.($this->MOD_SETTINGS['autorefresh'] ? '2' : '0').'); // Pass a default value of ... seconds.
				}
				
				function setReloadTime(secs) {
				  if (arguments.length == 1) {
				    if (reloadTimer) clearTimeout(reloadTimer);
				    if (secs) reloadTimer = setTimeout("setReloadTime()", Math.ceil(parseFloat(secs) * 1000));
				  }
				  else {
				    //window.location.replace(window.location.href);
				    document.options.submit();
				  }
				}
				
				function toggleReload(autorefresh) {
					if(autorefresh){
						setReloadTime(2);
					}else{
						setReloadTime(0);
					};
				}';
				


			$headerSection ='';
			if ($this->MOD_SETTINGS['function'] == 'showlog') {
				$optMenu = array ();
				$optMenu['sellogrun'] = t3lib_BEfunc::getFuncMenu($this->id,'SET[logrun]',$this->MOD_SETTINGS['logrun'],$this->MOD_MENU['logrun']);
				if ($this->MOD_SETTINGS['logrun'] <= 1000) {
					$optMenu['autorefresh'] = '<input type="hidden" name="SET[autorefresh]" value="0">';
					$onClick = 'toggleReload(this.checked);';
					$optMenu['autorefresh'] .= '<input type="checkbox" name="SET[autorefresh]" id="autorefresh" value="1"'.($this->MOD_SETTINGS['autorefresh']?' checked':'').' onclick="'.htmlspecialchars($onClick).'"> <label for="autorefresh">'.$GLOBALS['LANG']->getLL('auto_refresh').'</label>';
				}
				$optMenu['refresh'] = '<input type="submit" name="refresh" value="'.$GLOBALS['LANG']->getLL('refresh').'">';
			
				$headerSection = $this->doc->menuTable(
					array(
						array('Select Log:',$optMenu['sellogrun']),
						array('',$optMenu['refresh'])
					),
					array(
						array('',$optMenu['autorefresh']),
						array('','')
					)
				);			
			}
			
			
			$this->content.=$this->doc->startPage($GLOBALS['LANG']->getLL('title'));
			$this->content.=$this->doc->header($GLOBALS['LANG']->getLL('title'));
			$this->content.=$this->doc->spacer(5);
			$this->content.=$this->doc->section('',$this->doc->funcMenu($headerSection,t3lib_BEfunc::getFuncMenu($this->id,'SET[function]',$this->MOD_SETTINGS['function'],$this->MOD_MENU['function']).'&nbsp;&nbsp;&nbsp;'.$this->openNewView()));
			$this->content.=$this->doc->divider(5);


			// Render content:
			$this->moduleContent();

			
			// ShortCut
			if ($BE_USER->mayMakeShortcut())	{
				$this->content.=$this->doc->spacer(20).$this->doc->section('',$this->doc->makeShortcutIcon('id',implode(',',array_keys($this->MOD_MENU)),$this->MCONF['name']));
			}
		
			$this->content.=$this->doc->spacer(10);
		}
		else {
				// If no access or if ID == zero
		
			$this->doc = t3lib_div::makeInstance('mediumDoc');
			$this->doc->backPath = $BACK_PATH;
		
			$this->content.=$this->doc->startPage($GLOBALS['LANG']->getLL('title'));
			$this->content.=$this->doc->header($GLOBALS['LANG']->getLL('title'));
			$this->content.=$this->doc->spacer(5);
			$this->content.=$this->doc->spacer(10);
		}
	}

	/**
	 * Prints out the module HTML
	 */
	function printContent()	{
		$this->content .= $this->doc->endPage();
		$this->content = $this->doc->insertStylesAndJS($this->content);
		echo $this->content;
	}
	
	/**
	 * Generates the module content
	 */
	function moduleContent()	{

		switch((string)$this->MOD_SETTINGS['function'])	{
			case 'showlog':
				if(count($this->logRuns)) {					
					$content = $this->getLogTable();
					$this->content.=$this->doc->section($GLOBALS['LANG']->getLL('log_entries').':',$content,0,1);
				}
			break;
			case 'setup':
				if (t3lib_div::_GP('clearlog')) {
					$GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_devlog', '');
					$content = '<div>'.$GLOBALS['LANG']->getLL('cleared_log').'</div>';
				} else {
					$content = '<div>'.$GLOBALS['LANG']->getLL('clearlog_desc').'</div>';
					$content.= '<input type="submit" name="clearlog" value="'.$GLOBALS['LANG']->getLL('clearlog').'">';
				}
				$this->content.=$this->doc->section($GLOBALS['LANG']->getLL('clearlog').':',$content,0,1);
			break;
		} 
	}
	
	
	/**
	 * Creates the log entry table
	 * 
	 * @return	string 	rendered HTML table
	 */	
	function getLogTable()	{
		global $BE_USER, $BACK_PATH;

			// init table layout
		$tableLayout = array (
			'table' => array ('<table border="0" cellspacing="1" cellpadding="2" style="width:auto;">', '</table>'),
			'0' => array (
				'tr' => array('<tr class="bgColor2" valign="top">','</tr>'),
			),
			'defRow' => array (
				'tr' => array('<tr class="bgColor-20">','</tr>'),
				'1' => array('<td align="center">','</td>'),
				'defCol' => array('<td>','</td>'),
			)
		);

		$table=array();
		$tr=0;
		
			// add header row
		$table[$tr][] = $GLOBALS['LANG']->getLL('uid');
		$header = $GLOBALS['LANG']->getLL('severity');
		if ($this->selectedLog == -1) {
			$header .= '<br />'.$this->renderFilterMenu('severity');
		}
		$table[$tr][] = $header;
		$table[$tr][] = $GLOBALS['LANG']->getLL('crdate');
		$header = $GLOBALS['LANG']->getLL('extkey');
		if ($this->selectedLog == -1) {
			$header .= '<br />'.$this->renderFilterMenu('extkey');
		}
		$table[$tr][] = $header;
		$table[$tr][] = $GLOBALS['LANG']->getLL('message');
		$table[$tr][] = $GLOBALS['LANG']->getLL('page');
		$table[$tr][] = $GLOBALS['LANG']->getLL('cruser');
		$table[$tr][] = $GLOBALS['LANG']->getLL('extra_data');

			// Select only the logs from the latest run
		if ($this->selectedLog > 1000) {
			$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','tx_devlog', 'crmsec = '.$this->selectedLog, $groupBy='', $orderBy='uid', $limit='');
		}
			// Select all log entries, but taking pagination into account
		elseif ($this->selectedLog == -1) {

				// Assemble the SQL condition from filters
			$whereClause = '';
//t3lib_div::debug($this->MOD_SETTINGS);
			foreach ($this->MOD_SETTINGS as $key => $value) {
				if (strpos($key, 'filter_') !== false && $value  != '*') {
					if (!empty($whereClause)) $whereClause .= ' AND ';
					list($dummy, $filterKey) = explode('_', $key);
					$whereClause .= $filterKey." = '".$value."'";
				}
			}

				// Load the total entries count
			$this->getLogEntriesCount($whereClause);

				// Make sure the start page number is not an empty string
			if (empty($this->MOD_SETTINGS['page'])) {
				$page = 0;
			}
			else {
				$page = $this->MOD_SETTINGS['page'];
			}
				// Calculate start page
				// If start is larger than entries count, revert to first page (0)
			$start = $page * $this->maxLogsPerPage;
			if ($start > $this->totalLogEntries) $start = 0;
			$limit = $start.','.$this->maxLogsPerPage;
			$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','tx_devlog', $whereClause, $groupBy='', $orderBy='uid DESC', $limit);
		}
			// Select the latest log entries up to the selected limit
		else {
			$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','tx_devlog', '', $groupBy='', $orderBy='uid DESC', $limit=$this->selectedLog);
		}

			// Loop on the results and build table for display
		$endDate = 0;
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres)) {

				// Memorise start and end date of selected entries
			if (empty($endDate)) $endDate = $row['crdate'];
			$startDate = $row['crdate'];
			
				// Severity: 0 is info, 1 is notice, 2 is warning, 3 is fatal error, -1 is "OK" message
			switch ($row['severity']) {
			    case 0:
			        $severity = '<img'.t3lib_iconWorks::skinImg($BACK_PATH,'gfx/info.gif','width="18" height="16"').' alt="" />';
			        break;
			    case -1:
			    case 1:
			    case 2:
			    case 3:
			        $severity = $this->doc->icons($row['severity']);
			        break;
			    default:
			        $severity = $row['severity'];
			        break;
			}
			
				// add row to table
			$tr++;

				// if user created log entry use a darker row background
			if ($row['cruser'] == intval($GLOBALS['BE_USER']->user['uid']))	{
				$tableLayout[$tr]['tr'] = array('<tr class="bgColor4">','</tr>');
			}				
		
 			$table[$tr][] = $this->linkLogRun($row['uid'], $row['crmsec']);
 			$table[$tr][] = $severity;
 			$table[$tr][] = date('d-m-y G:i',$row['crdate']);
 			$table[$tr][] = $row['extkey'];
 			$table[$tr][] = htmlspecialchars($row['msg']);
 			$table[$tr][] = $this->getItemFromRecord('pages', array('uid' => $row['pid']));
 			$table[$tr][] = $row['cruser'];
 			$dataVar = '';
 			if (!empty($row['data_var'])) {
 				$fullData = unserialize(stripslashes(substr($row['data_var'],1,strlen($row['data_var'])-1)));
 				if ($fullData === false) {
		 			$dataVar = $GLOBALS['LANG']->getLL('extra_data_error');
 				}
 				else {
		 			$dataVar = '<a href="javascript:toggleExtraData(\''.$row['uid'].'\')" id="debug-link-'.$row['uid'].'" title="'.$GLOBALS['LANG']->getLL('show_extra_data').'">';
			        $dataVar .= '<img'.t3lib_iconWorks::skinImg($BACK_PATH,'gfx/plusbullet_list.gif','width="18" height="12"').' alt="+" />';
		 			$dataVar .= '</a>';
		 			$dataVar .= '<div id="debug-row-'.$row['uid'].'" style="display: none;">'.t3lib_div::view_array($fullData).'</div>';
 				}
 			}
 			$table[$tr][] = $dataVar;
		}

			// Assemble pagination links, if required
		$pagination = '';
		if ($this->selectedLog == -1) $pagination = $this->renderPaginationLinks();

			// return rendered table and pagination
		if ($startDate == $endDate) {
			$content = '<p>'.$GLOBALS['LANG']->getLL('log_period').': '.t3lib_befunc::dateTimeAge($startDate).'</p>';
		}
		else {
			$content = '<p>'.$GLOBALS['LANG']->getLL('log_period').': '.t3lib_befunc::dateTimeAge($startDate).' - '.t3lib_befunc::dateTimeAge($endDate).'</p>';
		}
		$content .= $this->doc->divider(5);
		$content .= $pagination;
		$content .= $this->doc->table($table, $tableLayout);
		$content .= $pagination;
		return $content;
	}

	/** 
	 * This method assemble links to navigate between pages of log entries
	 *
	 * @return	string	list of pages with links
	 */
	function renderPaginationLinks() {
		$navigation = '';
		$numPages = ceil($this->totalLogEntries / $this->maxLogsPerPage);
		for ($i = 0; $i < $numPages; $i++) {
			$text = ($i * $this->maxLogsPerPage).'-'.(($i + 1) * $this->maxLogsPerPage);
			if ($i == $this->MOD_SETTINGS['page']) {
				$item = '<strong>'.$text.'</strong>';
			}
			else {
				$item = '<a href="?SET[page]='.$i.'">'.$text.'</a>';
			}
			$navigation .= $item.' ';
		}
		return '<p>'.$GLOBALS['LANG']->getLL('entries').': '.$navigation.'</p>';
	}

	/**
	 * This method renders a filter drop-down menu for a given filter key
	 *
	 * @param	string	name of a filter key
	 *
	 * @return	string	HTML code for the dropdown menu
	 *
	 * @see	getLogFilters()
	 */
	function renderFilterMenu($filterKey) {
		if (isset($this->filters[$filterKey])) {
			$filter = '<form name="filter'.$filterKey.'" action="" method="GET">';
			$filter .= '<select name="SET[filter_'.$filterKey.']" onchange="this.form.submit()">';
			foreach ($this->filters[$filterKey] as $key => $value) {
				if ((string)$key == (string)$this->MOD_SETTINGS['filter_'.$filterKey]) {
					$selected = ' selected="selected"';
				}
				else {
					$selected = '';
				}
				$filter .= '<option value="'.$key.'"'.$selected.'>'.$value.'</option>';
			}
			$filter .= '</select>';
			$filter .= '</form>';
			return $filter;
		}
		else {
			return '';
		}
	}

	/*******************************************
	 *
	 * DB stuff
	 *
	 *******************************************/	

	/**
	 * This method gets the list of the most recent log runs up to a limit defined by maxLogRuns
	 *
	 * @return	void
	 */
	function getLogRuns() {
		$this->logRuns = array();
		$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('DISTINCT crmsec,crdate', 'tx_devlog', $where_clause='', $groupBy='', $orderBy='crmsec DESC', $limit=$this->maxLogRuns);
			// Assemble those runs in an associative array with run timestamp as a key
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres)) {
			$this->logRuns[$row['crmsec']] = t3lib_befunc::dateTimeAge($row['crdate']);
		}
	}

	/**
	 * This method gets the total number of log entries in the database
	 *
	 * @param	string	a SQL WHERE clause to apply to the total, without the "WHERE" keyword
	 *
	 * @return	void
	 */
	function getLogEntriesCount($whereClause = '') {
		$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('COUNT(uid) AS total', 'tx_devlog', $whereClause);
		$row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres);
		$this->totalLogEntries = $row['total'];
	}

	/**
	 * This method retrieves lists of (unique) values that can be used to filter log entries
	 * Possible filters are: extension keys and pages
	 * (severities are hard-code anyway, so no need to get them from the database)
	 *
	 * @return	void
	 */
	function getLogFilters() {
			// Get list of existing extension keys in the log table
		$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('DISTINCT extkey', 'tx_devlog', $where_clause='', $groupBy='', $orderBy='extkey ASC');
		$this->filters['extkey'] = array('*' => '');
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres)) {
			$this->filters['extkey'][$row['extkey']] = $row['extkey'];
		}

			// Get list of existing pages in the log table
		$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery('DISTINCT pid', 'tx_devlog', '');
		$this->filters['pid'] = array('*' => '');
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres)) {
			$this->filters['pid'][$row['pid']] = $row['pid'];
		}
			// Get list of severities
		$this->filters['severity']['*'] = '';
		$this->filters['severity']['-1'] = $GLOBALS['LANG']->getLL('severity_ok');
		$this->filters['severity']['0'] = $GLOBALS['LANG']->getLL('severity_info');
		$this->filters['severity']['1'] = $GLOBALS['LANG']->getLL('severity_notice');
		$this->filters['severity']['2'] = $GLOBALS['LANG']->getLL('severity_warning');
		$this->filters['severity']['3'] = $GLOBALS['LANG']->getLL('severity_error');
	}

	/**
	 * This method is used to set the selectedLog flag
	 *
	 * @return	void
	 */
	function selectLog() {
			// If logrun is 1000, we want to display only the latest log run
			// In this case, we select the timestamp key from the latest run
		if ($this->MOD_SETTINGS['logrun'] == 1000) {
			reset($this->logRuns);
			$this->selectedLog = key($this->logRuns);
		}
			// Otherwise just take the logrun value as is
		else {
			$this->selectedLog = $this->MOD_SETTINGS['logrun'];
		} 
	}

	/**
	 * Apparently this method is designed to automatically clean up the database if there are more than maxLogRuns runs in it
	 * But since the logRuns array is itself limited by maxLogRuns (see getLogRuns() method) the clean up will never happen
	 * Unless I misunderstood something...
	 *
	 * @return	void
	 */
	function logGC() {
		if (count($this->logRuns) >= $this->maxLogRuns) {
			$keys = array_keys($this->logRuns);
			$logRun = $keys[$this->maxLogRuns-1];
			$GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_devlog', 'crmsec < '.$logRun);
		}
	}	
	
	/*******************************************
	 *
	 * 
	 *
	 *******************************************/	
	
	/**
	 * 
	 *
	 * @return	string
	 */
	function openNewView()	{
		global $BACK_PATH;
		
		$url = t3lib_div::getIndpEnv('TYPO3_REQUEST_SCRIPT');		
		$onClick = "devlogWin=window.open('".$url."','devlog','width=790,status=0,menubar=1,resizable=1,location=0,scrollbars=1,toolbar=0');devlogWin.focus();return false;";
		#$content = '<input type="button" name="openview" value="'.$GLOBALS['LANG']->getLL('open_view').'" onclick="'.htmlspecialchars($onClick).'">';
		$content = '<a id="openview" href="#" onclick="'.htmlspecialchars($onClick).'">'.
					'<img'.t3lib_iconWorks::skinImg($BACK_PATH,'gfx/open_in_new_window.gif','width="19" height="14"').' title="'.$GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_core.php:labels.openInNewWindow',1).'" class="absmiddle" '.$addAttrib.' alt="" />'.
					'</a>';
		return $content;						
	}	

	/**
	 * Assemble the link to select a single log run
	 *
	 * @return	string
	 */
	function linkLogRun($str, $logRun) {		
		$content = '<a href="?SET[logrun]='.$logRun.'">'.$str.'</a>';
		return $content;						
	}


	/*******************************************
	 *
	 * see user_setsearch
	 *
	 *******************************************/	
	 
    /**
     * Returns a linked icon with title from a record
     *
     * @param   string      Table name (tt_content,...)
     * @param   array       Record array
     * @return  string      Rendered icon
     */
    function getItemFromRecord($table, $row) {
        global $BACK_PATH, $BE_USER, $TCA;

		if (!$row['uid']) return;
		if (count($row['uid'])==1) $row = t3lib_BEfunc::getRecord($table, $row['uid']);
		
        $iconAltText = t3lib_BEfunc::getRecordIconAltText($row, $table);

            // Prepend table description for non-pages tables
        if(!($table=='pages')) {
            $iconAltText = $GLOBALS['LANG']->sl($TCA[$table]['ctrl']['title']).': '.$iconAltText;
        }

            // Create record title or rootline for pages if option is selected
        if($table=='pages' AND $this->MOD_SETTINGS['showRootline']) {
            $elementTitle = t3lib_BEfunc::getRecordPath($row['uid'], '1=1', 0);
            $elementTitle = t3lib_div::fixed_lgd_pre($elementTitle, $BE_USER->uc['titleLen']);
        } else {
            $elementTitle = t3lib_BEfunc::getRecordTitle($table, $row, 1);
        }

            // Create icon for record
        $elementIcon = t3lib_iconworks::getIconImage($table, $row, $BACK_PATH, 'class="c-recicon" title="'.$iconAltText.'"');

            // Return item with edit link
        return $this->wrapEditLink($elementIcon.$elementTitle, $table, $row['uid']);
    }


    /**
     * Wraps an edit link around a string.
     * Creates a page module link for pages, edit link for other tables.
     *
     * @param   string      The string to be wrapped
     * @param   string      Table name (tt_content,...)
     * @param   integer     uid of the record
     * @return  string      Rendered link
     */
    function wrapEditLink($str, $table, $id)    {
        global $BACK_PATH;

        if($table=='pages') {
            $editOnClick = "top.fsMod.recentIds['web']=".$id.";top.goToModule('web_layout',1);";
        } else {
            $params = '&edit['.$table.']['.$id.']=edit';
            $editOnClick = t3lib_BEfunc::editOnClick($params, $BACK_PATH);
        }
        return '<a href="#" onclick="'.htmlspecialchars($editOnClick).'">'.$str.'</a>'; ;
    }	
	
	
}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/devlog/mod1/index.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/devlog/mod1/index.php']);
}




// Make instance:
$SOBE = t3lib_div::makeInstance('tx_devlog_module1');
$SOBE->init();
$SOBE->main();
$SOBE->printContent();

?>