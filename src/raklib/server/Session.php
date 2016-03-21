<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_DISCONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\PING_DataPacket;
use raklib\protocol\PONG_DataPacket;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;

class Session{
    const STATE_UNCONNECTED = 0;
    const STATE_CONNECTING_1 = 1;
    const STATE_CONNECTING_2 = 2;
    const STATE_CONNECTED = 3;

	const MAX_SPLIT_SIZE = 128;
	const MAX_SPLIT_COUNT = 4;

    public static $WINDOW_SIZE = 2048;

    private $messageIndex = 0;
	private $channelIndex = [];

    /** @var SessionManager */
    private $sessionManager;
    private $address;
    private $port;
    private $state = self::STATE_UNCONNECTED;
    private $mtuSize = 548; //Min size
    private $id = 0;
    private $splitID = 0;

	private $sendSeqNumber = 0;
    private $lastSeqNumber = -1;

    private $lastUpdate;
    private $startTime;

	private $isTemporal = true;

    /** @var DataPacket[] */
    private $packetToSend = [];

    private $isActive;

    /** @var int[] */
    private $ACKQueue = [];
    /** @var int[] */
    private $NACKQueue = [];

    /** @var DataPacket[] */
    private $recoveryQueue = [];

	/** @var DataPacket[][] */
	private $splitPackets = [];

    /** @var int[][] */
    private $needACK = [];

    /** @var DataPacket */
    private $sendQueue;

    private $windowStart;
    private $receivedWindow = [];
    private $windowEnd;

	private $reliableWindowStart;
	private $reliableWindowEnd;
	private $reliableWindow = [];
	private $lastReliableIndex = -1;

    public function __construct(SessionManager $sessionManager, $address, $port){
        $this->sessionManager = $sessionManager;
        $this->address = $address;
        $this->port = $port;
        $this->sendQueue = new DATA_PACKET_4();
        $this->lastUpdate = microtime(true);
        $this->startTime = microtime(true);
        $this->isActive = false;
        $this->windowStart = -1;
        $this->windowEnd = self::$WINDOW_SIZE;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = self::$WINDOW_SIZE;

		for($i = 0; $i < 32; ++$i){
			$this->channelIndex[$i] = 0;
		}
    }

    public function getAddress(){
        return $this->address;
    }

    public function getPort(){
        return $this->port;
    }

    public function getID(){
        return $this->id;
    }
	
