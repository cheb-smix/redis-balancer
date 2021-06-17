# RedisBalancer
## Yii2 async caching component for more then one redis server, which increases cache availability to ~99.99%

### Yii2 config example
```php
[
    'components' => [
        'cache' => [
            'class' => 'СhebSmix\RedisBalancer',
            'queueMode' => true,
            'lockTime' => 10,
            'interval' => 700,
            'servers' => [
                [ 'hostname' => 'localhost',    'port' => 6379, 'database' => 0 ],
                [ 'hostname' => '192.168.1.3',  'port' => 6379, 'database' => 0 ],
                [ 'hostname' => '192.168.1.5',  'port' => 6379, 'database' => 0 ],
            ],
        ],
    ],
]
```

### Properties description
Name            | Access Modifier | Available Values and Description
----------------|-----------|--------------------
queueMode       | public    | `true` - servers array shuffle and getting cache until successful attempt (by default)
`false` - getting cache from random server
lockTime        | public    | `>0` - will lock the key for specified number of seconds. Get-commands in a queueMode will recieve data from other servers, if mutexMode is turned off. Key will be unlocked after specified number of seconds, if the request initiator won't handle the key update (by default)
`0` - do not lock keys (not recommended)
mutexMode       | public    | `true` - flush, set, del, lock commands executing on all servers, first get-command locks the key on all servers, other get-requests are waiting for the first updates the key (not recommended)
`false` - flushdb will be executed only on first server, first get-command locks the key on first server, other get-requests are getting it from other servers (if it is no such key there, they wait until the first request finished the update). If there is only one server in array, this feature will not work.
interval        | public    | `>0` - (>300 recomended) key lock recheck interval
servers         | public    | `[]` - redis servers list
shuffledKeys    | private   | Shuffled servers keys array
shuffled        | private   | Shuffled servers array flag
source          | private   | Taken get-request source (debug-info)
lockedValues    | private   | List of keys locked by current request
