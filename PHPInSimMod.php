<?php
/* PHPInSimMod
*
* by the PHPInSimMod Development Team.
*
*/

/* Defines */
// PRISM
define('PRISM_DEBUG_CORE',		1);			# Shows Debug Messages From the Core
define('PRISM_DEBUG_SOCKETS',	2);			# Shows Debug Messages From the Sockets Module
define('PRISM_DEBUG_MODULES',	4);			# Shows Debug Messages From the all Modules
define('PRISM_DEBUG_PLUGINS',	8);			# Shows Debug Messages From the Plugins
define('PRISM_DEBUG_ALL',		15);		# Shows Debug Messages From All

define('MAINTENANCE_INTERVAL', 	2);			# The frequency in seconds to do connection maintenance checks.

// Return Codes: 
define('PLUGIN_CONTINUE',		0);			# Plugin passes through operation. Whatever called it continues.
define('PLUGIN_HANDLED',		1);			# Plugin halts continued operation. Plugins following in the plugins.ini won't be called.

error_reporting(E_ALL);
ini_set('display_errors',		'true');

define('ROOTPATH', dirname(realpath(__FILE__)));

// the packets and connections module are two of the three REQUIRED modules for PRISM.
require_once(ROOTPATH . '/modules/prism_functions.php');
require_once(ROOTPATH . '/modules/prism_config.php');
require_once(ROOTPATH . '/modules/prism_packets.php');
require_once(ROOTPATH . '/modules/prism_connections.php');
require_once(ROOTPATH . '/modules/prism_plugins.php');

$PRISM = new PHPInSimMod();
$PRISM->initialise($argc, $argv);
$PRISM->start();

/**
 * PHPInSimMod
 * @package PRISM
 * @author Dygear (Mark Tomlin) <Dygear@gmail.com>
 * @author ripnet (Tom Young) <ripnet@gmail.com>
 * @author morpha (Constantin Köpplinger) <morpha@xigmo.net>
 * @author Victor (Victor van Vlaardingen) <vic@lfs.net>
*/
class PHPInSimMod
{
	const VERSION = '0.1.9';
	const ROOTPATH = ROOTPATH;

	/* Run Time Arrays */
	public $config				= null;
	public $hosts				= null;
//	public $http				= new HttpHandler();
	public $plugins				= null;
//	public $users				= new UserHandler();
	
	public $curHostID		= NULL;				# Contains the current HostID we are talking to. (For the plugins::sendPacket method).

	# Time outs
	private $sleep				= NULL;
	private $uSleep				= NULL;
	
	private $nextMaintenance	= 0;
	public $isWindows			= FALSE;

	// Main while loop will run as long as this is set to TRUE.
	private $isRunning			= TRUE;

	// Real Magic Functions
	public function __construct()
	{
		// This reregisters our autoload magic function into the class.
		spl_autoload_register(__CLASS__ . '::_autoload');
		set_error_handler(__CLASS__ . '::_errorHandler', E_ALL | E_STRICT);
		
		// Windows OS check
		$shell = getenv('SHELL');
		if (!$shell || $shell[0] != '/')
			$this->isWindows = TRUE;

		$this->config	= new ConfigHandler();
		$this->hosts	= new HostHandler();
		$this->plugins	= new PluginHandler();
	}

	// Pseudo Magic Functions
	private static function _autoload($className)
	{
		require_once(ROOTPATH . "/modules/prism_{$className}.php");
	}

	public static function _errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
	{
		# This error code is not included in error_reporting
		if (!(error_reporting() & $errno))
			return;

		switch ($errno)
		{
			case E_ERROR:
			case E_USER_ERROR:
					echo 'PHP ERROR:'.PHP_EOL;
					$andExit = TRUE;
				break;
			case E_WARNING:
			case E_USER_WARNING:
					echo 'PHP WARNING:'.PHP_EOL;
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
					echo 'PHP NOTICE:'.PHP_EOL;
				break;
			case E_STRICT:
					echo 'PHP STRICT:'.PHP_EOL;
				break;
			default:
					echo 'UNKNOWN:'.PHP_EOL;
				break;
		}

		echo "\t$errstr in $errfile on line $errline".PHP_EOL;

		$trace = debug_backtrace();
		foreach ($trace as $index => $call)
		{
			if ($call['function'] == 'main') break;
			if ($index > 0 && isset($call['file']) && isset($call['line']))
			{
				console("\t".$index.' :: '.$call['function'].' in '.$call['file'].':'.$call['line']);
			}
		}

		if (isset($andExit) && $andExit == TRUE)
			exit(1);

		# Don't execute PHP internal error handler
		return true;
	}