	private function timeoutLogger($packet, $prefix = false){
		$log = "";
		foreach($packet->packets as $p){
			if(is_object($p)){
				$buffer = $p->buffer;
			} else {
				$pack = EncapsulatedPacket::fromBinary($p); 
				$buffer = $pack->buffer;
			}
			if(ord($buffer{0}) == 142){
				$buffer = substr($buffer, 1);		
			}
			$pid = ord($buffer{0});
			if($pid == 0 || $pid == 3){
				continue;
			}
			$packets = array(
				"LOGIN_PACKET" => 0x8f,
				"PLAY_STATUS_PACKET" => 0x90,
				"DISCONNECT_PACKET" => 0x91,
				"BATCH_PACKET" => 0x92,
				"TEXT_PACKET" => 0x93,
				"SET_TIME_PACKET" => 0x94,
				"START_GAME_PACKET" => 0x95,
				"ADD_PLAYER_PACKET" => 0x96,
				"REMOVE_PLAYER_PACKET" => 0x97,
				"ADD_ENTITY_PACKET" => 0x98,
				"REMOVE_ENTITY_PACKET" => 0x99,
				"ADD_ITEM_ENTITY_PACKET" => 0x9a,
				"TAKE_ITEM_ENTITY_PACKET" => 0x9b,
				"MOVE_ENTITY_PACKET" => 0x9c,
				"MOVE_PLAYER_PACKET" => 0x9d,
				"REMOVE_BLOCK_PACKET" => 0x9e,
				"UPDATE_BLOCK_PACKET" => 0x9f,
				"ADD_PAINTING_PACKET" => 0xa0,
				"EXPLODE_PACKET" => 0xa1,
				"LEVEL_EVENT_PACKET" => 0xa2,
				"TILE_EVENT_PACKET" => 0xa3,
				"ENTITY_EVENT_PACKET" => 0xa4,
				"MOB_EFFECT_PACKET" => 0xa5,
				"UPDATE_ATTRIBUTES_PACKET" => 0xa6,
				"MOB_EQUIPMENT_PACKET" => 0xa7,
				"MOB_ARMOR_EQUIPMENT_PACKET" => 0xa8,
				"INTERACT_PACKET" => 0xa9,
				"USE_ITEM_PACKET" => 0xaa,
				"PLAYER_ACTION_PACKET" => 0xab,
				"HURT_ARMOR_PACKET" => 0xac,
				"SET_ENTITY_DATA_PACKET" => 0xad,
				"SET_ENTITY_MOTION_PACKET" => 0xae,
				"SET_ENTITY_LINK_PACKET" => 0xaf,
				"SET_HEALTH_PACKET" => 0xb0,
				"SET_SPAWN_POSITION_PACKET" => 0xb1,
				"ANIMATE_PACKET" => 0xb2,
				"RESPAWN_PACKET" => 0xb3,
				"DROP_ITEM_PACKET" => 0xb4,
				"CONTAINER_OPEN_PACKET" => 0xb5,
				"CONTAINER_CLOSE_PACKET" => 0xb6,
				"CONTAINER_SET_SLOT_PACKET" => 0xb7,
				"CONTAINER_SET_DATA_PACKET" => 0xb8,
				"CONTAINER_SET_CONTENT_PACKET" => 0xb9,
				"CRAFTING_DATA_PACKET" => 0xba,
				"CRAFTING_EVENT_PACKET" => 0xbb,
				"ADVENTURE_SETTINGS_PACKET" => 0xbc,
				"TILE_ENTITY_DATA_PACKET" => 0xbd,
				"FULL_CHUNK_DATA_PACKET" => 0xbf,
				"SET_DIFFICULTY_PACKET" => 0xc0,
				"SET_PLAYER_GAMETYPE_PACKET" => 0xc2,
				"PLAYER_LIST_PACKET" => 0xc3,
				"REQUEST_CHUNK_RADIUS_PACKET" => 0xc8,
				"CHUNK_RADIUS_UPDATE_PACKET" => 0xc9,
			);
			$packets = array_flip($packets);
			$log .= (isset($packets[$pid]) ? $packets[$pid] : $pid) . ", ";
		
		}
		if($prefix){
			$log = "-----------------------------------\n".$log."\n-----------------------------------\n";
		} else{
			$log .= "\n";
		}
		return $log;
	}

    public function update($time){
        if(!$this->isActive and ($this->lastUpdate + 10) < $time){
            $this->disconnect("timeout");
			$log = date("G:i:s") . "\n";
			if(isset($this->lastGetPacket)){
				$log .= $this->timeoutLogger($this->lastGetPacket, true);
			}
			$i = 0;
			foreach ($this->recoveryQueue as $recoveryQueue){
				$log .= $this->timeoutLogger($recoveryQueue);
				$i++;
				if($i >= 2){
					break;
				}
			}
			$filename = date('Y.m.d'). "_timeout_log.txt";
			
			@file_put_contents($filename, $log, FILE_APPEND | LOCK_EX);

            return;
        }
        $this->isActive = false;

        if(count($this->ACKQueue) > 0){
            $pk = new ACK();
            $pk->packets = $this->ACKQueue;
            $this->sendPacket($pk);
            $this->ACKQueue = [];
        }

        if(count($this->NACKQueue) > 0){
            $pk = new NACK();
            $pk->packets = $this->NACKQueue;
            $this->sendPacket($pk);
            $this->NACKQueue = [];
        }

        if(count($this->packetToSend) > 0){
			$limit = 16;
            foreach($this->packetToSend as $k => $pk){
                $pk->sendTime = $time;
                $pk->encode();
                $this->recoveryQueue[$pk->seqNumber] = $pk;
                unset($this->packetToSend[$k]);
                $this->sendPacket($pk);

				if(--$limit <= 0){
					break;
				}
            }

			if(count($this->packetToSend) > self::$WINDOW_SIZE){
				$this->packetToSend = [];
			}
        }

        if(count($this->needACK) > 0){
            foreach($this->needACK as $identifierACK => $indexes){
                if(count($indexes) === 0){
                    unset($this->needACK[$identifierACK]);
                    $this->sessionManager->notifyACK($this, $identifierACK);
                }
            }
        }


		foreach($this->recoveryQueue as $seq => $pk){
			if($pk->sendTime < (time() - 8)){
				$this->packetToSend[] = $pk;
				unset($this->recoveryQueue[$seq]);
			}else{
				break;
			}
		}

		foreach($this->receivedWindow as $seq => $bool){
			if($seq < $this->windowStart){
				unset($this->receivedWindow[$seq]);
			}else{
				break;
			}
		}

        $this->sendQueue();
    }

