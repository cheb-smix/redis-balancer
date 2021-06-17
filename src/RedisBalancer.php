<?php
namespace СhebSmix\RedisBalancer;

use yii\di\Instance;
use yii\redis\Connection;


class RedisBalancer extends \yii\caching\Cache
{
    public $queueMode = true;
    public $lockTime = 30;
    public $mutexMode = false;
    public $interval = 500;
    public $servers = [];

    private $shuffledKeys = [];
    private $shuffled = false;
    private $source = "";
    private $lockedValues = [];


    public function __destruct()
    {
        foreach ($this->lockedValues as $key => $hash) {
            if ($this->isLockedValue($key)) $this->unlockValue($key);
        }
    }


    public function init()
    {
        parent::init();
        foreach ($this->servers as $i => $server) {
            $this->servers[$i] = Instance::ensure($server, Connection::className());
        }
    }


    public function getLastSource()
    {
        return substr($this->source, -2, 2);
    }


    public function getSyncPercent()
    {
        if (count($this->servers) < 2) return 100.00;
        if (count($this->servers) == 0) return 0;
        
        $KEYS = [];
        foreach ($this->servers as $server) {
            $KEYS = array_merge($KEYS, $this->executeCommand($server, 'KEYS *'));
        }
        $KEYS = array_unique($KEYS);
        
        $synched = 0;
        foreach ($KEYS as $key) {
            $synched += $this->synchronized($key) ? 1 : 0;
        }
        return round($synched * 100 / count($KEYS), 2);
    }


    public function synchronized($key)
    {
        if (count($this->servers) == 0) return false;

        $key = $this->buildKey($key);

        $values = [];

        foreach ($this->servers as $server) {
            $values[] = $this->executeCommand($server, 'GET', [ $key ]);
        }

        return count(array_unique($values)) == 1;
    }


    
    public function exists($key)
    {
        if (count($this->servers) == 0) return false;
        
        $key = $this->buildKey($key);

        $this->shuffleKeys();

        if (count($this->shuffledKeys) == 0) return false;


        if ($this->queueMode) {

            foreach ($this->shuffledKeys as $i => $index) {
                $server = $this->servers[$index];
                if ($this->executeCommand($server, 'EXISTS', [ $key ])) {
                    if ($this->isLockedValue($key) === false) return true;
                }
            }

        } else {
            if ($server = $this->servers[current($this->shuffledKeys)]) {
                return (bool) $server->executeCommand('EXISTS', [ $key ]);
            }
        }

        return false;
    }


    public function lock(string $key, int $lockTime = 0)
    {
        $lockTime = $lockTime === 0 ? $this->lockTime : $lockTime;

        if (count($this->servers) == 0 || $lockTime == 0) return false;
        
        $key = $this->buildKey($key);
        
        $hash = sha1($key . microtime());

        $result = false;
        
        foreach ($this->servers as $server) {
            $result = (bool)$this->executeCommand($server, 'SET', [$key, "LOCK|||$hash", 'NX', 'EX', $lockTime]);
            if (!$this->mutexMode) break;
        }
        if ($result) {
            $this->lockedValues[$key] = $hash;
        }
        return $result;
    }


    public function getStat()
    {
        $stat = [];
        $commands = ["SERVER", "CLIENTS", "STATS", "COMMANDSTATS", "KEYSPACE"];

        foreach ($commands as $cmd) {

            $stat[$cmd] = [];

            foreach ($this->servers as $server) {
                $stat[$cmd][$server->hostname] = $this->executeCommand($server, $cmd);
            }

        }

        return $stat;
        
    }








    protected function getValue($key, $try1 = true)
    {
        if (count($this->servers) == 0) return false;
        $this->shuffleKeys();
        if (count($this->shuffledKeys) == 0) return false;

        if ($isLocked = $this->isLockedValue($key)) {
            $this->runSleeping($key);
        }

        if ($this->queueMode) {
            $val = false;
            foreach ($this->shuffledKeys as $i => $index) {
                if (!$this->mutexMode && $isLocked && $index == key($this->servers)) continue;

                if (!$this->mutexMode && $isLocked === "") {
                    $this->lock($key);
                    return false;
                }

                $server = $this->servers[$index];
                $val = $this->executeCommand($server, 'GET', [$key]);

                if ($val) {

                    $this->source = $server->hostname;
                    break;

                } elseif ($val === false) {

                    unset($this->servers[$index], $this->shuffledKeys[$i]);

                } elseif ($val === null) {

                    if (!$this->mutexMode && !$isLocked) {
                        $this->lock($key);
                        return false;
                    }

                }

            }

            if (!$this->mutexMode && $try1 && !$val && $isLocked) {
                $this->runSleeping($key, true);
                $val = $this->getValue($key, false);
            }

            return $val;

        }

        if ($server = $this->servers[current($this->shuffledKeys)]) {
            return (bool) $server->executeCommand('GET', [ $key ]);
        }
        
        return false;
    }


