<?php
/**
 * PHPInSimMod - Connections Module
 * @package PRISM
 * @subpackage Connections
*/

define('CONNTYPE_HOST',			0);			# object is connected directly to a host
define('CONNTYPE_RELAY',		1);			# object is connected to host via relay

define('KEEPALIVE_TIME',		29);		# the time in seconds of write inactivity, after which we'll send a ping
define('HOST_TIMEOUT', 			90);		# the time in seconds of silence after we will disconnect from a host
define('HOST_RECONN_TIMEOUT',	3);
define('HOST_RECONN_TRIES',		5);

define('CONN_TIMEOUT',			10);		# host long may a connection attempt last

define('CONN_NOTCONNECTED',		0);			# not connected to the host
define('CONN_CONNECTING',		1);			# in the process of connecting to the host
define('CONN_CONNECTED',		2);			# connected to a host
define('CONN_VERIFIED',			3);			# it has been verified that we have a working insim connection

define('SOCKTYPE_BEST',			0);
define('SOCKTYPE_TCP',			1);
define('SOCKTYPE_UDP',			2);

define('STREAM_READ_BYTES',		1400);

class HostHandler extends SectionHandler
{
	private $connvars		= array();
	public $hosts			= array();			# Stores references to the hosts we're connected to

	public function initialise()
	{
		global $PRISM;
		
		if ($this->loadIniFile($this->connvars, 'connections.ini'))
		{
			foreach ($this->connvars as $hostID => $v)
			{
				if (!is_array($v))
				{
					console('Section error in connections.ini file!');
					return FALSE;
				}
			}
			if ($PRISM->config->cvars['debugMode'] & PRISM_DEBUG_CORE)
				console('Loaded connections.ini');
		}
		else
		{
			# We ask the client to manually input the connection details here.
			require_once(ROOTPATH . '/modules/prism_interactive.php');
			Interactive::queryConnections($this->connvars);
			
			# Then build a connections.ini file based on these details provided.
			if ($this->createIniFile('connections.ini', 'InSim Connection Hosts', $this->connvars))
				console('Generated config/connections.ini');
		}

		// Populate $this->hosts array from the connections.ini variables we've just read
		$this->populateHostsFromVars();
		
		return true;
	}

	private function populateHostsFromVars()
	{
		global $PRISM;
		
		$udpPortBuf = array();		// Duplicate udpPort (NLP/MCI port) value check array. Must have one socket per host to listen on.
		
		foreach ($this->connvars as $hostID => $v)
		{
			if (isset($v['useRelay']) && $v['useRelay'] > 0)
			{
				// This is a Relay connection
				$hostName		= isset($v['hostname']) ? substr($v['hostname'], 0, 31) : '';
				$adminPass		= isset($v['adminPass']) ? substr($v['adminPass'], 0, 15) : '';
				$specPass		= isset($v['specPass']) ? substr($v['specPass'], 0, 15) : '';

				// Some value checking - guess we should output some user notices here too if things go wrong.
				if ($hostName == '')
					continue;
				
				$ic				= new InsimConnection(CONNTYPE_RELAY, SOCKTYPE_TCP);
				$ic->id			= $hostID;
				$ic->ip			= $PRISM->config->cvars['relayIP'];
				$ic->port		= $PRISM->config->cvars['relayPort'];
				$ic->hostName	= $hostName;
				$ic->adminPass	= $adminPass;
				$ic->specPass	= $specPass;
				$ic->pps		= $PRISM->config->cvars['relayPPS'];
				
				$this->hosts[$hostID] = $ic;
			}
			else
			{
				// This is a direct to host connection
				$ip				= isset($v['ip']) ? $v['ip'] : '';
				$port			= isset($v['port']) ? (int) $v['port'] : 0;
				$udpPort		= isset($v['udpPort']) ? (int) $v['udpPort'] : 0;
				$pps			= isset($v['pps']) ? (int) $v['pps'] : 3;
				$adminPass		= isset($v['password']) ? substr($v['password'], 0, 15) : '';
				$socketType		= isset($v['socketType']) ? (int) $v['socketType'] : SOCKTYPE_TCP;
				
				// Some value checking
				if ($port < 1 || $port > 65535)
				{
					console('Invalid port '.$port.' for '.$hostID);
					console('Host '.$hostID.' will be excluded.');
					continue;
				}
				if ($udpPort < 0 || $udpPort > 65535)
				{
					console('Invalid port '.$udpPort.' for '.$hostID);
					console('Host '.$hostID.' will be excluded.');
					continue;
				}
				if ($pps < 1 || $pps > 100)
				{
					console('Invalid pps '.$pps.' for '.$hostID);
					console('Host '.$hostID.' will be excluded.');
					continue;
				}
				if ($socketType != SOCKTYPE_TCP && $socketType != SOCKTYPE_UDP)
				{
					console('Invalid socket type set for '.$ip.':'.$port);
					console('Host '.$hostID.' will be excluded.');
					continue;
				}
				
				// Create new ic object
				$ic				= new InsimConnection(CONNTYPE_HOST, $socketType);
				$ic->id			= $hostID;
				$ic->ip			= $ip;
				$ic->port		= $port;
				$ic->udpPort	= $udpPort;
				$ic->pps		= $pps;
				$ic->adminPass	= $adminPass;

				if ($ic->udpPort > 0)
				{
					if (in_array($ic->udpPort, $udpPortBuf))
					{
						console('Duplicate udpPort value found! Every host must have its own unique udpPort. Not using additional port for this host.');
						$ic->udpPort = 0;
					}
					else
					{
						$udpPortBuf[] = $ic->udpPort;
						if (!$ic->createMCISocket())
						{
							console('Host '.$hostID.' will be excluded.');
							continue;
						}
					}
				}

				$this->hosts[$hostID] = $ic;
			}
		}
	}
	