    public function disconnect($reason = "unknown"){
        $this->sessionManager->removeSession($this, $reason);
    }

    private function sendPacket(Packet $packet){
        $this->sessionManager->sendPacket($packet, $this->address, $this->port);
    }

    public function sendQueue(){
        if(count($this->sendQueue->packets) > 0){
            $this->sendQueue->seqNumber = $this->sendSeqNumber++;
			$this->sendPacket($this->sendQueue);
            $this->sendQueue->sendTime = microtime(true);
            $this->recoveryQueue[$this->sendQueue->seqNumber] = $this->sendQueue;
            $this->sendQueue = new DATA_PACKET_4();
        }
    }

    /**
     * @param EncapsulatedPacket $pk
     * @param int                $flags
     */
    private function addToQueue(EncapsulatedPacket $pk, $flags = RakLib::PRIORITY_NORMAL){
        $priority = $flags & 0b0000111;
        if($pk->needACK and $pk->messageIndex !== null){
            $this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
        }
        if($priority === RakLib::PRIORITY_IMMEDIATE){ //Skip queues
            $packet = new DATA_PACKET_0();
            $packet->seqNumber = $this->sendSeqNumber++;
	        if($pk->needACK){
		        $packet->packets[] = clone $pk;
		        $pk->needACK = false;
	        }else{
		        $packet->packets[] = $pk->toBinary();
	        }

            $this->sendPacket($packet);
            $packet->sendTime = microtime(true);
            $this->recoveryQueue[$packet->seqNumber] = $packet;

            return;
        }
        $length = $this->sendQueue->length();
        if($length + $pk->getTotalLength() > $this->mtuSize){
            $this->sendQueue();
        }

	    if($pk->needACK){
		    $this->sendQueue->packets[] = clone $pk;
		    $pk->needACK = false;
	    }else{
		    $this->sendQueue->packets[] = $pk->toBinary();
	    }
    }

    /**
     * @param EncapsulatedPacket $packet
     * @param int                $flags
     */
    public function addEncapsulatedToQueue(EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){

        if(($packet->needACK = ($flags & RakLib::FLAG_NEED_ACK) > 0) === true){
	        $this->needACK[$packet->identifierACK] = [];
        }

		if(
			$packet->reliability === 2 or
			$packet->reliability === 3 or
			$packet->reliability === 4 or
			$packet->reliability === 6 or
			$packet->reliability === 7
		){
			$packet->messageIndex = $this->messageIndex++;

			if($packet->reliability === 3){
				$packet->orderIndex = $this->channelIndex[$packet->orderChannel]++;
			}
		}

        if($packet->getTotalLength() + 4 > $this->mtuSize){
            $buffers = str_split($packet->buffer, $this->mtuSize - 34);
            $splitID = ++$this->splitID % 65536;
            foreach($buffers as $count => $buffer){
                $pk = new EncapsulatedPacket();
	            $pk->splitID = $splitID;
	            $pk->hasSplit = true;
	            $pk->splitCount = count($buffers);
	            $pk->reliability = $packet->reliability;
                $pk->splitIndex = $count;
                $pk->buffer = $buffer;
				if($count > 0){
					$pk->messageIndex = $this->messageIndex++;
				}else{
					$pk->messageIndex = $packet->messageIndex;
				}
				if($pk->reliability === 3){
					$pk->orderChannel = $packet->orderChannel;
					$pk->orderIndex = $packet->orderIndex;
				}
                $this->addToQueue($pk, $flags | RakLib::PRIORITY_IMMEDIATE);
            }
        }else{
            $this->addToQueue($packet, $flags);
        }
    }
	