	public function initialise($argc, $argv)
	{
		// Set the timezone
		if (isset($this->cvars['defaultTimeZone']))
			date_default_timezone_set($this->cvars['defaultTimeZone']);
		else
		{
			# I know, I'm using error suppression, but I swear it's appropriate!
			$timeZoneGuess = @date_default_timezone_get();
			date_default_timezone_set($timeZoneGuess);
			unset($timeZoneGuess);
		}
		
		// Load ini files
		if (!$this->loadIniFiles())
		{
			console('Fatal error encountered. Exiting...');
			exit(1);
		}
		
		if (
			(($pluginsLoaded = $this->plugins->loadPlugins()) == 0) &&
			($this->cvars['debugMode'] & PRISM_DEBUG_CORE))
		{
			console('No Plugins Loaded');
		} else if ($pluginsLoaded == 1) {
			console('One Plugin Loaded');
		} else {
			console("{$pluginsLoaded} Plugins Loaded.");
		}
				
		$this->nextMaintenance = time () + MAINTENANCE_INTERVAL;
	}
		
	private function loadIniFiles()
	{
		// Load generic cvars.ini
		if (!$this->config->initialise())
			return false;
		
		// Load connections.ini
		if (!$this->hosts->initialise())
			return false;

		// Load plugins.ini
		if (!$this->plugins->initialise())
			return false;

		return TRUE;
	}
	
	public function start()
	{
		$this->main();
	}

	private function main()
	{
		while ($this->isRunning === TRUE)
		{
			// Setup our listen arrays
			$sockReads = array();
			$sockWrites = array();
			
			if (!$this->isWindows)
				$sockReads[] = STDIN;
			
			// Add host sockets to the arrays as needed
			// While at it, check if we need to connect to any of the hosts.
			$this->hosts->getSelectableSockets($sockReads, $sockWrites);
			
			$this->getSelectTimeOut();

			# Error suppressed used because this function returns a "Invalid CRT parameters detected" only on Windows.
			$numReady = @stream_select($sockReads, $sockWrites, $socketExcept = null, $this->sleep, $this->uSleep);
				
			// Keep looping until you've handled all activities on the sockets.
			while($numReady > 0)
			{
				$numReady -= $this->hosts->checkTraffic($sockReads, $sockWrites);
				
				// KB input
				if (in_array (STDIN, $sockReads))
				{
					$numReady--;
					$kbInput = trim(fread (STDIN, STREAM_READ_BYTES));
					
					// Split up the input
					$exp = explode (' ', $kbInput);
	
					// Process the command (the first char or word of the line)
					switch ($exp[0])
					{
						case 'h':
							foreach ($this->hosts->hosts as $hostID => $host)
							{
								console($hostID.' => '.$host->ip.':'.$host->port.(($host->udpPort > 0) ? '+udp'.$host->udpPort : '').' -> '.(($host->connStatus == CONN_CONNECTED) ? '' : (($host->connStatus == CONN_VERIFIED) ? 'verified &' : 'not')).' connected');
							}
							break;
						
						case 'p':
							console("#\tName\tVersion\tAuthor\tDescription");
							foreach ($this->plugins as $pluginID => $plugin)
							{
								console($pluginID."\t#".$plugin::NAME."\t".$plugin::VERSION."\t".$plugin::AUTHOR."\t".$plugin::DESCRIPTION);
							}
							break;
						
						case 'x':
							$this->isRunning = FALSE;
							break;
						
						case 'w':
							for ($k=0; $k<$this->httpNumClients; $k++)
							{
								$lastAct = time() - $this->httpClients[$k]->lastActivity;
								console($this->httpClients[$k]->ip.':'.$this->httpClients[$k]->port.' - last activity was '.$lastAct.' second'.(($lastAct = 1) ? '' : 's').' ago.');
							}
							console('Counted '.$this->httpNumClients.' http client'.(($this->httpNumClients == 1) ? '' : 's'));
							break;
						
						default :
							console('Available Commands:');
							console('h - show host info');
							console('p - show plugin info');
							console('x - exit PHPInSimMod');
							console('w - show www connections');
					}
				}
				
			} // End while(numReady)
			
			// No need to do the maintenance check every turn
			if ($this->nextMaintenance > time ())
				continue;
			$this->nextMaintenance = time () + MAINTENANCE_INTERVAL;
			$this->hosts->maintenance();
						
		} // End while(isRunning)
	}

	public function sendPacket($packetClass, $HostId = FALSE)
	{
		if ($HostId === FALSE)
			return $this->hosts->hosts[$this->curHostID]->writePacket($packetClass);
		else
			return $this->hosts->hosts[$HostId]->writePacket($packetClass);
	}

	private function getSelectTimeOut()
	{
		# If timer & cron array is empty, set the Sleep & uSleep to NULL.
		# Else set the timeout to the detla of now as compared to the next timer or cronjob event, what ever is smaller.
		# A Cron Jobs distance to now will have to be recalcuated after each socket_select call, well do that here also.

		// Must have a max delay of a second, otherwise there is no connection maintenance done.
		$this->sleep = 1;
		$this->uSleep = null;
	}

	public function __destruct()
	{
		console('Safe shutdown: ' . date($this->config->cvars['logFormat']));
	}
}

?>