	public function getSelectableSockets(&$sockReads, &$sockWrites)
	{
		foreach ($this->hosts as $hostID => $host)
		{
			if ($host->connStatus >= CONN_CONNECTED)
			{
					$sockReads[] = $host->socket;
					
					// If the host is lagged, we must check to see when we can write again
					if ($host->sendQStatus > 0)
						$sockWrites[] = $host->socket;
			}
			else if ($host->connStatus == CONN_CONNECTING)
			{
				$sockWrites[] = $host->socket;
			}
			else
			{
				// Should we try to connect?
				if ($host->mustConnect > -1 && $host->mustConnect < time())
				{
					if ($host->connect()) {
						$sockReads[] = $this->hosts[$hostID]->socket;
						if ($host->socketType == SOCKTYPE_TCP)
							$sockWrites[] = $this->hosts[$hostID]->socket;
					}
				}
			}
			
			// Treat secundary socketMCI separately. This socket is always open.
			if ($host->udpPort > 0 && is_resource($host->socketMCI))
				$sockReads[] = $host->socketMCI;
		}
	}
	
	public function checkTraffic(&$sockReads, &$sockWrites)
	{
		global $PRISM;
		
		$activity = 0;
		
		// Host traffic
		foreach($this->hosts as $hostID => $host)
		{
			// Finalise a tcp connection?
			if ($host->connStatus == CONN_CONNECTING && 
				in_array($host->socket, $sockWrites))
			{
				$activity++;
				
				// Check if remote replied negatively
				# Error suppressed, because of the underlying CRT (C Run Time) producing an error on Windows.
				$nr = @stream_select($r = array($host->socket), $w = null, $e = null, 0);
				if ($nr > 0)
				{
					// Experimentation showed that if something happened on this socket at this point,
					// it is always an indication that the connection failed. We close this socket now.
					$host->close();
				}
				else
				{
					// The socket has become available for writing
					$host->connectFinish();
				}
				unset($nr, $r, $w, $e);
			}

			// Recover a lagged host?
			if ($host->connStatus >= CONN_CONNECTED && 
				$host->sendQStatus > 0 &&
				in_array($host->socket, $sockWrites))
			{
				$activity++;
				
				// Flush the sendQ and handle possible overload again
				for ($a=0; $a<$host->sendQStatus; $a++)
				{
					$bytes = $host->writeTCP($host->sendQ[$a], TRUE);
					if ($bytes == strlen($host->sendQ[$a])) {
						// an entire packet from the queue has been flushed. Remove it from the queue.
						array_shift($host->sendQ);
						$a--;

						if (--$host->sendQStatus == 0) {
							// All done flushing - reset queue variables
							$host->sendQ			= array ();
							$host->sendQTime		= 0;
							break;

						} else {
							// Set when the last packet was flushed
							$host->sendQTime		= time ();
						}
					} 
					else if ($bytes > 0)
					{
						// only partial packet sent
						$host->sendQ[$a] = substr($host->sendQ[$a], $bytes);
						break;
					}
					else
					{
						// sending queued data completely failed. We stop trying and will see if we can send more later on.
						break;
					}
				}
			}

			// Did the host send us something?
			if (in_array($host->socket, $sockReads))
			{
				$activity++;
				$data = $packet = '';
				
				// Incoming traffic from a host
				$peerInfo = '';
				$data = $host->read($peerInfo);
				
				if (!$data)
				{
					$host->close();
				}
				else
				{
					if ($host->socketType == SOCKTYPE_UDP)
					{
						// Check that this insim packet came from the IP we connected to
						// UDP packet can be sent straight to packet parser
						if ($host->connectIp.':'.$host->port == $peerInfo)
							$this->handlePacket($data, $hostID);
					}
					else
					{
						// TCP Stream requires buffering
						$host->appendToBuffer($data);
						while (true) {
							//console('findloop');
							$packet = $host->findNextPacket();
							if (!$packet)
								break;
							
							// Handle the packet here
							$this->handlePacket($packet, $hostID);
						}
					}
				}
			}

			// Did the host send us something on our separate udp port (if we have that active to begin with)?
			if ($host->udpPort > 0 && in_array($host->socketMCI, $sockReads))
			{
				$activity++;
				
				$peerInfo = '';
				$data = $host->readMCI($peerInfo);
				$exp = explode(':', $peerInfo);
				console('received '.strlen($data).' bytes on second socket');

				// Only process the packet if it came from the host's IP.
				if ($host->connectIp == $exp[0])
					$this->handlePacket($data, $hostID);
			}
		}
		
		return $activity;
	}
	