	private function handleSplit(EncapsulatedPacket $packet){
		if($packet->splitCount >= self::MAX_SPLIT_SIZE or $packet->splitIndex >= self::MAX_SPLIT_SIZE or $packet->splitIndex < 0){
			return;
		}


		if(!isset($this->splitPackets[$packet->splitID])){
			if(count($this->splitPackets) >= self::MAX_SPLIT_COUNT){
				return;
			}
			$this->splitPackets[$packet->splitID] = [$packet->splitIndex => $packet];
		}else{
			$this->splitPackets[$packet->splitID][$packet->splitIndex] = $packet;
		}

		if(count($this->splitPackets[$packet->splitID]) === $packet->splitCount){
			$pk = new EncapsulatedPacket();
			$pk->buffer = "";
			for($i = 0; $i < $packet->splitCount; ++$i){
				$pk->buffer .= $this->splitPackets[$packet->splitID][$i]->buffer;
			}

			$pk->length = strlen($pk->buffer);
			unset($this->splitPackets[$packet->splitID]);

			$this->handleEncapsulatedPacketRoute($pk);
		}
	}

	private function handleEncapsulatedPacket(EncapsulatedPacket $packet){
		if($packet->messageIndex === null){
			$this->handleEncapsulatedPacketRoute($packet);
		}else{
			if($packet->messageIndex < $this->reliableWindowStart or $packet->messageIndex > $this->reliableWindowEnd){
				return;
			}

			if(($packet->messageIndex - $this->lastReliableIndex) === 1){
				$this->lastReliableIndex++;
				$this->reliableWindowStart++;
				$this->reliableWindowEnd++;
				$this->handleEncapsulatedPacketRoute($packet);

				if(count($this->reliableWindow) > 0){
					ksort($this->reliableWindow);

					foreach($this->reliableWindow as $index => $pk){
						if(($index - $this->lastReliableIndex) !== 1){
							break;
						}
						$this->lastReliableIndex++;
						$this->reliableWindowStart++;
						$this->reliableWindowEnd++;
						$this->handleEncapsulatedPacketRoute($pk);
						unset($this->reliableWindow[$index]);
					}
				}
			}else{
				$this->reliableWindow[$packet->messageIndex] = $packet;
			}
		}

	}

	public function getState(){
		return $this->state;
	}

	public function isTemporal(){
		return $this->isTemporal;
	}