    protected function getValues($keys, $try1 = true)
    {
        if (count($this->servers) == 0) return false;
        $response = [];
        $this->shuffleKeys();
        if (count($this->shuffledKeys) == 0) return false;
        
        if ($isLocked = $this->isLockedValue($key)) {
            $this->runSleeping($key);
        }


        if ($this->queueMode) {

            foreach ($this->shuffledKeys as $i => $index) {

                if (!$this->mutexMode && $isLocked && $index == key($this->servers)) continue;

                $server = $this->servers[$index];
                $response = $this->executeCommand($server, 'MGET', $keys);

                if ($response) {
                    $this->source = $server->hostname;
                    break;
                } elseif ($response === false) {
                    unset($this->servers[$index], $this->shuffledKeys[$i]);
                    $response = [];
                } elseif ($val === null) {

                    if (!$this->mutexMode && !$isLocked) {
                        $this->lock($key);
                        $response = [];
                        break;
                    }

                }

            }

            if (!$this->mutexMode && $try1 && !$val && $isLocked) {
                $this->runSleeping($key, true);
                $response = $this->getValue($key, false);
            }

        } else {
            if ($server = $this->servers[current($this->shuffledKeys)]) {
                $response = $server->executeCommand('MGET', $keys);
            }
        }

        if (!$response) return false;

        $result = [];
        $i = 0;
        foreach ($keys as $key) {
            $result[$key] = $response[$i++];
        }

        return $result;
    }


    protected function setValue($key, $value, $expire, $mode = "SET")
    {
        if (count($this->servers) == 0) return false;
        
        if ($expire == 0) {
            $setArr = [ $key, $value ];
        } else {
            $setArr = [ $key, $value, 'EX', (int)$expire ];
        }

        if ($mode == "ADD") {
            $setArr[] = 'NX';
            $this->unlockValue($key);
        } else {
            if (!$this->isAllowedToSet($key)) {
                return false;
            } else {
                if (isset($this->lockedValues[$key])) unset($this->lockedValues[$key]);
            }
        }

        $result = false;

        foreach ($this->servers as $server) {
            
            $result = $this->executeCommand($server, 'SET', $setArr);
        }

        return (bool) $result;
    }


    protected function setValues($data, $expire)
    {
        if (count($this->servers) == 0) return false;
        
        $args = [];
        foreach ($data as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        $failedKeys = [];

        if ($expire == 0) {
            foreach ($this->servers as $server) {
                $this->executeCommand($server, 'MSET', $args);
            }
        } else {

            $expire = (int) ($expire * 1000);

            foreach ($this->servers as $server) {
                $this->executeCommand($server, 'MULTI');
                $this->executeCommand($server, 'MSET', $args);
                $index = [];
                foreach ($data as $key => $value) {
                    $this->executeCommand($server, 'PEXPIRE', [$key, $expire]);
                    $index[] = $key;
                }
                $result = $this->executeCommand($server, 'EXEC');
                array_shift($result);
                foreach ($result as $i => $r) {
                    if ($r != 1) {
                        $failedKeys[] = $index[$i];
                    }
                }
            }

        }

        return $failedKeys;
    }


    protected function addValue($key, $value, $expire)
    {
        return $this->setValue($key, $value, $expire, "ADD");
    }


    protected function deleteValue($key)
    {
        $result = false;
        foreach ($this->servers as $server) {
            $result = $this->executeCommand($server, 'DEL', [$key]);
        }
        return (bool) $result;
    }


    protected function flushValues()
    {
        $result = false;
        foreach ($this->servers as $server) {
            $result = $this->executeCommand($server, 'FLUSHDB');
            if (!$this->mutexMode) break;
        }
        return (bool) $result;
    }


    protected function unlockValue(string $key)
    {        
        if (!$this->lockTime) return true;
        if ($this->isAllowedToSet($key)) {
            $result = $this->deleteValue($key);
            if ($result && isset($this->lockedValues[$key])) {
                unset($this->lockedValues[$key]);
            }
            return $result;
        }
        return false;
    }


    protected function isAllowedToSet(string $key)
    {
        $isLocked = $this->isLockedValue($key);
        return (!$isLocked || (isset($this->lockedValues[$key]) && $isLocked == "LOCK|||{$this->lockedValues[$key]}"));
    }


    protected function isLockedValue(string $key)
    {
        foreach ($this->servers as $server) {
            $val = $this->executeCommand($server, 'GETRANGE', [$key, 0, 46]);
            if ($val === "" || substr($val, 0, 7) == "LOCK|||") return $val;
        }

        return false;
    }


    protected function runSleeping($key, $force = false)
    {
        if ($this->lockTime > 0 && ($this->mutexMode || count($this->servers) == 1) || $force) {

            usleep($this->interval * 1000);
            while ($this->isLockedValue($key)) usleep($this->interval * 1000);
            
        }
    }


    protected function shuffleKeys()
    {
        if (!$this->shuffled) {
            $this->shuffledKeys = array_keys($this->servers);
            $this->shuffled = shuffle($this->shuffledKeys);
        }
    }


    protected function executeCommand($server = null, $command = null, $keys = [])
    {
        if (!$server) return false;
        if (!$command) return null;

        try {
            return $server->executeCommand($command, $keys);
        } catch (\Exception $e) {
            return false;
        }
    }

}