	public function maintenance()
	{
		// InSim Connection maintenance
		foreach($this->hosts as $hostID => $host)
		{
			if ($host->connStatus == CONN_NOTCONNECTED)
				continue;
			else if ($host->connStatus == CONN_CONNECTING)
			{
				// Check to see if a connection attempt is going to time out.
				if ($host->connTime < time() - CONN_TIMEOUT)
				{
					console('Connection attempt to '.$host->ip.':'.$host->port.' timed out');
					$host->close();
				}
				continue;
			}
			
			// Does the connection appear to be dead? (LFS host not sending anything for more than HOST_TIMEOUT seconds
			if ($host->lastReadTime < time () - HOST_TIMEOUT)
			{
				console('Host '.$host->ip.':'.$host->port.' timed out');
				$host->close();
			}
			
			// Do we need to keep the connection alive with a ping?
			if ($host->lastWriteTime < time () - KEEPALIVE_TIME)
			{
				$ISP = new IS_TINY();
				$ISP->SubT = TINY_NONE;
				$host->writePacket($ISP);
			}
		}
	}

	private function handlePacket(&$rawPacket, &$hostID)
	{
		global $TYPEs, $PRISM;
		
		// Check packet size
		if ((strlen($rawPacket) % 4) > 0)
		{
			// Packet size is not a multiple of 4
			console('WARNING : packet with invalid size ('.strlen($rawPacket).') from '.$hostID);
			
			// Let's clear the buffer to be sure, because remaining data cannot be trusted at this point.
			$this->hosts[$hostID]->clearBuffer();
			
			// Do we want to do anything else at this point?
			// Count errors? Disconnect host?
			// My preference would go towards counting the amount of times this error occurs and hang up after perhaps 3 errors.
			
			return;
		}
		
		# Parse Packet Header
		$pH = unpack('CSize/CType/CReqI/CData', $rawPacket);
		if (isset($TYPEs[$pH['Type']]))
		{
			if ($PRISM->config->cvars['debugMode'] & PRISM_DEBUG_CORE)
				console($TYPEs[$pH['Type']] . ' Packet from '.$hostID);
			$packet = new $TYPEs[$pH['Type']]($rawPacket);
			$this->inspectPacket($packet, $hostID);
			$PRISM->plugins->dispatchPacket($packet, $hostID);
		}
		else
		{
			console("Unknown Type Byte of ${pH['Type']}, with reported size of ${pH['Size']} Bytes and actual size of " . strlen($rawPacket) . ' Bytes.');
		}
	}
	