    private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet){
        if($this->sessionManager === null){
            return;
        }

		if($packet->hasSplit){
			if($this->state === self::STATE_CONNECTED){
				$this->handleSplit($packet);
			}
			return;
		}

		$id = ord($packet->buffer{0});
		if($id < 0x80){ //internal data packet
			if($this->state === self::STATE_CONNECTING_2){
				if($id === CLIENT_CONNECT_DataPacket::$ID){
					$dataPacket = new CLIENT_CONNECT_DataPacket;
					$dataPacket->buffer = $packet->buffer;
					$dataPacket->decode();
					$pk = new SERVER_HANDSHAKE_DataPacket;
					$pk->address = $this->address;
					$pk->port = $this->port;
					$pk->sendPing = $dataPacket->sendPing;
					$pk->sendPong = bcadd($pk->sendPing, "1000");
					$pk->encode();

					$sendPacket = new EncapsulatedPacket();
					$sendPacket->reliability = 0;
					$sendPacket->buffer = $pk->buffer;
					$this->addToQueue($sendPacket, RakLib::PRIORITY_IMMEDIATE);
				}elseif($id === CLIENT_HANDSHAKE_DataPacket::$ID){
					$dataPacket = new CLIENT_HANDSHAKE_DataPacket;
					$dataPacket->buffer = $packet->buffer;
					$dataPacket->decode();

					if($dataPacket->port === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
						$this->state = self::STATE_CONNECTED; //FINALLY!
						$this->isTemporal = false;
						$this->sessionManager->openSession($this);
					}
				}
			}elseif($id === CLIENT_DISCONNECT_DataPacket::$ID){
				$this->disconnect("client disconnect");
			}elseif($id === PING_DataPacket::$ID){
				$dataPacket = new PING_DataPacket;
				$dataPacket->buffer = $packet->buffer;
				$dataPacket->decode();

				$pk = new PONG_DataPacket;
				$pk->pingID = $dataPacket->pingID;
				$pk->encode();

				$sendPacket = new EncapsulatedPacket();
				$sendPacket->reliability = 0;
				$sendPacket->buffer = $pk->buffer;
				$this->addToQueue($sendPacket);
			}//TODO: add PING/PONG (0x00/0x03) automatic latency measure
		}elseif($this->state === self::STATE_CONNECTED){
			$this->sessionManager->streamEncapsulated($this, $packet);

			//TODO: stream channels
		}else{
			//$this->sessionManager->getLogger()->notice("Received packet before connection: " . bin2hex($packet->buffer));
		}
	}

    public function handlePacket(Packet $packet){
        $this->isActive = true;
        $this->lastUpdate = microtime(true);
        if($this->state === self::STATE_CONNECTED or $this->state === self::STATE_CONNECTING_2){
            if($packet::$ID >= 0x80 and $packet::$ID <= 0x8f and $packet instanceof DataPacket){ //Data packet
                $packet->decode();

				if($packet->seqNumber < $this->windowStart or $packet->seqNumber > $this->windowEnd or isset($this->receivedWindow[$packet->seqNumber])){
					return;
				}

				$diff = $packet->seqNumber - $this->lastSeqNumber;

				unset($this->NACKQueue[$packet->seqNumber]);
				$this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;
				$this->receivedWindow[$packet->seqNumber] = $packet->seqNumber;

				if($diff !== 1){
					for($i = $this->lastSeqNumber + 1; $i < $packet->seqNumber; ++$i){
						if(!isset($this->receivedWindow[$i])){
							$this->NACKQueue[$i] = $i;
						}
					}
				}

				if($diff >= 1){
					$this->lastSeqNumber = $packet->seqNumber;
					$this->windowStart += $diff;
					$this->windowEnd += $diff;
				}

				foreach($packet->packets as $pk){
					$this->handleEncapsulatedPacket($pk);
				}
			}else{
                if($packet instanceof ACK){
                    $packet->decode();
                    foreach($packet->packets as $seq){
                        if(isset($this->recoveryQueue[$seq])){
                            foreach($this->recoveryQueue[$seq]->packets as $pk){
                                if($pk instanceof EncapsulatedPacket and $pk->needACK and $pk->messageIndex !== null){
                                    unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
                                }
                            }
							$this->lastGetPacket = $this->recoveryQueue[$seq];
                            unset($this->recoveryQueue[$seq]);
                        }
                    }
                }elseif($packet instanceof NACK){
                    $packet->decode();
                    foreach($packet->packets as $seq){
                        if(isset($this->recoveryQueue[$seq])){
							$pk = $this->recoveryQueue[$seq];
							$pk->seqNumber = $this->sendSeqNumber++;
                            $this->packetToSend[] = $pk;
							unset($this->recoveryQueue[$seq]);
                        }
                    }
                }
            }

        }elseif($packet::$ID > 0x00 and $packet::$ID < 0x80){ //Not Data packet :)
            $packet->decode();
            if($packet instanceof OPEN_CONNECTION_REQUEST_1){
                $packet->protocol; //TODO: check protocol number and refuse connections
                $pk = new OPEN_CONNECTION_REPLY_1();
                $pk->mtuSize = $packet->mtuSize;
                $pk->serverID = $this->sessionManager->getID();
                $this->sendPacket($pk);
                $this->state = self::STATE_CONNECTING_1;
            }elseif($this->state === self::STATE_CONNECTING_1 and $packet instanceof OPEN_CONNECTION_REQUEST_2){
                $this->id = $packet->clientID;
                if($packet->serverPort === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
                    $this->mtuSize = min(abs($packet->mtuSize), 1464); //Max size, do not allow creating large buffers to fill server memory
                    $pk = new OPEN_CONNECTION_REPLY_2();
                    $pk->mtuSize = $this->mtuSize;
                    $pk->serverID = $this->sessionManager->getID();
					$pk->clientAddress = $this->address;
                    $pk->clientPort = $this->port;
                    $this->sendPacket($pk);
                    $this->state = self::STATE_CONNECTING_2;
                }
            }
        }
    }

    public function close(){
		$data = "\x00\x00\x08\x15";
        $this->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($data), RakLib::PRIORITY_IMMEDIATE); //CLIENT_DISCONNECT packet 0x15
        $this->sessionManager = null;
    }
}