	// inspectPacket is used to act upon certain packets like error messages
	// We need these packets for proper basic PRISM connection functionality
	//
	private function inspectPacket(&$packet, &$hostID)
	{
		switch($packet->Type)
		{
			case ISP_VER :
				// When receiving ISP_VER we can conclude that we now have a working insim connection.
				if ($this->hosts[$hostID]->connStatus != CONN_VERIFIED)
				{
					// Because we can receive more than one ISP_VER, we only set this the first time
					$this->hosts[$hostID]->connStatus	= CONN_VERIFIED;
					$this->hosts[$hostID]->connTime		= time();
					$this->hosts[$hostID]->connTries	= 0;
					
					// Send out some info requests
					$ISP = new IS_TINY();
					$ISP->SubT = TINY_NCN;
					$ISP->ReqI = 1;
					$this->hosts[$hostID]->writePacket($ISP);
					$ISP = new IS_TINY();
					$ISP->SubT = TINY_NPL;
					$ISP->ReqI = 1;
					$this->hosts[$hostID]->writePacket($ISP);
					$ISP = new IS_TINY();
					$ISP->SubT = TINY_RES;
					$ISP->ReqI = 1;
					$this->hosts[$hostID]->writePacket($ISP);
				}
				break;

			case IRP_ERR :
				switch($packet->ErrNo)
				{
					case IR_ERR_PACKET :
						console('Invalid packet sent by client (wrong structure / length)');
						break;

					case IR_ERR_PACKET2 :
						console('Invalid packet sent by client (packet was not allowed to be forwarded to host)');
						break;

					case IR_ERR_HOSTNAME :
						console('Wrong hostname given by client');
						break;

					case IR_ERR_ADMIN :
						console('Wrong admin pass given by client');
						break;

					case IR_ERR_SPEC :
						console('Wrong spec pass given by client');
						break;

					case IR_ERR_NOSPEC :
						console('Spectator pass required, but none given');
						break;

					default :
						console('Unknown error received from relay ('.$packet->ErrNo.')');
						break;
				}
				break;
		}
	}
}

class InsimConnection
{
	private $connType;
	public $socketType;
	
	public $socket;
	public $socketMCI;						# secundary, udp socket to listen on, if udpPort > 0
											# note that this follows the exact theory of how insim deals with tcp and udp sockets
											# see InSim.txt in LFS distributions for more info
	
	public $connStatus		= CONN_NOTCONNECTED;
	public $sockErrNo		= 0;
	public $sockErrStr		= '';
	
	// Counters and timers
	public $mustConnect		= 0;
	public $connTries		= 0;
	public $connTime		= 0;
	public $lastReadTime	= 0;
	public $lastWriteTime	= 0;
	
	// TCP stream buffer
	private $streamBuf		= '';
	private $streamBufLen	= 0;
	
	// send queue used in emergency cases (if host appears lagged or overflown with packets)
	public $sendQ			= array();
	public $sendQStatus		= 0;
	public $sendQTime		= 0;
	
	// connection & host info
	public $id				= '';			# the section id from the ini file
	public $ip				= '';			# ip or hostname to connect to
	public $connectIp		= '';			# the actual ip used to connect
	public $port			= 0;			# the port
	public $udpPort			= 0;			# the secundary udp port to listen on for NLP/MCI packets, in case the main port is tcp
	public $hostName		= '';			# the hostname. Can be populated by user in case of relay.
	public $adminPass		= '';			# adminpass for both relay and direct usage
	public $specPass		= '';			# specpass for relay usage
	public $pps				= 3;		
	
	public function __construct($connType = CONNTYPE_HOST, $socketType = SOCKTYPE_TCP)
	{
		$this->connType		= ($connType == CONNTYPE_RELAY) ? $connType : CONNTYPE_HOST;
		$this->socketType	= ($socketType == SOCKTYPE_UDP) ? $socketType : SOCKTYPE_TCP;
	}
	
	public function __destruct()
	{
		$this->close(TRUE);
		if ($this->socketMCI)
			fclose($this->socketMCI);
	}
	
	public function connect()
	{
		// If we're already connected, then we'll assume this is a forced reconnect, so we'll close
		$this->close(FALSE, TRUE);
		
		// Figure out the proper IP address. We do this every time we connect in case of dynamic IP addresses.
		$this->connectIp = getIP($this->ip);
		if (!$this->connectIp)
		{
			console('Cannot connect to host, Invalid IP : '.$this->ip.':'.$this->port.' : '.$this->sockErrStr);
			$this->socket		= NULL;
			$this->connStatus	= CONN_NOTCONNECTED;
			$this->mustConnect	= -1;					// Something completely failed - we will no longer try this connection
			return FALSE;
		}
		
		if ($this->socketType == SOCKTYPE_UDP)
			$this->connectUDP();
		else
			$this->connectTCP();
		
		return true;
	}
	
	public function connectUDP()
	{
		// Create UDP socket
		$this->socket = @stream_socket_client('udp://'.$this->connectIp.':'.$this->port, $this->sockErrNo, $this->sockErrStr);
		if ($this->socket === FALSE || $this->sockErrNo)
		{
			console ('Error opening UDP socket for '.$this->connectIp.':'.$this->port.' : '.$this->sockErrStr);
			$this->socket		= NULL;
			$this->connStatus	= CONN_NOTCONNECTED;
			$this->mustConnect	= -1;					// Something completely failed - we will no longer try this connection
			return FALSE;
		}
		
		// We set the connection time here, so we can track how long we're trying to connect
		$this->connTime = time();
	
		console('Connecting to '.$this->ip.':'.$this->port.' ... #'.($this->connTries + 1));
		$this->connectFinish();
		$this->lastReadTime = time() - HOST_TIMEOUT + 10;
		
		return TRUE;		
	}
	
	public function connectTCP()
	{
		// If we're already connected, then we'll assume this is a forced reconnect, so we'll close
		$this->close(FALSE, TRUE);
	
		// Here we create the socket and initiate the connection. This is done asynchronously.
		$this->socket = @stream_socket_client('tcp://'.$this->connectIp.':'.$this->port, 
												$this->sockErrNo, 
												$this->sockErrStr, 
												CONN_TIMEOUT, 
												STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
		if ($this->socket === FALSE || $this->sockErrNo)
		{
			console ('Error opening TCP socket for '.$this->connectIp.':'.$this->port.' : '.$this->sockErrStr);
			$this->socket		= NULL;
			$this->connStatus	= CONN_NOTCONNECTED;
			$this->mustConnect	= -1;					// Something completely failed - we will no longer try this connection
			return FALSE;
		}
		
		// Set socket status to 'SYN sent'
		$this->connStatus = CONN_CONNECTING;
		// We set the connection time here, so we can track how long we're trying to connect
		$this->connTime = time();
		
		stream_set_blocking($this->socket, 0);
		
		console('Connecting to '.$this->ip.':'.$this->port.' ... #'.($this->connTries + 1));
		
		return TRUE;		
	}
	
	public function connectFinish()
	{
		// Here we finalise the connection cycle. Send an init packet to start the insim stream and while at it, detect if the socket is real.
		$this->connStatus	= CONN_CONNECTED;
		
		if ($this->connType == CONNTYPE_HOST)
		{
			// Send IS_ISI packet
			$ISP			= new IS_ISI();
			$ISP->ReqI		= TRUE;
			$ISP->UDPPort	= ($this->udpPort > 0) ? $this->udpPort : 0;
			$ISP->Flags		= ISF_LOCAL | ISF_MSO_COLS | ISF_NLP;
			$ISP->Prefix	= ord('!');
			$ISP->Interval	= round(1000 / $this->pps);
			$ISP->Admin		= $this->adminPass;
			$ISP->IName		= 'PRISM v' . PHPInSimMod::VERSION;
			$this->writePacket($ISP);
		}
		else if ($this->connType == CONNTYPE_RELAY)
		{
			// Send IR_SEL packet
			$ISP			= new IR_SEL();
			$ISP->ReqI		= TRUE;
			$ISP->HName		= $this->hostName;
			$ISP->Admin		= $this->adminPass;
			$ISP->Spec		= $this->specPass;
			$this->writePacket($ISP);
		}
		else
		{
			// I'm not sure what we connected to. Shouldn't be possible. Permanently close.
			$this->close(TRUE);
		}
		
		console('Connected to '.$this->ip.':'.$this->port);
	}
	
	public function createMCISocket()
	{
		$this->socketMCI = @stream_socket_server('udp://0.0.0.0:'.$this->udpPort, $errNo, $errStr, STREAM_SERVER_BIND);
		if (!$this->socketMCI || $errNo > 0)
		{
			console ('Error opening additional UDP socket to listen on : '.$this->sockErrStr);
			$this->socketMCI	= NULL;
			$this->udpPort		= 0;
			return FALSE;
		}
		
		console('Listening for NLP/MCI on secundary UDP port '.$this->udpPort);
		
		return TRUE;
	}
	
	// $permanentClose	- set to TRUE to close this connection once and for all.
	// $quick			- set to TRUE to bypass the reconnection mechanism. If TRUE this disconnect would not count towards the reconnection counter.
	//
	public function close($permanentClose = FALSE, $quick = FALSE)
	{
		if (is_resource($this->socket))
		{
			if ($this->connStatus == CONN_VERIFIED && $this->connType == CONNTYPE_HOST)
			{
				// Send goodbye packet to host
				$ISP		= new IS_TINY();
				$ISP->SubT	= TINY_CLOSE;
				$this->writePacket($ISP);
			}
	
			fclose($this->socket);
			console('Closed connection to '.$this->ip.':'.$this->port);
		}
		
		// (re)set some variables.
		$this->socket			= NULL;
		$this->connStatus		= CONN_NOTCONNECTED;
		$this->sendQ			= array();
		$this->sendQStatus		= 0;
		$this->sendQTime		= 0;
		$this->lastReadTime		= 0;
		$this->lastWriteTime	= 0;
		
		if ($quick)
			return;
		
		if (!$permanentClose)
		{
			if (++$this->connTries < HOST_RECONN_TRIES)
				$this->mustConnect = time() + HOST_RECONN_TIMEOUT;
			else
			{
				console('Cannot seem to connect to '.$this->ip.':'.$this->port.' - giving up ...');
				$this->mustConnect = -1;
			}
		}
		else
			$this->mustConnect = -1;
	}
	
	public function writePacket(struct &$packet)
	{
		if ($this->socketType	== SOCKTYPE_UDP)
			return $this->writeUDP($packet->pack());
		else
			return $this->writeTCP($packet->pack());
	}
	
	public function writeUDP(&$data)
	{
		$this->lastWriteTime = time();
		return @fwrite ($this->socket, $data);
	}
	
	public function writeTCP(&$data, $sendQPacket = FALSE)
	{
		$bytes = 0;
		
		if ($this->connStatus < CONN_CONNECTED)
			return $bytes;
	
		if ($sendQPacket == TRUE)
		{
			// This packet came from the sendQ. We just try to send this and don't bother too much about error checking.
			// That's done from the sendQ flushing code.
			$bytes = @fwrite ($this->socket, $data);
		}
		else
		{
			if ($this->sendQStatus == 0)
			{
				// It's Ok to send packet
				$bytes = @fwrite ($this->socket, $data);
				$this->lastWriteTime = time();
		
				if (!$bytes || $bytes != strlen($data))
				{
					console('Writing '.strlen($data).' bytes to socket '.$this->ip.':'.$this->port.' failed (wrote '.$bytes.' bytes). Error : '.(($this->connStatus == CONN_CONNECTING) ? 'Socket connection not completed.' : $this->sockErrStr).' (connStatus : '.$this->connStatus.')');
					$this->addPacketToSendQ (substr($data, $bytes));
				}
			}
			else
			{
				// Host is lagged
				$this->addPacketToSendQ ($data);
			}
		}
	
		return $bytes;
	}
	
	public function addPacketToSendQ($data)
	{
		if ($this->sendQStatus == 0)
			$this->sendQTime	= time();
		$this->sendQ[]			= $data;
		$this->sendQStatus++;
	}
	
	public function read(&$peerInfo)
	{
		$this->lastReadTime = time();
		return stream_socket_recvfrom($this->socket, STREAM_READ_BYTES, 0, $peerInfo);
	}
	
	public function readMCI(&$peerInfo)
	{
		$this->lastReadTime = time();
		return stream_socket_recvfrom($this->socketMCI, STREAM_READ_BYTES, 0, $peerInfo);
	}
	
	public function appendToBuffer(&$data)
	{
		$this->streamBuf	.= $data;
		$this->streamBufLen	= strlen ($this->streamBuf);
	}
	
	public function clearBuffer()
	{
		$this->streamBuf	= '';
		$this->streamBufLen	= 0;
	}
	
	public function findNextPacket()
	{
		if ($this->streamBufLen == 0)
			return FALSE;
		
		$sizebyte = ord($this->streamBuf[0]);
		if ($sizebyte == 0)
		{
			return FALSE;
		}
		else if ($this->streamBufLen < $sizebyte)
		{
			//console('Split packet ...');
			return FALSE;
		}
		
		// We should have a whole packet in the buffer now
		$packet					= substr($this->streamBuf, 0, $sizebyte);
		$packetType				= ord($packet[1]);
	
		// Cleanup streamBuffer
		$this->streamBuf		= substr($this->streamBuf, $sizebyte);
		$this->streamBufLen		= strlen($this->streamBuf);
		
		return $packet;
	}
	
}

function getIP(&$ip)
{
	if (verifyIP($ip))
		return $ip;
	else
	{
		$tmp_ip = @gethostbyname($ip);
		if (verifyIP($tmp_ip))
			return $tmp_ip;
	}
	
	return FALSE;
}

function verifyIP(&$ip)
{
	return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

?>