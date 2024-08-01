<?php
declare(strict_types=1);
/**
 * Plugin Name: MadMax PHP Symfony/Doctrine/Illuminate/phpFastache Integration Caching Plugin
 * Description: A simple plugin that allows you to choose the caching mechanism and enable/disable page caching, object caching, and transient caching
 * Author: MadMax & GPT4
 * Version: 1.21 a
 */
// Ensure proper inclusion of WordPress functions and security checks
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly for security
}
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

// Define the FILE constant if it's not already defined
if (!defined('FILE')) {
    define('FILE', __FILE__);
}

define('MYPLUGIN_PATH', plugin_dir_path(__FILE__));
define('MYPLUGIN_URL', plugin_dir_url(__FILE__));

// Define WP_CONTENT_DIR if it's not already defined
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
}

// Define the base directory for cached assets.
if (!defined('MADMAX_BASE_CACHE_DIR')) {
    define('MADMAX_BASE_CACHE_DIR', WP_CONTENT_DIR . '/cache/madmax-cache');
}

// Define the base URL for cached assets.
if (!defined('MADMAX_BASE_CACHE_URL')) {
    define('MADMAX_BASE_CACHE_URL', WP_CONTENT_URL . '/cache/madmax-cache');
}

require_once(ABSPATH . "wp-admin" . '/includes/image.php');
require_once(ABSPATH . "wp-admin" . '/includes/file.php');
require_once(ABSPATH . "wp-admin" . '/includes/media.php');
require_once(ABSPATH . "wp-admin" . '/includes/plugin.php');
require_once(ABSPATH . "wp-includes" . '/pluggable.php');
require_once(ABSPATH . "wp-load.php");

// Include library
require_once(ABSPATH . "/vendor/autoload.php");

// For minify
use MatthiasMullie\Minify;
use voku\helper\HtmlMin;

// For Symfony Cache
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Simple\FilesystemCache as SystemCache;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

// For Laminas Cache
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\Memcached;
use Laminas\Cache\Storage\Adapter\Redis;
use Laminas\Cache\Storage\Adapter\PdoSqlite;
use Laminas\Cache\Storage\Adapter\Apcu;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Cache\Storage\Adapter\SQLite;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Adapter\MemcachedOptions;
use Laminas\Cache\Storage\Adapter\RedisOptions;
use Laminas\Cache\Storage\Adapter\PdoSqliteOptions;
use Laminas\Cache\Storage\Adapter\ApcuOptions;

// For Stash Cache
use Stash\Pool as StashPool;
use Stash\Driver\Ephemeral as StashEphemeral;
use Stash\Driver\FileSystem as StashFileSystem;
use Stash\Driver\Memcache as StashMemcache;
use Stash\Driver\Memcached as StashMemcached;
use Stash\Driver\SQLite as StashSQLite;
use Stash\Driver\Redis as StashRedis;

// For Doctrine Cache
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\SQLite3Cache;
use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\DriverManager;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\PhpFileCache;

// For PhpFastCache
use phpFastCache\Helper\Psr16Adapter;
use phpFastCache\CacheManager;
use phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface;
use phpFastCache\Drivers\Apcu\Driver as ApcuDriver;
use phpFastCache\Drivers\Redis\Driver as RedisDriver;
use phpFastCache\Drivers\Memcached\Driver as MemcachedDriver;
use phpFastCache\Drivers\Sqlite\Driver as SqliteDriver;
use phpFastCache\Drivers\Files\Driver as FilesDriver;
use phpFastCache\Drivers\Apcu\Config as ApcuConfig;
use phpFastCache\Drivers\Files\Config as FilesConfig;
use phpFastCache\Drivers\Memcached\Config as MemcachedConfig;
use phpFastCache\Drivers\Redis\Config as RedisConfig;
use phpFastCache\Drivers\Sqlite\Config as SqliteConfig;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

// For Illuminate
use Illuminate\Cache\CacheManager as ManagerCache;
use Illuminate\Filesystem\FilesystemAdapter as SystemFileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\ApcuStore;
use Illuminate\Cache\SQLiteStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Redis\RedisManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache as IlluminateCache;

// Library and backend will be set from admin options
$library = 'phpFastCache'; // Example value, could be dynamically set based on admin options
$backend = ''; // To be dynamically set based on admin options or automatic detection

// Initial cache instance placeholder
$cache = '';

// Dynamically adjust the caching strategy based on server capabilities or admin preferences
function choose_cache_system() {
    global $backend;
    
    // Priority order: Redis > Memcached > APCu > Opcache > SQLite > Files
    if (extension_loaded('redis')) {
        $backend = 'redis';
    } elseif (extension_loaded('memcached')) {
        $backend = 'memcached';
    } elseif (extension_loaded('apcu')) {
        $backend = 'apcu';
    } elseif (function_exists('opcache_get_status')) {
        $backend = 'files'; // Note: Opcache is for opcode caching and doesn't directly store data like other caches
    } elseif (extension_loaded('sqlite3')) {
        $backend = 'sqlite';
    } else {
        $backend = 'files';
    }
}

// Setup the caching system based on the chosen library and backend
// Setup the caching system based on the chosen library and backend
function madmax_phpfastcache_setup($library, $backend) {
    // Configurations for different caching systems
    $config = [
        // Configurations for different caching systems
        'redis' => [
            'host' => '127.0.0.1 ,
            'port' => '6379',
            'password' => '',
            'database' => '0',
            'timeout' => '5',
        ],
        'memcached' => [
            'host' => '127.0.0.1 ,
            'port' => '11211',
            'sasl_user' => '', // Optional: SASL username for secured Memcached servers
            'sasl_password' => '', // Optional: SASL password for secured Memcached servers
        ],
        'apcu' => [
            'itemDetailedDate' => false, // Optional: Whether to store detailed creation and modification times for cache items
        ],
        // Include configurations for other backends as necessary
        'files' => [
            'path' => WP_CONTENT_DIR . "/cache/",
        ],
    ];

    // Initialize the appropriate cache instance based on backend
    switch ($backend) {
        case 'redis':
            // Initialize Redis cache instance using provided configurations
            $InstanceCache = new RedisCache($config['redis']['host'], $config['redis']['port']);
            // Additional Redis configuration settings can be applied here
            break;
        case 'memcached':
            // Initialize Memcached cache instance using provided configurations
            $InstanceCache = new MemcachedCache();
            $InstanceCache->setServers([$config['memcached']['host'] . ':' . $config['memcached']['port']]);
            // Additional Memcached configuration settings can be applied here
            break;
        case 'apcu':
            // Initialize APCu cache instance
            $InstanceCache = new ApcuCache();
            // Additional APCu configuration settings can be applied here
            break;
        case 'sqlite':
            // Initialize SQLite cache instance
            $InstanceCache = new SQLiteCache();
            // Additional SQLite configuration settings can be applied here
            break;
        case 'files':
        default:
            // Initialize file-based cache instance
            $InstanceCache = new FileCache($config['files']['path']);
            // Additional file cache configuration settings can be applied here
            break;
    }

    return $InstanceCache;
}

// Determine the best cache system based on the server's capabilities or admin settings
choose_cache_system();

// Setup cache instance based on the library and backend, now dynamically chosen or set by admin
$cacheInstance = madmax_phpfastcache_setup($library, $GLOBALS['backend']);

class ExtendedCacheItem implements CacheItemInterface {
    private $key;
    private $value;
    private $hit;
    private $expiration;

    public function __construct($key, $value = null, $hit = false) {
        $this->key = $key;
        $this->value = $value;
        $this->hit = $hit;
        $this->expiration = new \DateTime();
    }

	    public function getKey() {
	        return $this->key;
	    }

	    public function get() {
	        return $this->value;
	    }

	    public function isHit() {
	        return $this->hit;
	    }

	    public function set($value) {
	        $this->value = $value;
	        return $this;
	    }

	    public function expiresAt($expiration) {
	        $this->expiration = $expiration;
	        return $this;
	    }

	    public function expiresAfter($time) {
	        $this->expiration = new \DateTime('+'. $time .' seconds');
	        return $this;
	    }

	    // Add any other methods that you need here
}

class CacheWrapper_1 {
    private $cache;

    public function __construct($cache) {
        $this->cache = $cache;
    }

    public function getItem($key) {
        if ($this->cache instanceof \Symfony\Component\Cache\Adapter\AbstractAdapter) {
            $item = $this->cache->getItem($key);
            return new ExtendedCacheItem($key, $item->get(), $item->isHit());
        } elseif ($this->cache instanceof \Doctrine\Common\Cache\Cache) {
            $data = $this->cache->fetch($key);
            $hit = $this->cache->contains($key);
            return new ExtendedCacheItem($key, $data, $hit);
        } else {
            $item = $this->cache->getItem($key);
            return new ExtendedCacheItem($key, $item->get(), $item->isHit());
        }
    }

    public function save(ExtendedCacheItem $item) {
        if ($this->cache instanceof \Symfony\Component\Cache\Adapter\AbstractAdapter) {
            $symfonyItem = $this->cache->getItem($item->getKey());
            $symfonyItem->set($item->get());
            $symfonyItem->expiresAt($item->getExpiration());
            return $this->cache->save($symfonyItem);
        } elseif ($this->cache instanceof \Doctrine\Common\Cache\Cache) {
            return $this->cache->save($item->getKey(), $item->get(), $item->getExpiration()->getTimestamp() - time());
        } else {
            $phpFastCacheItem = $this->cache->getItem($item->getKey());
            $phpFastCacheItem->set($item->get());
            $phpFastCacheItem->expiresAt($item->getExpiration());
            return $this->cache->save($phpFastCacheItem);
        }
    }

    public function setItem($key, $value, $expiration = null) {
         $item = new ExtendedCacheItem($key);
         $item->set($value);

         if ($expiration !== null) {
             $item->expiresAfter($expiration);
         }

         return $this->save($item);
     }

     public function delete($key) {
         if ($this->cache instanceof \Symfony\Component\Cache\Adapter\AbstractAdapter) {
             return $this->cache->delete($key);
         } elseif ($this->cache instanceof \Doctrine\Common\Cache\Cache) {
             return $this->cache->delete($key);
         } else {
             return $this->cache->deleteItem($key);
         }
     }

     public function clear() {
         if ($this->cache instanceof \Symfony\Component\Cache\Adapter\AbstractAdapter) {
             return $this->cache->clear();
         } elseif ($this->cache instanceof \Doctrine\Common\Cache\Cache) {
             return $this->cache->deleteAll();
         } else {
             return $this->cache->clear();
         }
     }

     public function isHit($key) {
         $item = $this->getItem($key);
         return $item->isHit();
     }
}

class CacheAdapter {
    private $library;
    private $backend;
    private $cache;
    private $configurations;

    public function __construct($library, $backend, array $configurations = []) {
        $this->library = $this->sanitizeKey($library);
        $this->backend = $this->sanitizeKey($backend);
        $this->configurations = $configurations;
        $this->initializeCache();
    }

    private function sanitizeKey($key) {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $key);
    }

    protected function ensureDirectoryExists($directory) {
        if (!file_exists($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
    }

    protected function ensureFileExists($filePath) {
        if (!file_exists($filePath)) {
            if (false === @touch($filePath)) {
                throw new \RuntimeException(sprintf('File "%s" was not created', $filePath));
            }
        }
    }

    private function handleInitializationError(\Exception $e) {
        error_log("Cache initialization error for {$this->library}: {$this->backend} - " . $e->getMessage());
        throw $e;
    }

    private function initializeCache() {
        try {
            switch ($this->library) {
                case 'symfony':
                    $this->initializeSymfonyCache($this->backend);
                    break;

                case 'stash':
                    $this->initializeStashCache($this->backend);
                    break;

                case 'laminas':
                    $this->initializeLaminasCache($this->backend);
                    break;

                case 'doctrine':
                    $this->initializeDoctrineCache($this->backend);
                    break;

                case 'phpfastcache':
                    $this->initializePhpFastCache($this->backend, $this->configurations);
                    break;

                case 'illuminate':
                    $this->initializeIlluminateCache($this->backend, $this->configurations);
                    break;

                default:
                    throw new \InvalidArgumentException("Invalid caching library provided: {$this->library}");
            }
        } catch (\InvalidArgumentException $e) {
            $this->handleInitializationError($e);
        } catch (\Exception $e) {
            $this->handleInitializationError($e);
        }
    }

private function initializeSymfonyCache($backend) {
    try {
        switch ($backend) {
            case 'files':
                $this->initializeFilesystemCache();
                break;

            case 'memcached':
                $this->initializeMemcachedCache();
                break;

            case 'redis':
                $this->initializeRedisCache();
                break;

            case 'sqlite':
                $this->initializeSqliteCache();
                break;

            case 'apcu':
                $this->cache = new ApcuAdapter();
                break;

            default:
                throw new \InvalidArgumentException("Unsupported cache backend: $backend");
        }
    } catch (\Symfony\Component\Cache\Exception\CacheException $e) {
        $this->handleInitializationError($e);
    } catch (\InvalidArgumentException $e) {
        $this->handleInitializationError($e);
    }
}

// Example of refactored method for initializing filesystem cache
private function initializeFilesystemCache() {
    $cacheDirectory = WP_CONTENT_DIR . '/cache';
    $this->ensureDirectoryExists($cacheDirectory);
    $this->cache = new FilesystemAdapter('', 0, $cacheDirectory);
}

// Initializes Memcached Cache
private function initializeMemcachedCache() {
    $client = MemcachedAdapter::createConnection(
        ['memcached://10.75.32.72:11211'],
        [
            'binary_protocol' => true,
            'compression' => true,
            'connect_timeout' => 1000,
            'poll_timeout' => 1000,
            'retry_timeout' => 15,
            'send_timeout' => 1000,
        ]
    );
    $this->cache = new MemcachedAdapter($client);
}

// Initializes Redis Cache
private function initializeRedisCache() {
    $client = RedisAdapter::createConnection(
        'redis://10.75.32.72:6379',
        [
            'persistent' => 0,
            'timeout' => 5,
            'read_timeout' => 5,
            'retry_interval' => 0,
            'tcp_keepalive' => 60,
            'lazy' => null,
            'redis_cluster' => false,
            'redis_sentinel' => null,
            'dbindex' => 0,
            'failover' => 'none',
            'ssl' => null,
        ]
    );
    $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
    $client->setOption(\Redis::OPT_PREFIX, 'symfony_cache');
    $client->setOption(\Redis::OPT_COMPRESSION, true);
    $this->cache = new RedisAdapter($client);
}

// Initializes SQLite Cache
private function initializeSqliteCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $dbFile = $cacheDir . '/file.sqlite';
    $this->ensureFileExists($dbFile);
    $pdo = new \PDO('sqlite:' . $dbFile);
    $this->cache = new PdoAdapter($pdo);
}

private function initializeStashCache($backend) {
    try {
        switch ($backend) {
            case 'filesystem':
                $this->initializeStashFilesystemCache();
                break;

            case 'memcached':
                $this->initializeStashMemcachedCache();
                break;

            case 'redis':
                $this->initializeStashRedisCache();
                break;

            case 'sqlite':
                $this->initializeStashSqliteCache();
                break;

            case 'apcu':
                $this->initializeStashApcuCache();
                break;

            default:
                throw new \InvalidArgumentException("Unsupported Stash Cache backend: $backend");
        }
    } catch (\Stash\Exception\ExceptionInterface $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        throw $e;
    } catch (\Exception $e) {
        throw $e;
    }
}

private function initializeStashFilesystemCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache';
    $this->ensureDirectoryExists($cacheDir);
    $pool = new Pool(new FileSystem($cacheDir));
    $pool->setNamespace('stash_cache');
    $this->cache = $pool;
}

private function initializeStashMemcachedCache() {
    $memcached = new \Memcached();
    $memcached->addServer('127.0.0.1 , 11211);
    if ($memcached->getVersion() === false) {
        throw new \RuntimeException("Could not connect to Memcached server.");
    }
    $pool = new Pool(new Memcache($memcached));
    $pool->setNamespace('stash_cache');
    $this->cache = $pool;
}

private function initializeStashRedisCache() {
    $redis = new \Redis();
    if (!$redis->connect('127.0.0.1 , 6379)) {
        throw new \RuntimeException("Could not connect to Redis server.");
    }
    $pool = new Pool(new Redis($redis));
    $pool->setNamespace('stash_cache');
    $this->cache = $pool;
}

private function initializeStashSqliteCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $dbFile = $cacheDir . '/stash.sqlite';
    $this->ensureFileExists($dbFile);
    $sqliteDriver = new Sqlite(['path' => $dbFile]);
    $pool = new Pool($sqliteDriver);
    $pool->setNamespace('stash_cache');
    $this->cache = $pool;
}

private function initializeStashApcuCache() {
    if (!extension_loaded('apcu')) {
        throw new \RuntimeException("APCu extension is not loaded.");
    }
    $pool = new Pool(new Apcu());
    $pool->setNamespace('stash_cache');
    $this->cache = $pool;
}

private function initializeLaminasCache($backend) {
    try {
        switch ($backend) {
            case 'filesystem':
                $this->initializeLaminasFilesystemCache();
                break;

            case 'memcached':
                $this->initializeLaminasMemcachedCache();
                break;

            case 'redis':
                $this->initializeLaminasRedisCache();
                break;

            case 'sqlite':
                $this->initializeLaminasSqliteCache();
                break;

            case 'apcu':
                $this->initializeLaminasApcuCache();
                break;

            default:
                throw new \InvalidArgumentException("Unsupported Laminas Cache backend: $backend");
        }
    } catch (\Laminas\Cache\Exception\ExceptionInterface $e) {
        error_log("Laminas Cache Exception: " . $e->getMessage());
        throw $e;
    } catch (\RuntimeException $e) {
        error_log("Runtime Exception: " . $e->getMessage());
        throw $e;
    } catch (\Exception $e) {
        error_log("Exception: " . $e->getMessage());
        throw $e;
    }
}

private function initializeLaminasFilesystemCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache';
    $this->ensureDirectoryExists($cacheDir);
    $options = new FilesystemOptions([
        'cache_dir' => $cacheDir,
        'ttl' => 3600,
        'namespace' => 'filesystem_cache',
    ]);
    $this->cache = new Filesystem($options);
}

private function initializeLaminasMemcachedCache() {
    $memcached = new \Memcached();
    $memcached->addServer('127.0.0.1 , 11211);
    if (!$memcached->getStats()) {
        throw new \RuntimeException("Could not connect to Memcached server.");
    }
    $options = new MemcachedOptions([
        'servers' => [['host' => '127.0.0.1 , 'port' => 11211]],
        'namespace' => 'memcached_cache',
        'ttl' => 3600,
    ]);
    $this->cache = new Memcached($options);
}

private function initializeLaminasRedisCache() {
    $redis = new \Redis();
    if (!$redis->connect('127.0.0.1 , 6379)) {
        throw new \RuntimeException("Could not connect to Redis server.");
    }
    $options = new RedisOptions([
        'server' => ['host' => '127.0.0.1 , 'port' => 6379],
        'namespace' => 'redis_cache',
        'ttl' => 3600,
    ]);
    $this->cache = new Redis($options);
}

private function initializeLaminasSqliteCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $dbFile = $cacheDir . '/file.sqlite';
    if (!file_exists($dbFile)) {
        touch($dbFile);
    }
    $options = new SqliteOptions([
        'database' => $dbFile,
        'namespace' => 'sqlite_cache',
        'ttl' => 3600,
    ]);
    $this->cache = new Sqlite($options);
}

private function initializeLaminasApcuCache() {
    if (!extension_loaded('apcu')) {
        throw new \RuntimeException("APCu extension is not loaded.");
    }
    $options = new ApcuOptions([
        'namespace' => 'apcu_cache',
        'ttl' => 3600,
    ]);
    $this->cache = new Apcu($options);
}


private function initializeDoctrineCache($backend) {
    try {
        switch ($backend) {
            case 'files':
                $this->initializeDoctrineFilesystemCache();
                break;

            case 'memcached':
                $this->initializeDoctrineMemcachedCache();
                break;

            case 'redis':
                $this->initializeDoctrineRedisCache();
                break;

            case 'sqlite':
                $this->initializeDoctrineSqliteCache();
                break;

            case 'apcu':
                $this->initializeDoctrineApcuCache();
                break;

            default:
                throw new \InvalidArgumentException("Invalid cache backend provided: {$backend}");
        }
    } catch (\Doctrine\Common\Cache\CacheException $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        throw $e;
    } catch (\Exception $e) {
        throw $e;
    }
}

private function initializeDoctrineFilesystemCache() {
    $cacheDirectory = WP_CONTENT_DIR . '/cache';
    $this->ensureDirectoryExists($cacheDirectory);
    $this->cache = new \Doctrine\Common\Cache\PhpFileCache($cacheDirectory);
}

private function initializeDoctrineMemcachedCache() {
    $memcached = new \Memcached();
    $memcached->addServer('127.0.0.1 , 11211);
    if ($memcached->getVersion() === false) {
        throw new \RuntimeException("Could not connect to Memcached server.");
    }
    $this->cache = new \Doctrine\Common\Cache\MemcachedCache();
    $this->cache->setMemcached($memcached);
}

private function initializeDoctrineRedisCache() {
    $redis = new \Redis();
    if (!$redis->connect('127.0.0.1 , 6379)) {
        throw new \RuntimeException("Could not connect to Redis server.");
    }
    $this->cache = new \Doctrine\Common\Cache\RedisCache();
    $this->cache->setRedis($redis);
}

private function initializeDoctrineSqliteCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $dbFile = $cacheDir . '/doctrine.sqlite';
    $this->ensureFileExists($dbFile);
    $sqlite = new \SQLite3($dbFile);
    $this->cache = new \Doctrine\Common\Cache\SQLite3Cache($sqlite, 'cache');
}

private function initializeDoctrineApcuCache() {
    if (!extension_loaded('apcu')) {
        throw new \RuntimeException("APCu extension is not loaded.");
    }
    $this->cache = new \Doctrine\Common\Cache\ApcuCache();
}

private function initializePhpFastCache($backend, $configurations) {
    try {
        $this->mergeDefaultConfigurations($configurations);

        switch ($backend) {
            case 'files':
                $this->initializePhpFastCacheFiles($configurations['files']);
                break;

            case 'memcached':
                $this->initializePhpFastCacheMemcached($configurations['memcached']);
                break;

            case 'redis':
                $this->initializePhpFastCacheRedis($configurations['redis']);
                break;

            case 'sqlite':
                $this->initializePhpFastCacheSqlite($configurations['sqlite']);
                break;

            case 'apcu':
                $this->initializePhpFastCacheApcu($configurations['apcu']);
                break;

            default:
                throw new \InvalidArgumentException("Invalid cache backend provided: {$backend}");
        }
    } catch (\Phpfastcache\Exceptions\PhpFastCacheException $e) {
        error_log("Cache initialization PhpFastCache error: " . $e->getMessage());
        throw $e;
    } catch (\Exception $e) {
        error_log("Cache initialization error: " . $e->getMessage());
        throw $e;
    }
}

private function mergeDefaultConfigurations(&$configurations) {
    $defaultConfigurations = [
        'files' => ['path' => WP_CONTENT_DIR . '/cache/files'],
        'memcached' => ['servers' => [['host' => '127.0.0.1 , 'port' => 11211]]],
        'redis' => ['host' => '127.0.0.1 , 'port' => 6379, 'prefix' => 'madmax_54908'],
        'sqlite' => ['path' => WP_CONTENT_DIR . '/cache/sqlite'],
        'apcu' => ['namespace' => 'madmax_54908'],
    ];
    $configurations = array_merge_recursive($defaultConfigurations, $configurations);
}

private function initializePhpFastCacheFiles($config) {
    $cacheDir = $config['path'];
    $this->ensureDirectoryExists($cacheDir);
    $this->cache = CacheManager::getInstance('Files', $config);
}

private function initializePhpFastCacheMemcached($config) {
    $this->cache = CacheManager::getInstance('Memcached', $config);
}

private function initializePhpFastCacheRedis($config) {
    $this->cache = CacheManager::getInstance('Redis', $config);
}

private function initializePhpFastCacheSqlite($config) {
    $cacheDir = $config['path'];
    $dbFile = $cacheDir . '/cache.sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $this->ensureFileExists($dbFile);
    $config['path'] = $dbFile;
    $this->cache = CacheManager::getInstance('Sqlite', $config);
}

private function initializePhpFastCacheApcu($config) {
    $this->cache = CacheManager::getInstance('Apcu', $config);
}

private function initializeIlluminateCache($backend, $configurations) {
    try {
        switch ($backend) {
            case 'redis':
                $this->initializeIlluminateRedisCache($configurations);
                break;

            case 'files':
                $this->initializeIlluminateFileCache();
                break;

            case 'apcu':
                $this->initializeIlluminateApcuCache();
                break;

            case 'memcached':
                $this->initializeIlluminateMemcachedCache();
                break;

            case 'sqlite':
                $this->initializeIlluminateSqliteCache();
                break;

            default:
                throw new \InvalidArgumentException("Invalid cache backend provided: {$backend}");
        }
    } catch (\Exception $e) {
        throw $e;
    }
}

private function initializeIlluminateRedisCache($configurations) {
    $redis = new \Redis();
    $redis->connect('127.0.0.1 , 6379);
    $redisManager = new \Illuminate\Cache\CacheManager(app());
    $redisManager->extend('redis', function ($app) use ($redis, $configurations) {
        return new \Illuminate\Cache\RedisStore($redis, $configurations['redis']['prefix']);
    });
    $this->cache = $redisManager->store('redis');
}

private function initializeIlluminateFileCache() {
    $cacheStore = new \Illuminate\Cache\FileStore(new \Illuminate\Filesystem\Filesystem(), WP_CONTENT_DIR . '/cache');
    $this->cache = new \Illuminate\Cache\Repository($cacheStore);
}

private function initializeIlluminateApcuCache() {
    $cacheStore = new \Illuminate\Cache\ApcuStore();
    $this->cache = new \Illuminate\Cache\Repository($cacheStore);
}

private function initializeIlluminateMemcachedCache() {
    $memcached = new \Memcached();
    $memcached->addServer('127.0.0.1 , 11211);
    $cacheStore = new \Illuminate\Cache\MemcachedStore($memcached);
    $this->cache = new \Illuminate\Cache\Repository($cacheStore);
}

private function initializeIlluminateSqliteCache() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    $this->ensureDirectoryExists($cacheDir);
    $dbFile = $cacheDir . '/cache.sqlite';
    $this->ensureFileExists($dbFile);
    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $dbFile,
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $connection = $capsule->getConnection();
    $this->ensureCacheTableExists($connection);
    $cacheStore = new \Illuminate\Cache\DatabaseStore($connection);
    $this->cache = new \Illuminate\Cache\Repository($cacheStore);
}

protected function ensureCacheTableExists($connection) {
    if (!$connection->getSchemaBuilder()->hasTable('cache')) {
        $connection->getSchemaBuilder()->create('cache', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('key')->unique();
            $table->text('value');
            $table->integer('expiration');
        });
    }
}

    public function get($key) {
        try {
            $item = $this->cache->get($key);
            return $item ?: null;
        } catch (\Exception $e) {
            error_log("Cache retrieval error: " . $e->getMessage());
            return null;
        }
    }

    public function put($key, $value, $minutes) {
        try {
            return $this->cache->put($key, $value, $minutes * 60);
        } catch (\Exception $e) {
            error_log("Cache put error: " . $e->getMessage());
            return false;
        }
    }

    public function remember($key, $minutes, $callback) {
        try {
            return $this->cache->remember($key, $minutes * 60, $callback);
        } catch (\Exception $e) {
            error_log("Cache remember error: " . $e->getMessage());
            return call_user_func($callback);
        }
    }

    public function forget($key) {
        try {
            return $this->cache->forget($key);
        } catch (\Exception $e) {
            error_log("Cache forget error: " . $e->getMessage());
            return false;
        }
    }

    public function getCacheInstance() {
        return $this->cache;
    }
}

class CacheWrapper_2  {
	    private $cache;

		public function __construct($cache) {
		    $this->cache = $cache;
		}
		

	    public function getItem($key) {
	        $item = $this->cache->getItem($key);
	        if (!($item instanceof ExtendedCacheItem)) {
	            $extendedItem = new ExtendedCacheItem($item);
	            $item = $extendedItem;
	        }
	        return $item;
	    }

	    public function setItem($key, $value, $expiration = null) {
	        $item = $this->cache->getItem($key);
	        $item->set($value);
	        if ($expiration !== null) {
	            $item->expiresAfter($expiration);
	        }
	        return $this->cache->save($item);
	    }

	    public function deleteItem($key) {
	        return $this->cache->deleteItem($key);
	    }

	    // Clear cache using either 'deleteAll' or 'clear' method, depending on the cache library
	    public function clear() {
	        if (method_exists($this->cache, 'deleteAll')) {
	            return $this->cache->deleteAll();
	        } elseif (method_exists($this->cache, 'clear')) {
	            return $this->cache->clear();
	        }
	        return false;
	    }

	    // Alias for the 'clear' method
	    public function clear_cache() {
	        return $this->clear();
	    }

	    // Alias for the 'clear' method
	    public function deleteAll() {
	        return $this->clear();
	    }
}

class AutoCache {
    private $cacheAdapter;

    public function __construct($cacheAdapter) {
        $this->cacheAdapter = $cacheAdapter;
        add_action('init', array($this, 'start_output_buffering'));
        add_action('wp_head', array($this, 'start_output_buffering_with_key'), 1);
        add_action('wp_footer', array($this, 'end_output_buffering'), 1000);
        add_action('wp_ajax_my_ajax_request', array($this, 'handle_ajax_request'));
        add_action('wp_ajax_nopriv_my_ajax_request', array($this, 'handle_ajax_request'));
        add_action('template_redirect', array($this, 'start_output_buffering'), 0);
        add_action('init', array($this, 'auto_opcache_warmup'));
        add_action('invalidate_opcache_even', array($this, 'invalidate_opcache'));
        add_filter('override_load_textdomain', array($this, 'a_faster_load_textdomain'), 1, 3);
        add_filter('override_load_textdomain', array($this, 'a_faster_load_textdomain_persistent_cache'), 10, 3);
        add_action('template_redirect', array($this, 'early_page_cache'), 0);
        add_action("acf/update_field", array($this, 'on_acf_update_field'), 5, 1);
        add_action("acf/save_post", array($this, 'on_acf_save_post'), 20, 1);
        add_filter('acf/pre_load_value', array($this, 'prefetch_acf_options_and_meta'), 10, 3);
    }

    private function generate_cache_key($key, $prefix = '') {
        return $prefix . md5($key);
    }

    public function compress_data($data) {
        try {
            $serialized_data = serialize($data);
            $compressed_data = gzcompress($serialized_data, 7);
            if ($compressed_data === false) {
                throw new \Exception('Compression failed.');
            }
            return $compressed_data;
        } catch (\Exception $e) {
            error_log("Compression error: " . $e->getMessage());
            return null;
        }
    }

    public function decompress_data($compressed_data) {
        try {
            $serialized_data = gzuncompress($compressed_data);
            if ($serialized_data === false) {
                throw new \Exception('Decompression failed.');
            }
            return unserialize($serialized_data);
        } catch (\Exception $e) {
            error_log("Decompression error: " . $e->getMessage());
            return null;
        }
    }

public function init() {
    // Get the plugin options
    $options = ['library', 'backend', 'page_cache', 'transient_cache', 'object_cache'];
    foreach ($options as $option) {
        $this->$option = get_option($option);
    }

    // Initialize the cache adapter
    $this->cacheAdapter = new CacheAdapter($this->library, $this->backend);

    if ($this->page_cache) {
        add_action('wp_loaded', array($this, 'handlePageCache'));
    }

    if ($this->transient_cache) {
        add_filter('pre_transient_get', array($this, 'handleTransientGet'), 10, 2);
        add_filter('pre_transient_set', array($this, 'handleTransientSet'), 10, 3);
    }

    if ($this->object_cache) {
        add_filter('wp_cache_get', array($this, 'handleObjectCacheGet'), 10, 3);
        add_filter('wp_cache_set', array($this, 'handleObjectCacheSet'), 10, 4);
    }
}

public function auto_cache() {
    $content_type = $this->detect_content_type();

    switch ($content_type) {
        case 'post':
            // Call the caching method for posts
            $post = $this->cachePost(get_the_ID());
            $latest_posts = $this->cacheLatestPosts(125); // Pass the number of latest posts you want to cache
            break;
        case 'page':
            // Call the caching method for pages
            $pageId = get_the_ID(); // Get the page ID
            $page = $this->cachePage($pageId);
            $this->autoCacheByTemplate(); // Replace with the actual method for caching pages by template
            break;
        case 'output_buffer':
            // Call the caching method for output buffer
            $this->start_output_buffering(); // This will automatically handle output buffering with a custom key
            break;
        default:
            // No content type detected, do nothing
            break;
    }
}

private function detect_content_type() {
    global $output_buffer_key;

    if (is_singular()) {
        return 'post';
    } elseif (is_page()) {
        return 'page';
    } elseif (ob_get_length() > 0 && isset($output_buffer_key)) {
        return $output_buffer_key;
    }

    return null;
}

// Object Cache
public function cacheObject($objectId, $group) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cacheKey = $group . '_' . $objectId;
    $cachedObject = $this->cacheAdapter->getItem($cacheKey);

    try {
        if (!$cachedObject->isHit()) {
            $objectData = $this->fetchObjectData($objectId); // Implement actual object fetching logic
            $compressedObjectData = $this->compress_data($objectData);

            if ($compressedObjectData !== null) {
                $cachedObject->set($compressedObjectData)->expiresAfter(3600);
                $this->cacheAdapter->save($cachedObject);
            } else {
                // Handle compression failure
                error_log("Compression of object data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $objectData = $this->decompress_data($cachedObject->get());
        }

        return $objectData;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cacheObject: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

// Transient Cache
public function cacheTransient($transientKey, $dataCallback) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cachedValue = get_transient($transientKey);

    try {
        if ($cachedValue === false) {
            $cachedValue = call_user_func($dataCallback); // Call the provided callback to get the data
            $compressedCachedValue = $this->compress_data($cachedValue);

            if ($compressedCachedValue !== null) {
                set_transient($transientKey, $compressedCachedValue, 3600); // Cache for 1 hour
            } else {
                // Handle compression failure
                error_log("Compression of transient data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $cachedValue = $this->decompress_data($cachedValue);
        }

        return $cachedValue;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cacheTransient: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

// Post Cache
public function cachePost($postId) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cacheKey = 'post_' . $postId;
    $cachedPost = $this->cacheAdapter->getItem($cacheKey);

    try {
        if (!$cachedPost->isHit()) {
            $postData = $this->fetchPostData($postId); // Implement actual post fetching logic
            $compressedPostData = $this->compress_data($postData);

            if ($compressedPostData !== null) {
                $cachedPost->set($compressedPostData)->expiresAfter(3600);
                $this->cacheAdapter->save($cachedPost);
            } else {
                // Handle compression failure
                error_log("Compression of post data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $postData = $this->decompress_data($cachedPost->get());
        }

        return $postData;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cachePost: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

// Menu Cache
public function cacheMenu($menuLocation) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cacheKey = 'menu_' . $menuLocation;
    $cachedMenu = $this->cacheAdapter->getItem($cacheKey);

    try {
        if (!$cachedMenu->isHit()) {
            $menuItems = $this->fetchMenuItems($menuLocation); // Implement actual menu fetching logic
            $compressedMenuData = $this->compress_data($menuItems);

            if ($compressedMenuData !== null) {
                $cachedMenu->set($compressedMenuData)->expiresAfter(3600); // Cache for 1 hour
                $this->cacheAdapter->save($cachedMenu);
            } else {
                // Handle compression failure
                error_log("Compression of menu data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $menuItems = $this->decompress_data($cachedMenu->get());
        }

        return $menuItems;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cacheMenu: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

public function cache_latest_posts($count) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cache_key = 'cache_latest_posts_' . $count;
    $cache_ttl = 3600; // 1 hour

    $compressed_posts = $this->cacheAdapter->getItem($cache_key);

    try {
        if (!$compressed_posts->isHit()) {
            $args = array(
                'numberposts' => $count,
                'post_status' => 'publish'
            );
            $latest_posts = wp_get_recent_posts($args);
            $compressed_posts_data = $this->compress_data($latest_posts);

            if ($compressed_posts_data !== null) {
                $compressed_posts->set($compressed_posts_data)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_posts);
            } else {
                // Handle compression failure
                error_log("Compression of latest posts data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $latest_posts = $this->decompress_data($compressed_posts->get());
        }

        return $latest_posts;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cache_latest_posts: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

public function cache_page($page_id) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cache_key = 'cache_page_' . $page_id;
    $cache_ttl = 3600; // 1 hour

    $compressed_page = $this->cacheAdapter->getItem($cache_key);

    try {
        if (!$compressed_page->isHit()) {
            $page = get_post($page_id);
            $compressed_page_data = $this->compress_data($page);

            if ($compressed_page_data !== null) {
                $compressed_page->set($compressed_page_data)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_page);
            } else {
                // Handle compression failure
                error_log("Compression of page data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $page = $this->decompress_data($compressed_page->get());
        }

        return $page;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cache_page: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

public function cache_pages_by_template($template_file) {
    if ($this->cacheAdapter === null) {
        return null; // Return null instead of void
    }

    $cache_key = 'cache_pages_by_template_' . md5($template_file);
    $cache_ttl = 3600; // 1 hour

    $compressed_pages = $this->cacheAdapter->getItem($cache_key);

    try {
        if (!$compressed_pages->isHit()) {
            $args = array(
                'post_type' => 'page',
                'meta_key' => '_wp_page_template',
                'meta_value' => $template_file
            );
            $pages = get_posts($args);
            $compressed_pages_data = $this->compress_data($pages);

            if ($compressed_pages_data !== null) {
                $compressed_pages->set($compressed_pages_data)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_pages);
            } else {
                // Handle compression failure
                error_log("Compression of pages by template data failed.");
                return null; // Return null in case of compression failure
            }
        } else {
            $pages = $this->decompress_data($compressed_pages->get());
        }

        return $pages;
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cache_pages_by_template: " . $e->getMessage());
        return null; // Return null in case of an exception
    }
}

public function start_output_buffering() {
    $ob_started = ob_start([$this, 'cache_output_buffer']);
    if ($ob_started === false) {
        // Handle error if ob_start fails
        error_log("Output buffering failed to start.");
    }
}

public function cache_output_buffer($buffer) {
    if ($this->cacheAdapter === null) {
        return $buffer;
    }

    $cache_key = 'cache_output_buffer_' . md5($buffer);
    $cache_ttl = 3600; // 1 hour

    $compressed_buffer = $this->cacheAdapter->getItem($cache_key);

    try {
        if (!$compressed_buffer->isHit()) {
            $compressed_buffer_data = $this->compress_data($buffer);

            if ($compressed_buffer_data !== null) {
                $compressed_buffer->set($compressed_buffer_data)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_buffer);
            } else {
                // Handle compression failure
                error_log("Compression of output buffer failed.");
            }
        } else {
            $buffer = $this->decompress_data($compressed_buffer->get());
        }
    } catch (Exception $e) {
        // Handle any exceptions that may occur
        error_log("Error in cache_output_buffer: " . $e->getMessage());
    }

    return $buffer;
}

public function end_output_buffering() {
    if (ob_get_level() > 0) {
        $ob_flushed = ob_end_flush();
        if ($ob_flushed === false) {
            // Handle error if ob_end_flush fails
            error_log("Output buffering failed to end/flush.");
        }
    }
}

public function cache_output_buffer_auto() {
    if ($this->cacheAdapter === null) {
        return;
    }

    global $post; // Ensure that $post is declared as global if you're going to use it

    // Determine cache key based on post type and ID
    $cache_key = 'cache_output_buffer_' . (isset($post) ? $post->post_type . '_' . $post->ID : 'other');
    $cache_ttl = 3600; // 1 hour

    $compressed_buffer = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_buffer->isHit()) {
        ob_start();
        wp_footer(); // Ensure the footer is included in the buffer
        $buffer = ob_get_clean();

        if ($buffer !== false) {
            $compressed_buffer_data = $this->compress_data($buffer);

            if ($compressed_buffer_data !== null) {
                $compressed_buffer->set($compressed_buffer_data)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_buffer);
            } else {
                // Handle compression failure
                error_log("Compression of output buffer failed.");
            }
        } else {
            // Handle output buffering failure
            error_log("Output buffering failed.");
        }
    } else {
        $buffer = $this->decompress_data($compressed_buffer->get());
    }

    echo $buffer;
}

function myplugin_compress_data($data, $compression_method = 'gzip') {
    // Check if the specified compression method is supported
    if (!in_array($compression_method, ['gzip', 'deflate'])) {
        return $data; // Return data as is if compression method is not supported
    }

    $compressed_data = '';

    if ($compression_method === 'gzip') {
        $compressed_data = gzcompress($data, 7); // Use gzcompress for gzip compression
    } elseif ($compression_method === 'deflate') {
        $compressed_data = gzdeflate($data, 7); // Use gzdeflate for deflate compression
    }

    if ($compressed_data !== false) {
        return base64_encode($compressed_data); // Encode the compressed data for safe storage
    }

    return $data; // Return the data as is if compression fails
}

function myplugin_decompress_data($compressed_data, $compression_method = 'gzip') {
    // Check if the specified compression method is supported
    if (!in_array($compression_method, ['gzip', 'deflate'])) {
        return $compressed_data; // Return compressed data as is if compression method is not supported
    }

    $decoded_data = base64_decode($compressed_data);

    if ($compression_method === 'gzip') {
        $decompressed_data = gzuncompress($decoded_data); // Use gzuncompress for gzip decompression
    } elseif ($compression_method === 'deflate') {
        $decompressed_data = gzinflate($decoded_data); // Use gzinflate for deflate decompression
    }

    if ($decompressed_data !== false) {
        return $decompressed_data;
    }

    return $compressed_data; // Return the compressed data as is if decompression fails
}

function cache_social_media_request($platform, $endpoint) {
    global $cache;
    $cache_key = "social_media_{$platform}_" . md5($endpoint);
    $cached_data = $cache->getItem($cache_key);
    if (null === $cached_data->get()) {
        // Fetch data from the social media platform
        $response = fetch_social_media_data($platform, $endpoint);
        $cached_data->set($response)->expiresAfter(3600);
        $cache->save($cached_data);
    }
    return $cached_data->get();
}

function cache_graphql_query($query) {
    if ($this->cacheAdapter === null) {
        // Log the error or handle it appropriately if the cacheAdapter is not initialized
        error_log('CacheAdapter is not initialized.');
        return null;
    }

    $cache_key = "graphql_" . md5($query);
    $cached_data = $this->cacheAdapter->getItem($cache_key);

    if (!$cached_data->isHit()) {
        try {
            // Execute the GraphQL query
            $result = execute_graphql_query($query);

            // Validate the result before caching
            if ($result && !is_wp_error($result) && is_array($result)) {
                $compressed_result = $this->compress_data($result);
                $cached_data->set($compressed_result)->expiresAfter(3600);
                $this->cacheAdapter->save($cached_data);
                return $result;
            } else {
                // Handle the situation when result is a WP_Error or not valid
                error_log('Invalid GraphQL query result.');
                return null;
            }
        } catch (Exception $e) {
            // Log the exception message
            error_log('GraphQL query exception: ' . $e->getMessage());
            return null;
        }
    } else {
        // Decompress the data if the cache hit
        try {
            $result = $this->decompress_data($cached_data->get());
            // Ensure that the result is the expected format after decompression
            if (is_array($result)) {
                return $result;
            } else {
                // Handle unexpected format
                error_log('Decompressed data is not an array.');
                return null;
            }
        } catch (Exception $e) {
            // Log the exception message
            error_log('Decompression exception: ' . $e->getMessage());
            return null;
        }
    }
}

function cache_graphql_post($post_id) {
    global $cache;

    $cache_key = "graphql_post_" . $post_id;
    $cached_data = $cache->getItem($cache_key);

    if ($cached_data->isHit()) {
        return $cached_data->get();
    }

    // Fetch the post using GraphQL
    $query = generate_graphql_post_query($post_id);
    $result = execute_graphql_query($query);

    if ($result && is_array($result)) {
        $cached_data->set($result)->expiresAfter(3600);
        $cache->save($cached_data);
        return $result;
    } else {
        error_log('Invalid GraphQL post query result.');
        return null;
    }
}

function cache_graphql_category($category_id) {
    global $cache;

    $cache_key = "graphql_category_" . $category_id;
    $cached_data = $cache->getItem($cache_key);

    if ($cached_data->isHit()) {
        return $cached_data->get();
    }

    // Fetch the category using GraphQL
    $query = generate_graphql_category_query($category_id);
    $result = execute_graphql_query($query);

    if ($result && is_array($result)) {
        $cached_data->set($result)->expiresAfter(3600);
        $cache->save($cached_data);
        return $result;
    } else {
        error_log('Invalid GraphQL category query result.');
        return null;
    }
}

public function cache_menu_fragment() {
    if ($this->cacheAdapter === null) {
        return;
    }

    $cache_key = 'menu_fragment';
    $compressed_menu_item = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_menu_item->isHit()) {
        ob_start();
        wp_nav_menu(['theme_location' => 'primary']);
        $menu_html = ob_get_clean();
        $compressed_menu_data = $this->compress_data($menu_html);
        $compressed_menu_item->set($compressed_menu_data)->expiresAfter(3600);
        $this->cacheAdapter->save($compressed_menu_item);
    } else {
        $menu_html = $this->decompress_data($compressed_menu_item->get());
    }

    echo $menu_html;
}

public function handle_ajax_request() {
    // Check for the nonce for security
    check_ajax_referer('my_ajax_nonce', 'nonce');

    if ($this->cacheAdapter === null) {
        return;
    }

    $post_id = intval($_GET['post_id']);
    $cache_key = 'ajax_post_' . $post_id;
    $compressed_post_item = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_post_item->isHit()) {
        $post_data = get_post($post_id);

        if ($post_data !== null) {
            $compressed_post_data = $this->compress_data($post_data);
            $compressed_post_item->set($compressed_post_data);
            $this->cacheAdapter->save($compressed_post_item);
        }
    } else {
        $post_data = $this->decompress_data($compressed_post_item->get());
    }

    // Handle the AJAX request here
    $response_data = ['success' => true, 'message' => 'AJAX handled.'];

    // Always return a response in JSON format
    wp_send_json($response_data);
}

public function cache_api_request($api_url) {
    if ($this->cacheAdapter === null) {
        return null;
    }

    $cache_key = 'api_json_' . md5($api_url);
    $compressed_api_response_item = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_api_response_item->isHit()) {
        $response = wp_remote_get($api_url);

        if (!is_wp_error($response)) {
            $api_data = wp_remote_retrieve_body($response);
            $compressed_api_data = $this->compress_data($api_data);

            if ($compressed_api_data !== null) {
                $compressed_api_response_item->set($compressed_api_data);
                $this->cacheAdapter->save($compressed_api_response_item);
                return json_decode($api_data);
            }
        }
    } else {
        $api_data = $this->decompress_data($compressed_api_response_item->get());
        return json_decode($api_data);
    }

    return null; // Return null if there's an error or no data found
}

public function preload_page_cache() {
    if ($this->cacheAdapter === null) {
        error_log('CacheAdapter not initialized. Exiting preload_page_cache.');
        return;
    }

    $urls_to_preload = [home_url('/')];

    $categories = get_categories(['hide_empty' => false]);
    foreach ($categories as $category) {
        $category_link = get_category_link($category->term_id);
        $urls_to_preload[] = $category_link;

        for ($i = 2; $i <= 5; $i++) {
            $urls_to_preload[] = $category_link . 'page/' . $i . '/';
        }
    }

    $sitemap_response = wp_remote_get(home_url('/sitemap.xml'));
    if (!is_wp_error($sitemap_response)) {
        $sitemap_content = wp_remote_retrieve_body($sitemap_response);
        if ($sitemap_content) {
            $xml = simplexml_load_string($sitemap_content);
            if ($xml && isset($xml->url)) {
                foreach ($xml->url as $url_entry) {
                    $urls_to_preload[] = (string) $url_entry->loc;
                }
            }
        }
    }

    foreach ($urls_to_preload as $url) {
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log('Error preloading URL: ' . $url . ' - ' . $response->get_error_message());
            continue;
        }

        $cache_key = 'page_cache_' . md5($url);
        $response_body = wp_remote_retrieve_body($response);
        $compressed_data = $this->compress_data($response_body);

        if ($compressed_data !== null) {
            $compressed_response_item = $this->cacheAdapter->getItem($cache_key);
            $compressed_response_item->set($compressed_data)->expiresAfter(HOUR_IN_SECONDS);
            $this->cacheAdapter->save($compressed_response_item);
        }
    }

    error_log('Finished preloading cache for selected URLs.');
}

public function preload_transient_cache($transient_key, $callback, $cache_ttl = 3600) {
    if ($this->cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_data_auto_transient_' . $transient_key;
    $compressed_data_item = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_data_item->isHit()) {
        $results = $callback();

        if (!is_null($results)) {
            if ($results instanceof WP_Query) {
                $transient_key .= '_wp_query';
            }

            $compressed_results = $this->compress_data(maybe_serialize($results));

            if ($compressed_results !== null) {
                $compressed_data_item->set($compressed_results)->expiresAfter($cache_ttl);
                $this->cacheAdapter->save($compressed_data_item);
            }
        }
    }
}

public function preload_apijson($served, $result, $request, $server) {
    if ($this->cacheAdapter === null) {
        return;
    }

    $cache_key = 'api_json_response_' . md5($request->get_route());
    $cache_ttl = 3600; // 1 hour

    $compressed_response_item = $this->cacheAdapter->getItem($cache_key);

    if (!$compressed_response_item->isHit()) {
        $response_data = $result->get_data();
        $compressed_response_data = $this->compress_data(json_encode($response_data));

        if (json_last_error() === JSON_ERROR_NONE) {
            $compressed_response_item->set($compressed_response_data)->expiresAfter($cache_ttl);
            $this->cacheAdapter->save($compressed_response_item);
        }
    }
}

function cache_graphql_responses($response, $schema, $operation, $variables, $request) {
    if ($this->cacheAdapter === null) {
        return;
    }

    $cache_key = 'graphql_response_' . md5(json_encode($variables));
    $cache_ttl = 60; // 1 minute

    $compressed_response_data = $this->compress_data(json_encode($response));
    $cache_item = $this->cacheAdapter->getItem($cache_key);
    $cache_item->set($compressed_response_data)->expiresAfter($cache_ttl);
    $this->cacheAdapter->save($cache_item);
}

public function cache_latest_articles_query() {
    if ($this->cacheAdapter === null) {
        return;
    }

    $cache_key = 'graphql_latest_articles';
    $cached_response = $this->cacheAdapter->getItem($cache_key);

    if ($cached_response->isHit()) {
        return $cached_response->get();
    }

    try {
        $query = "
        {
            articles(limit: 12, orderBy: { 
                field: DATE, order: DESC }) {
                nodes {
                    id
                    title
                    content
                    date
                }
            }
        }";
        $response = execute_graphql_query($query);

        if ($response && is_array($response)) {
            $cache_item = $this->cacheAdapter->getItem($cache_key);
            $cache_item->set($response)->expiresAfter(3600); // Cache for 1 hour
            $this->cacheAdapter->save($cache_item);
            return $response;
        } else {
            error_log('Invalid GraphQL response for latest articles.');
            return null;
        }
    } catch (\Exception $e) {
        error_log('Error fetching latest articles: ' . $e->getMessage());
        return null;
    }
}

public function cache_comments_resolver($article_id) {
    if ($this->cacheAdapter === null) {
        return fetch_comments_for_article($article_id);
    }

    $cache_key = 'graphql_comments_resolver_' . $article_id;
    $cached_response = $this->cacheAdapter->getItem($cache_key);

    if ($cached_response->isHit()) {
        return $cached_response->get();
    }

    $comments = fetch_comments_for_article($article_id);

    $cache_item = $this->cacheAdapter->getItem($cache_key);
    $cache_item->set($comments)->expiresAfter(3600); // Cache for 1 hour
    $this->cacheAdapter->save($cache_item);

    return $comments;
}

public function get_fragment_cache($key, $ttl, $callback, $params = array()) {
    if ($this->cacheAdapter === null) {
        return call_user_func_array($callback, $params);
    }

    $cache_key = 'fragment_cache_' . $key;
    $item = $this->cacheAdapter->getItem($cache_key);

    if ($item->isHit()) {
        return $item->get();
    }

    $data = call_user_func_array($callback, $params);
    $item->set($data)->expiresAfter($ttl);
    $this->cacheAdapter->save($item);

    return $data;
}

public function cached_rest_fragment($served, $result, $request, $server, $handler) {
    if ($this->cacheAdapter === null) {
        return $result;
    }

    try {
        $compressed_data = $this->compress_data(maybe_serialize($result));
    } catch (Exception $e) {
        return array();
    }

    $cache_key = 'rest_fragment_cache_' . md5($served);
    $item = $this->cacheAdapter->getItem($cache_key);
    $item->set($compressed_data)->expiresAfter(3600); // Cache for 1 hour
    $this->cacheAdapter->save($item);

    try {
        $result = maybe_unserialize($this->decompress_data($compressed_data));
    } catch (Exception $e) {
        // Handle the error (e.g., log it) and return the original result
    }

    return $result;
}

public function preload_object_cache_fragment($served, $result, $request, $server, $handler) {
    if ($this->cacheAdapter === null) {
        return $result;
    }

    try {
        $compressed_data = $this->compress_data(maybe_serialize($result));
    } catch (Exception $e) {
        // Handle the error (e.g., log it) and return an empty result
        return array();
    }

    $cache_key = 'object_cache_' . md5($served);
    $item = $this->cacheAdapter->getItem($cache_key);
    $item->set($compressed_data)->expiresAfter($this->cache_ttl);
    $this->cacheAdapter->save($item);

    try {
        $result = maybe_unserialize($this->decompress_data($compressed_data));
    } catch (Exception $e) {
        // Handle the error (e.g., log it) and return the original result
    }

    return $result;
}

public function cached_fragment($key, $ttl, $function, $compression_method = null) {
    if ($this->cacheAdapter === null) {
        ob_start();
        call_user_func($function);
        $output = ob_get_contents();
        ob_end_clean();
        echo $output;
        return;
    }

    $cache_key = 'fragment_cache_' . $key;
    $item = $this->cacheAdapter->getItem($cache_key);
    $compressed_data = $item->get();

    if ($item->isHit() && $compressed_data !== null) {
        $output = maybe_unserialize($this->decompress_data($compressed_data));
    } else {
        ob_start();
        call_user_func($function);
        $output = ob_get_contents();
        ob_end_clean();
        $compressed_output = $this->compress_data($output, $compression_method);
        $item->set($compressed_output)->expiresAfter($ttl);
        $this->cacheAdapter->save($item);
    }

    echo $output;
}

public function preload_menu_cache($output, $args) {
    if ($this->cacheAdapter === null) {
        return $output;
    }

    $menu = null;

    if (isset($args->menu)) {
        $menu = wp_get_nav_menu_object($args->menu);
    } elseif (isset($args->theme_location) && $args->theme_location) {
        $menu_locations = get_nav_menu_locations();

        if (isset($menu_locations[$args->theme_location])) {
            $menu_object = get_term($menu_locations[$args->theme_location], 'nav_menu');

            if (!is_wp_error($menu_object)) {
                $menu = $menu_object;
            }
        }
    }

    if ($menu) {
        global $wp_query;
        $menu_signature = md5(wp_json_encode($args) . $wp_query->query_vars_hash);
        $cached_versions = get_transient('menu-cache-menuid-' . $menu->term_id);

        if (false !== $cached_versions) {
            $cached_output = $this->get_fragment_cache('menu-cache-' . $menu_signature, 3600, function () use ($output) {
                return $output;
            });

            if ($cached_output !== null) {
                return $cached_output;
            }
        }
    }

    return $output;
}

function cache_current_system() {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_current_system_' . md5($_SERVER['REQUEST_URI']);
    $cache_ttl = 60; // 1 minute

    $cached_page = $cacheAdapter->getItem($cache_key);

    if (!$cached_page->isHit()) {
        ob_start();
        // Your existing code here...
        $output = ob_get_clean();

        $compressed_output = myplugin_compress_data($output); // Use your compression function

        $cached_page->set($compressed_output)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cached_page);
        echo $output;
    } else {
        $compressed_output = $cached_page->get();
        $output = myplugin_decompress_data($compressed_output); // Use your decompression function
        echo $output;
    }
}

function cache_data_current_system($key, $callback, $cache_ttl = 3600) {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return call_user_func($callback);
    }

    $cache_key = 'cache_data_' . md5($key);
    $cached_data = $cacheAdapter->getItem($cache_key);

    if (!$cached_data->isHit()) {
        $data = call_user_func($callback);
        $compressed_data = myplugin_compress_data($data); // Use your compression function
        $cached_data->set($compressed_data)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cached_data);
    } else {
        $compressed_data = $cached_data->get();
        $data = myplugin_decompress_data($compressed_data); // Use your decompression function
    }

    return $data;
}

function cache_current_page() {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_current_page_' . md5($_SERVER['REQUEST_URI']);
    $cache_ttl = 3600; // 1 hour

    $cached_page = $cacheAdapter->getItem($cache_key);

    if (!$cached_page->isHit()) {
        ob_start();
        // Your existing code here...
        $output = ob_get_clean();
        $compressed_output = myplugin_compress_data($output); // Use your compression function
        $cached_page->set($compressed_output)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cached_page);
        echo $output;
    } else {
        $compressed_output = $cached_page->get();
        $output = myplugin_decompress_data($compressed_output); // Use your decompression function
        echo $output;
    }
}

function cache_data_current_page() {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_data_current_page_' . md5($_SERVER['REQUEST_URI']);
    $cache_ttl = 60; // 1 minute

    $cached_item = $cacheAdapter->getItem($cache_key);

    if (!$cached_item->isHit()) {
        ob_start();
        // Your existing code here...
        $output = ob_get_clean();
        $compressed_output = myplugin_compress_data($output); // Use your compression function
        $cached_item->set($compressed_output)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cached_item);
        echo $output;
    } else {
        $compressed_output = $cached_item->get();
        $output = myplugin_decompress_data($compressed_output); // Use your decompression function
        echo $output;
    }
}

function cache_current_object() {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_current_object_' . get_the_ID();
    $cache_ttl = 60; // 1 minute

    $cached_object = $cacheAdapter->getItem($cache_key);

    if (!$cached_object->isHit()) {
        $object = get_post(get_the_ID());
        $compressed_object = myplugin_compress_data(maybe_serialize($object)); // Use your compression function
        $cached_object->set($compressed_object)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cached_object);
    } else {
        $compressed_object = $cached_object->get();
        return maybe_unserialize(myplugin_decompress_data($compressed_object)); // Use your decompression function
    }
}


function cache_data_current_object() {
    $redis = get_redis_client();

    // Define cache key and TTL
    $cache_key = 'cache_data_current_object_' . get_the_ID();
    $cache_ttl = 60; // 1 minute

    // Try to get cached object
    $compressed_cached_object = $redis->get($cache_key);

    // If object is not cached, generate and cache it
    if ($compressed_cached_object === false) {
        $object = get_post(get_the_ID()); // Get object
        $compressed_object = myplugin_compress_data(maybe_serialize($object)); // Use your compression function
        $redis->set($cache_key, $compressed_object, 'EX', $cache_ttl); // Cache object
    } else {
        $object = maybe_unserialize(myplugin_decompress_data($compressed_cached_object)); // Use your decompression function
    }

    return $object; // Return the cached or newly generated object
}

function cache_current_transient() {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_current_transient_' . md5($_SERVER['REQUEST_URI']);
    $cache_ttl = 60; // 1 minute

    $cached_transient = $cacheAdapter->getItem($cache_key)->get();

    if ($cached_transient === null) {
        $transient = get_transient('my_transient_madmax');
        $cache_item = $cacheAdapter->getItem($cache_key);
        $compressed_transient = myplugin_compress_data(maybe_serialize($transient), 'gzip'); // Use your compression function
        $cache_item->set($compressed_transient)->expiresAfter($cache_ttl);
        $cacheAdapter->save($cache_item);
    } else {
        $transient = maybe_unserialize(myplugin_decompress_data($cached_transient, 'gzip')); // Use your decompression function
    }

    return $transient; // Return the cached or newly generated transient
}

function cache_data($cache_key, $callback) {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $compressed_cached_data = $cacheAdapter->getItem($cache_key)->get();

    if ($compressed_cached_data === null) {
        $data = $callback();
        $cache_item = $cacheAdapter->getItem($cache_key);
        $compressed_data = myplugin_compress_data(maybe_serialize($data), 'gzip'); // Use your compression function
        $cache_item->set($compressed_data);
        $cacheAdapter->save($cache_item);
    } else {
        $data = maybe_unserialize(myplugin_decompress_data($compressed_cached_data, 'gzip')); // Use your decompression function
    }

    return $data; // Return the cached or newly generated data
}

function cache_data_current_transient($transient_key, $callback, $cache_ttl = 3600) {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'cache_data_auto_transient_' . $transient_key;

    $compressed_data = cache_data($cache_key, function () use ($callback, $transient_key, $cache_ttl) {
        $results = $callback();
        if ($results instanceof WP_Query) {
            $transient_key .= '_wp_query';
        }
        $compressed_results = myplugin_compress_data(maybe_serialize($results), 'gzip'); // Use your compression function
        set_transient($transient_key, $compressed_results, $cache_ttl);
        return $compressed_results;
    });

    return maybe_unserialize(myplugin_decompress_data($compressed_data, 'gzip')); // Use your decompression function
}

function cache_api_json_responses($served, $result, $request, $server) {
    if (is_admin()) {
        return $served;
    }
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        return;
    }

    $cache_key = 'api_json_response_' . md5($request->get_route());
    $cache_ttl = 60; // 1 minute

    $compressed_response = $cacheAdapter->getItem($cache_key)->get();

    if ($compressed_response === null) {
        $response_data = $result->get_data();
        $compressed_response_data = myplugin_compress_data(json_encode($response_data), 'gzip'); // Use your compression function

        if (json_last_error() === JSON_ERROR_NONE) {
            $cache_item = $cacheAdapter->getItem($cache_key);
            $cache_item->set($compressed_response_data)->expiresAfter($cache_ttl);
            $cacheAdapter->save($cache_item);
        }
    } else {
        $decoded_response_data = json_decode(myplugin_decompress_data($compressed_response, 'gzip'), true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $result->set_data($decoded_response_data);
        }
    }

    return $served;
}

function store_ajax_json_api_cache_auto_detect() {
    global $wp, $cacheAdapter;

    if ($cacheAdapter === null) {
        // If the cacheAdapter object is not initialized, return early.
        return;
    }

    if ((defined('DOING_AJAX') && DOING_AJAX) || (isset($wp->query_vars['rest_route']) && !empty($wp->query_vars['rest_route']))) {
        $cache_key = 'ajax_json_' . md5($_SERVER['REQUEST_URI']);
        ob_start(); // Start output buffering
    }

    add_action('shutdown', function () use ($cacheAdapter, $cache_key) {
        $response = ob_get_clean(); // Get the buffered response
        $compressed_response = myplugin_compress_data($response, 'gzip'); // Use your compression function
        $cache_item = $cacheAdapter->getItem($cache_key);
        $cache_item->set($compressed_response);
        $cacheAdapter->save($cache_item);
        echo myplugin_decompress_data($compressed_response, 'gzip'); // Use your decompression function
    }, 1000); // Priority 1000 to ensure it runs late in the shutdown process
}

function cache_oembed_results($html, $url, $args) {
    $cache_key = 'oembed_' . md5($url . maybe_serialize($args));
    $cached_html = get_transient($cache_key);

    if (false !== $cached_html) {
        return $cached_html;
    }

    set_transient($cache_key, $html, HOUR_IN_SECONDS);
    return $html;
}

function get_oembed_data($url) {
    global $cacheAdapter;

    if ($cacheAdapter === null) {
        // If the cacheAdapter object is not initialized, return early.
        return false;
    }

    $cache_key = 'oembed_' . md5($url);
    $oembed_data = $cacheAdapter->getItem($cache_key)->get();

    if (!$oembed_data) {
        $oembed_url = 'https://api.max.saviezvousque.net/oembed?url=' . urlencode($url);
        $response = wp_remote_get($oembed_url);

        // Error handling
        if (is_wp_error($response)) {
            // Handle error, log it, and return false or a default value
            error_log('Oembed request error: ' . $response->get_error_message());
            return false;
        }

        $oembed_data = json_decode(wp_remote_retrieve_body($response), true);
        $cache_item = $cacheAdapter->getItem($cache_key);
        $cache_item->set($oembed_data)->expiresAfter(HOUR_IN_SECONDS); // Cache for 1 hour
        $cacheAdapter->save($cache_item);
    }

    return $oembed_data;
}

function my_custom_avatar_filter() {
    add_filter('get_avatar', 'cache_gravatar', 10, 5);
}

function cache_gravatar($avatar, $id_or_email, $size, $default, $alt) {
    // Generate a unique filename based on the email or ID
    $hash = md5(strtolower(trim(is_object($id_or_email) ? $id_or_email->comment_author_email : $id_or_email)));
    $avatar_dir = WP_CONTENT_DIR . '/cache';

    // Create the cache directory if it doesn't exist
    if (!file_exists($avatar_dir)) {
        mkdir($avatar_dir, 0755, true);
    }

    $cached_avatar_path = $avatar_dir . '/' . $hash . '-' . $size . '.png';

    // Check if the cached avatar image exists and is not older than a specified time (e.g., 24 hours)
    $cache_duration = 24 * 60 * 60; // 24 hours
    if (!file_exists($cached_avatar_path) || (time() - filemtime($cached_avatar_path)) > $cache_duration) {
        // If the cached avatar doesn't exist or is older than the cache duration, fetch and cache the avatar
        $gravatar_url = get_avatar_url($id_or_email, ['size' => $size, 'default' => $default, 'alt' => $alt]);
        if ($gravatar_url) {
            copy($gravatar_url, $cached_avatar_path);
        }
    }

    // Replace the avatar URL with the cached URL
    $cached_avatar_url = content_url('/cache/' . $hash . '-' . $size . '.png');
    $avatar = str_replace($gravatar_url, $cached_avatar_url, $avatar);

    return $avatar;
}

function ajax_cache_test() {
    if (!isset($_POST['cache_key']) || !wp_verify_nonce($_POST['_wpnonce'], 'ajax_cache_test_nonce')) {
        wp_send_json_error('Invalid request', 400);
    }

    $cache_key = sanitize_key($_POST['cache_key']);
    $cache_file = ABSPATH . '/ajax-cache/' . $cache_key . '.json';
    $time_start = microtime(true);
    $cache_duration = 21600; // 6hrs

    if (file_exists($cache_file) && (filemtime($cache_file) > (time() - $cache_duration))) {
        $data['debug'][] = 'from cache';
        $data['results'] = json_decode(file_get_contents($cache_file));
    } else {
        $args = [
            'post_type'      => ['post', 'page'],
            'showposts'      => -1,
            'no_found_rows'  => true,
        ];
        $data['debug'][] = 'create cache';
        $data['results'] = get_posts($args);
        file_put_contents($cache_file, json_encode($data['results']), LOCK_EX);
    }

    $data['time'] = microtime(true) - $time_start;
    wp_send_json($data);
}

function get_all_post_meta($post_id) {
    global $wpdb;

    $data = wp_cache_get($post_id, 'post_meta_cache');

    if ($data === false) {
        $data = array();
        $raw = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $post_id), ARRAY_A);

        foreach ($raw as $row) {
            $data[$row['meta_key']][] = $row['meta_value'];
        }

        wp_cache_add($post_id, $data, 'post_meta_cache');
    }

    return $data;
}

function get_cached_query_results() {
    global $wpdb;

    $cache_key = 'query_results_transient';
    $data = get_transient($cache_key);

    if ($data === false) {
        // Replace 'SQL query' with your actual SQL query
        $data = $wpdb->get_results('SQL query');
        set_transient($cache_key, $data, 3600 * 24);
    }

    return $data;
}

function cache_current_post() {
    global $post, $cached_post;
    $cached_post = $post;
}

function revert_to_cached_post() {
    global $post, $cached_post;
    if (is_object($cached_post) && isset($cached_post->ID) && $cached_post->ID !== $post->ID) {
        $post = $cached_post;
        setup_postdata($post);
    }
}

function cache_user_meta($user_id, $meta_key) {
    $cache_key = "user_meta_{$user_id}_{$meta_key}";
    $cached_meta = get_transient($cache_key);

    if ($cached_meta === false) {
        $cached_meta = get_user_meta($user_id, $meta_key, true);
        set_transient($cache_key, $cached_meta, 3600);
    }

    return $cached_meta;
}

function cache_term_meta($term_id, $meta_key) {
    $cache_key = "term_meta_{$term_id}_{$meta_key}";
    $cached_meta = get_transient($cache_key);

    if ($cached_meta === false) {
        $cached_meta = get_term_meta($term_id, $meta_key, true);
        set_transient($cache_key, $cached_meta, 3600);
    }

    return $cached_meta;
}

function cache_fragment($unique_key, $callback) {
    $cache_key = "fragment_{$unique_key}";
    $cached_content = get_transient($cache_key);

    if ($cached_content === false) {
        ob_start();
        call_user_func($callback);
        $cached_content = ob_get_clean();
        set_transient($cache_key, $cached_content, 3600);
    }

    echo $cached_content;
}

function cache_db_query($query_key, $query) {
    global $wpdb;
    $cache_key = "db_query_{$query_key}";
    $cached_results = get_transient($cache_key);

    if ($cached_results === false) {
        $cached_results = $wpdb->get_results($query);
        set_transient($cache_key, $cached_results, 3600);
    }

    return $cached_results;
}

function cache_widget($widget_id, $callback) {
    $cache_key = "widget_{$widget_id}";
    $cached_widget = get_transient($cache_key);

    if ($cached_widget === false) {
        ob_start();
        call_user_func($callback);
        $cached_widget = ob_get_clean();
        set_transient($cache_key, $cached_widget, 3600);
    }

    echo $cached_widget;
}

function cache_http_request($url, $request_args = []) {
    $cache_key = "http_request_" . md5($url . serialize($request_args));
    $cached_response = get_transient($cache_key);

    if ($cached_response === false) {
        $response = wp_remote_get($url, $request_args);
        $cached_response = wp_remote_retrieve_body($response);
        set_transient($cache_key, $cached_response, 3600);
    }

    return $cached_response;
}

function cache_rss_feed($feed_url) {
    $cache_key = "rss_feed_" . md5($feed_url);
    $cached_feed = get_transient($cache_key);

    if ($cached_feed === false) {
        $rss = fetch_feed($feed_url);
        if (!is_wp_error($rss)) {
            $cached_feed = $rss->get_items();
            set_transient($cache_key, $cached_feed, 3600);
        }
    }

    return $cached_feed;
}

function versatile_object_cache($key, $callback, $expiration = 3600) {
    $cached_data = wp_cache_get($key);

    if ($cached_data === false) {
        $cached_data = call_user_func($callback);
        wp_cache_set($key, $cached_data, '', $expiration);
    }

    return $cached_data;
}

function dynamic_db_cache($query, $expiration = 3600) {
    $cache_key = 'dynamic_db_cache_' . md5($query);
    $cached_result = wp_cache_get($cache_key);

    if ($cached_result === false) {
        global $wpdb;
        $result = $wpdb->get_results($query);
        wp_cache_set($cache_key, $result, '', $expiration);
    }

    return $cached_result;
}

function flexible_object_cache($key, $data = null, $expiration = 3600) {
    if ($data !== null) {
        wp_cache_set($key, $data, '', $expiration);
    } else {
        $cached_data = wp_cache_get($key);
        return $cached_data;
    }
}

function adaptable_full_page_cache($page_key, $callback, $expiration = 3600) {
    $cached_page = wp_cache_get($page_key);

    if ($cached_page === false) {
        ob_start();
        call_user_func($callback);
        $cached_page = ob_get_clean();
        wp_cache_set($page_key, $cached_page, '', $expiration);
    }

    echo $cached_page;
}

function efficient_opcache_management($file_path = null) {
    if (function_exists('opcache_invalidate')) {
        if ($file_path === null) {
            opcache_reset(); // Clear the entire OPcache
        } else {
            opcache_invalidate($file_path, true); // Clear OPcache for a specific file
        }
    }
}

function user_specific_cache($key, $callback, $expiration = 3600) {
    $user_id = get_current_user_id();
    $cache_key = "{$user_id}_{$key}";
    $cached_data = wp_cache_get($cache_key);

    if ($cached_data === false) {
        $cached_data = call_user_func($callback);
        wp_cache_set($cache_key, $cached_data, '', $expiration);
    }

    return $cached_data;
}

function post_dependency_cache($key, $callback, $post_id, $expiration = 3600) {
    $cache_key = "{$key}_{$post_id}";
    $cached_data = wp_cache_get($cache_key);

    if ($cached_data === false) {
        $cached_data = call_user_func($callback);
        wp_cache_set($cache_key, $cached_data, '', $expiration);

        // Add a cache invalidation hook on post save
        add_action('save_post', function ($post_id) use ($cache_key) {
            wp_cache_delete($cache_key);
        });
    }

    return $cached_data;
}

function cache_with_group($key, $callback, $group, $expiration = 3600) {
    $cached_data = wp_cache_get($key, $group);

    if ($cached_data === false) {
        $cached_data = call_user_func($callback);
        wp_cache_set($key, $cached_data, $group, $expiration);
    }

    return $cached_data;
}

function transient_based_cache($transient_key, $callback, $expiration = 3600) {
    $cached_data = get_transient($transient_key);

    if ($cached_data === false) {
        $cached_data = call_user_func($callback);
        set_transient($transient_key, $cached_data, $expiration);
    }

    return $cached_data;
}

function cached_query($query, $cache_key, $cache_time = 3600) {
    $cached_result = get_transient($cache_key);

    if (false === $cached_result) {
        global $wpdb;
        $result = $wpdb->get_results($query);
        set_transient($cache_key, $result, $cache_time);
    } else {
        $result = $cached_result;
    }

    return $result;
}

function custom_sqlite_cache($key, $data, $expiration = 3600) {
    try {
        // Generate an MD5-based database name from the key
        $dbName = md5($key) . '.sqlite';

        // Construct the full path to the database file within the /wp-content/ directory
        $dbPath = trailingslashit(WP_CONTENT_DIR) . $dbName;

        // Initialize SQLite database
        $db = new SQLite3($dbPath);

        // Create a table if it doesn't exist
        $db->exec('CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, data TEXT, expiration INTEGER)');

        // Check if the data exists in the cache and is not expired
        $stmt = $db->prepare('SELECT data FROM cache WHERE key = :key AND expiration > :expiration');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':expiration', time(), SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($cachedData = $result->fetchArray()) {
            return unserialize($cachedData['data']);
        } else {
            // Data not found in cache or expired, store it
            $serializedData = serialize($data);
            $stmt = $db->prepare('REPLACE INTO cache (key, data, expiration) VALUES (:key, :data, :expiration)');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':data', $serializedData, SQLITE3_TEXT);
            $stmt->bindValue(':expiration', time() + $expiration, SQLITE3_INTEGER);
            $stmt->execute();

            return $data;
        }
    } catch (Exception $e) {
        // Handle any exceptions (e.g., SQLite database creation failure)
        error_log('SQLite Cache Error: ' . $e->getMessage());

        // Try Memcached as a fallback
        if (class_exists('Memcached')) {
            $memcached = new Memcached();
            $memcached->addServer('127.0.0.1 , 11211);

            $cachedData = $memcached->get($key);
            if ($cachedData !== false) {
                return $cachedData;
            }
        }

        // Try Redis as a fallback
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect('127.0.0.1 , 6379);

            $cachedData = $redis->get($key);
            if ($cachedData !== false) {
                return unserialize($cachedData);
            }
        }

        // Return the data without caching if both Memcached and Redis fail
        return $data;
    }
}

// Function to cache ACF fields
function cache_acf_field($field_name, $post_id, $expiration = 3600) {
    $key = "acf_field_{$field_name}_{$post_id}";

    // Check if the data exists in the cache
    $cached_data = custom_sqlite_cache($key, '', $expiration);

    if ($cached_data === '') {
        // Data not found in cache, retrieve and cache ACF field
        $value = get_field($field_name, $post_id);
        custom_sqlite_cache($key, $value, $expiration);
        return $value;
    }

    return $cached_data;
}

function custom_query_optimization($sql, $query) {
    // Check if this is the main query and the query you want to optimize
    if (is_main_query() && $query->is_archive) {
        global $wpdb;

        // Modify the SQL query as needed
        $sql = "SELECT * FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC";
    }

    return $sql;
}

public function auto_opcache_warmup() {
    // Get the current plugin's folder path
    $plugin_folder = dirname(__FILE__);

    // Determine the cache expiration time (in seconds)
    $cache_time = 3600; // Change this value as needed

    // Scan the current plugin's folder for PHP files
    if (is_dir($plugin_folder)) {
        $php_files = glob($plugin_folder . '/*.php');
        if ($php_files) {
            foreach ($php_files as $file) {
                if (file_exists($file)) {
                    // Warm up OPcache for each PHP file
                    opcache_compile_file($file);
                }
            }
        }
    }

    // Schedule an event to invalidate the cache after the specified time
    wp_schedule_single_event(time() + $cache_time, 'invalidate_opcache_event');
}

// Function to invalidate the OPcache
public function invalidate_opcache() {
    // Get the current plugin's folder path
    $plugin_folder = dirname(__FILE__);

    // Invalidate OPcache for PHP files in the current plugin's folder
    if (is_dir($plugin_folder)) {
        $php_files = glob($plugin_folder . '/*.php');
        if ($php_files) {
            foreach ($php_files as $file) {
                if (file_exists($file)) {
                    // Invalidate OPcache for each PHP file
                    opcache_invalidate($file, true);
                }
            }
        }
    }
}

// Optimize loading of language files with transient caching
public function a_faster_load_textdomain($retval, $domain, $mofile) {
    global $l10n;

    if (!is_readable($mofile)) return false;

    $data = get_transient(md5($mofile));
    $mtime = filemtime($mofile);

    $mo = new MO();
    if (!$data || !isset($data['mtime']) || $mtime > $data['mtime']) {
        if (!$mo->import_from_file($mofile)) return false;
        $data = array(
            'mtime' => $mtime,
            'entries' => $mo->entries,
            'headers' => $mo->headers
        );
        set_transient(md5($mofile), $data);
    } else {
        $mo->entries = $data['entries'];
        $mo->headers = $data['headers'];
    }

    if (isset($l10n[$domain])) {
        $mo->merge_with($l10n[$domain]);
    }

    $l10n[$domain] = &$mo;

    return true;
}

// Optimize loading of language files with persistent cache
public function a_faster_load_textdomain_persistent_cache($retval, $domain, $mofile) {
    global $l10n;

    if (!is_readable($mofile)) return false;

    $cache_key = 'load_textdomain_' . md5($mofile);
    $mtime = filemtime($mofile);
    $data = wp_cache_get($cache_key);

    $mo = new MO();
    if (!$data || !isset($data['mtime']) || $mtime > $data['mtime']) {
        if (!$mo->import_from_file($mofile)) return false;
        $data = array(
            'mtime' => $mtime,
            'entries' => $mo->entries,
            'headers' => $mo->headers
        );
        wp_cache_set($cache_key, $data);
    } else {
        $mo->entries = $data['entries'];
        $mo->headers = $data['headers'];
    }

    if (isset($l10n[$domain])) {
        $mo->merge_with($l10n[$domain]);
    }

    $l10n[$domain] = &$mo;

    return true;
}

// Cache PO translations for improved performance
public function a_faster_cache_po_translations($domain, $pofile) {
    $cache_key = 'po_translations_' . md5($pofile);
    $mtime = filemtime($pofile);
    $po_entries = get_transient($cache_key);

    if (!$po_entries || !isset($po_entries['mtime']) || $mtime > $po_entries['mtime']) {
        $po = new PO();
        if (!$po->import_from_file($pofile)) return false;
        $po_entries = array(
            'mtime' => $mtime,
            'entries' => $po->entries
        );
        set_transient($cache_key, $po_entries, HOUR_IN_SECONDS);
    }

    return $po_entries['entries'];
}

public function on_acf_update_field($field) {
        // Your code for 'acf/update_field'
        $old_value = wp_cache_get('load_field/key=' . $field['key'], 'acf');
        if ($old_value !== $field) {
            wp_cache_set('load_field/key=' . $field['key'], $field, 'acf');
        }
        return $field;
}

public function on_acf_save_post($post_id) {
        // Your code for 'acf/save_post'
        wp_cache_delete($post_id, 'post_meta');
}

// Prefetch ACF options and object meta for improved performance
    public function prefetch_acf_options_and_meta($meta, $post_id, $field) {
        $store = acf_get_store('values');

        if ('options' === $post_id) {
            // Prefetch options into ACF Store
            $prefetch = function() use (&$store) {
                global $wpdb;
                $like = 'option_%';
                $prefetch_cache_key = md5('acf/pre_load_value/prefetched:options');
                $results = wp_cache_get($prefetch_cache_key);

                if (empty($results)) {
                    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->options WHERE option_name LIKE %s", $like));
                    wp_cache_set($prefetch_cache_key, $results); // Let the Object Cache handle expiry
                }

                foreach ($results as $result) {
                    $key = str_replace('options_', '', $result->option_name);
                    $key = "options:$key";
                    $store->set($key, maybe_unserialize($result->option_value));
                }

                $store->set('prefetched:options', true);
            };

            if (!$store->has('prefetched:options')) {
                $prefetch();
            }
        } else {
            // Prefetch object meta
            $prefetch = function($post_id) use (&$store) {
                $decoded = acf_decode_post_id($post_id);
                $meta_id = $decoded['id'];
                $meta_type = $decoded['type'];

                $prefetch_cache_key = md5("acf/pre_load_value/prefetched:$post_id");
                $meta_data = wp_cache_get($prefetch_cache_key);

                if (empty($meta_data)) {
                    $meta_data = get_metadata($meta_type, $meta_id);
                    wp_cache_set($prefetch_cache_key, $meta_data); // Let the Object Cache handle expiry
                }

                if (is_array($meta_data)) {
                    foreach ($meta_data as $field_name => $value) {
                        if (preg_match('/^_/', $field_name)) {
                            continue; // Ignore hidden keys
                        }
                        $real_value = is_array($value) && count($value) === 1 ? reset($value) : $value;
                        $store->set("$post_id:$field_name", $real_value);
                    }
                }

                $store->set("prefetched:$post_id", true);
            };

            if (!$store->has("prefetched:$post_id")) {
                $prefetch($post_id);
            }
        }

        $field_name = $field['name'];
        if ($store->has("$post_id:$field_name")) {
            return $store->get("$post_id:$field_name");
        }

        return $meta;
    }

// Function to get the last modified time of a file
function get_translations_file_mtime($file_path) {
    return file_exists($file_path) ? filemtime($file_path) : false;
}

// Cache translations array for improved performance
function a_faster_cache_translations_array($domain, $translations_array, $file_path) {
    $cache_key = 'translations_array_' . $domain;
    $mtime = get_translations_file_mtime($file_path);
    $entries = get_transient($cache_key);

    if ($mtime === false || !$entries || !isset($entries['mtime']) || $mtime > $entries['mtime']) {
        $entries = array(
            'mtime' => $mtime,
            'translations' => $translations_array
        );
        set_transient($cache_key, $entries, HOUR_IN_SECONDS);
    }

    return $entries['translations'];
}

// Load JSON translations and cache them
function a_faster_cache_json_translations($handle, $domain, $file_path) {
    $cache_key = 'json_translations_' . $handle;
    $mtime = get_translations_file_mtime($file_path);
    $translations_json = get_transient($cache_key);

    if ($mtime === false || !$translations_json || !isset($translations_json['mtime']) || $mtime > $translations_json['mtime']) {
        $json_translations = ''; // Load your JSON translations here
        $translations_json = array(
            'mtime' => $mtime,
            'translations' => $json_translations
        );
        set_transient($cache_key, $translations_json, HOUR_IN_SECONDS);
    }

    return $translations_json['translations'];
}

// Cache full page output for non-logged-in users
public function early_page_cache() {
    if (!is_user_logged_in()) {
        $cache_key = 'page_cache_' . md5($_SERVER['REQUEST_URI']);
        $cached_page = wp_cache_get($cache_key, 'page_cache');

        if ($cached_page) {
            echo $cached_page;
            exit;
        }
        
        ob_start(function ($buffer) use ($cache_key) {
            wp_cache_set($cache_key, $buffer, 'page_cache', HOUR_IN_SECONDS);
            return $buffer;
        });
    }
}

// Cache and retrieve posts with lazy loading
function get_post_with_lazy_cache($post_id) {
    $cache_key = 'post_' . $post_id;
    $cached_post = wp_cache_get($cache_key, 'posts');

    if (!$cached_post) {
        $cached_post = get_post($post_id);
        wp_cache_set($cache_key, $cached_post, 'posts', DAY_IN_SECONDS);
    }

    return $cached_post;
}

// Cache database query results
function cache_query_results($query) {
    $cache_key = 'query_' . md5(serialize($query));
    $cached_result = get_transient($cache_key);

    if ($cached_result === false) {
        $result = new WP_Query($query);
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        add_action('save_post', function() use ($cache_key) {
            delete_transient($cache_key);
        });
    }

    return $cached_result;
}

// Cache custom menu with checksum
function cache_custom_menu($menu_id) {
    $cache_key = 'menu_cache_' . $menu_id;
    $checksum = get_option($cache_key . '_checksum');
    $current_checksum = md5(json_encode(wp_get_nav_menu_items($menu_id)));

    if ($checksum !== $current_checksum) {
        $menu = wp_get_nav_menu_items($menu_id);
        set_transient($cache_key, $menu, DAY_IN_SECONDS);
        update_option($cache_key . '_checksum', $current_checksum);
    }

    return get_transient($cache_key);
}

// Cache oEmbed responses using an external API
function cache_oembed_with_external_api($url, $args) {
    $cache_key = 'oembed_' . md5($url . serialize($args));
    $oembed_data = get_transient($cache_key);

    if (!$oembed_data) {
        $oembed_response = wp_remote_get("https://oembed.com/provider?" . http_build_query(['url' => $url]));
        if (!is_wp_error($oembed_response)) {
            $oembed_data = json_decode(wp_remote_retrieve_body($oembed_response), true);
            set_transient($cache_key, $oembed_data, DAY_IN_SECONDS);
        }
    }

    return $oembed_data;
}

// Cache API responses with optional force refresh
function cache_api_response($endpoint, $args = [], $force_refresh = false) {
    $cache_key = 'api_response_' . md5($endpoint . serialize($args));
    $cached_response = get_transient($cache_key);

    if ($force_refresh || !$cached_response) {
        $response = wp_remote_get($endpoint, $args);
        if (!is_wp_error($response)) {
            $cached_response = wp_remote_retrieve_body($response);
            set_transient($cache_key, $cached_response, HOUR_IN_SECONDS);
        }
    }

    return $cached_response;
}

// Cache dynamic inline script data
function cache_dynamic_inline_script($handle, $data_callback) {
    $cache_key = 'inline_script_' . $handle;
    $cached_script = wp_cache_get($cache_key, 'inline_scripts');

    if (!$cached_script) {
        $data = call_user_func($data_callback);
        $cached_script = "window.$handle = " . json_encode($data) . ";";
        wp_cache_set($cache_key, $cached_script, 'inline_scripts', DAY_IN_SECONDS);
    }

    wp_add_inline_script($handle, $cached_script, 'before');
}

// Cache social network API responses
function cache_social_network_api_response($social_network_name, $endpoint, $params = [], $cache_time = HOUR_IN_SECONDS) {
    // Sanitize the input to create a safe cache key
    $cache_key = MY_PLUGIN_CACHE_PREFIX . 'social_network_' . $social_network_name . '_' . md5($endpoint . serialize($params));
    
    // Attempt to get cached data
    $cached_data = get_transient($cache_key);

    if ($cached_data === false) {
        // Perform the API request using WordPress HTTP API
        $response = wp_remote_get(add_query_arg($params, $endpoint), array('timeout' => 15));
        
        // Only store successful responses in cache
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $cached_data = wp_remote_retrieve_body($response);
            // Cache the response data
            set_transient($cache_key, $cached_data, $cache_time);
        } else {
            // You can choose to handle errors here, maybe log them or set a shorter cache time
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'API request failed';
            error_log($social_network_name . ' API Request Error: ' . $error_message);
            // Optionally set a very short transient on a failed request to avoid repeated immediate failures
            set_transient($cache_key, 'error', MINUTE_IN_SECONDS);
            return false;
        }
    }

    // Decode JSON if expected JSON response
    $decoded_data = json_decode($cached_data, true);
    return is_array($decoded_data) ? $decoded_data : $cached_data;
}

// Cache social network URL data
function cache_social_network_url_data($social_network_name, $url, $cache_time = HOUR_IN_SECONDS) {
    $cache_key = MY_PLUGIN_CACHE_PREFIX . 'social_network_url_data_' . $social_network_name . '_' . md5($url);
    
    // Attempt to get cached data
    $cached_data = get_transient($cache_key);

    if ($cached_data === false) {
        // Fetch the data from the social network or third-party service
        $data = fetch_social_network_data($url); // You need to define this function
        
        if ($data !== null) {
            // Cache the fetched data
            set_transient($cache_key, $data, $cache_time);
            $cached_data = $data;
        } else {
            // Handle the error, maybe set a short transient or log the error
            return false;
        }
    }

    return $cached_data;
}

// Function to get a cached database query result
function get_cached_db_query($query) {
    global $wpdb;
    $cache_key = 'db_query_' . md5($query);
    $cached_result = wp_cache_get($cache_key);

    if ($cached_result === false) {
        $result = $wpdb->get_results($query);
        wp_cache_set($cache_key, $result);
        return $result;
    }

    return $cached_result;
    }
}

class WPCache {

    public function __construct() {
        // Admin actions
        add_action('admin_init', [$this, 'handle_clear_cacheall_request']);
        add_action('admin_bar_menu', [$this, 'add_clear_cache_button'], 100);
        add_action('admin_notices', [$this, 'show_cache_cleared_message']);
        
        // Post events to clear cache
        add_action('save_post', [$this, 'clear_cache_on_post_events'], 10, 3);
        add_action('publish_post', [$this, 'clear_cache_on_post_events'], 10, 3);
        add_action('publish_page', [$this, 'clear_cache_on_post_events'], 10, 3);
        add_action('wp_insert_post', [$this, 'clear_cache_on_post_events'], 10, 3);

        // Scheduled cache clearing
        add_filter('cron_schedules', [$this, 'add_60minutes_cron_interval']);
        add_action('wp', [$this, 'schedule_60minutes_cache_clearing']);
        add_action('clear_cache_60minutes_event', [$this, 'clear_cache_60minutes']);
    }

    public function handle_clear_cacheall_request() {
        if (isset($_GET['clear_cacheall']) && $_GET['clear_cacheall'] == '1' && check_admin_referer('clear_cacheall')) {
            $this->clear_cacheall();
            wp_redirect(add_query_arg(['cache_cleared' => '1'], remove_query_arg('clear_cacheall')));
            exit;
        }
    }

    public function add_clear_cache_button($wp_admin_bar) {
        if (current_user_can('manage_options')) {
            $wp_admin_bar->add_node([
                'id'    => 'clear_cache_button',
                'title' => 'Clear Cache',
                'href'  => wp_nonce_url(admin_url('?clear_cacheall=1'), 'clear_cacheall')
            ]);
        }
    }

    public function show_cache_cleared_message() {
        if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Cache cleared successfully.</p></div>';
        }
    }

    public function clear_cache_on_post_events($post_id, $post, $update) {
        if (!wp_is_post_autosave($post_id) && !wp_is_post_revision($post_id)) {
            $this->clear_cacheall();
        }
    }

    public function add_60minutes_cron_interval($schedules) {
        $schedules['60min'] = [
            'interval' => 3600, // 3600 seconds in an hour
            'display'  => 'Every 60 Minutes'
        ];
        return $schedules;
    }

    public function schedule_60minutes_cache_clearing() {
        if (!wp_next_scheduled('clear_cache_60minutes_event')) {
            wp_schedule_event(time(), '60min', 'clear_cache_60minutes_event');
        }
    }

    public function clear_cache_60minutes() {
        $this->clear_cacheall();
    }
	
	private function clear_cacheall() {
    // Clear WordPress object cache
    wp_cache_flush();

    // Clear WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Clear W3 Total Cache
    if (function_exists('w3tc_pgcache_flush')) {
        w3tc_pgcache_flush();
    }

    // Clear LiteSpeed Cache
    if (class_exists('LiteSpeed_Cache')) {
        LiteSpeed_Cache::purge_all();
    }

    // Clear all transients
    global $wpdb;
    $wpdb->query("DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_%' OR '_site_transient_%')");

    // Clear Redis cache
    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            // Assuming Redis is running on the default host and port, change these if needed
            $redis->connect('127.0.0.1 , 6379);
            // If your Redis setup requires authentication, add it here like:
            // $redis->auth('yourpassword');
            
            $redis->flushAll(); // This clears the entire Redis cache

            // If you are using Redis for session storage or other critical functionalities
            // and want to selectively clear cache, consider using flushDb() to clear
            // only the currently selected database or managing cache keys more granularly.
        } catch (Exception $e) {
            // Handle exceptions, such as Redis server not being available
            error_log('Failed to clear Redis cache: ' . $e->getMessage());
        }
    }

    // Clear Memcached cache
    if (class_exists('Memcached')) {
        $memcached = new Memcached();
        $memcached->addServer('127.0.0.1 , 11211);
        $memcached->flush();
    }

    // Clear APCu cache
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }

    // Clear Opcache
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    // Clear PhpFastCache
    if (class_exists('\Phpfastcache\CacheManager')) {
        $cacheInstance = \Phpfastcache\CacheManager::getInstance('files');
        $cacheInstance->clear();
    }

    // Clear Illuminate Cache
    if (class_exists('\Illuminate\Support\Facades\Cache')) {
        \Illuminate\Support\Facades\Cache::flush();
    }

    // Clear Doctrine Cache
    if (class_exists('\Doctrine\Common\Cache\CacheProvider')) {
        $doctrineCache = \Doctrine\Common\Cache\CacheProvider::getSystemCache();
        if ($doctrineCache) {
            $doctrineCache->flushAll();
        }
    }

    // Clear Symfony Cache
    // This might require specific handling based on your Symfony cache configuration

    // Clear Stash Cache
    if (class_exists('\Stash\Pool')) {
        $stashPool = \Stash\Pool::getDefault();
        $stashPool->clear();
    }

    // Clear Laminas Cache
    // This might require specific handling based on your Laminas cache configuration

    // Your custom cacheAdapter logic, if applicable
    global $cacheAdapter;
    if ($cacheAdapter) {
        $cacheAdapter->clear();
    }

    // Any additional cache clearing logic specific to your setup can go here
	}
}

// Add menu page for MadMax WP Cache settings in the WordPress admin menu
// Add menu page for MadMax WP Cache settings in the WordPress admin menu
function madmax_wp_cache_menu() {
    add_menu_page(
        'MadMax WP Cache Settings',
        'MadMax WP Cache',
        'manage_options',
        'madmax-wp-cache',
        'madmax_wp_cache_settings_page',
        'dashicons-performance',
        100
    );
}
add_action('admin_menu', 'madmax_wp_cache_menu');

// Register settings for the MadMax WP Cache plugin
function madmax_wp_cache_register_settings() {
    register_setting('madmax-wp-cache-options', 'cacheAdapter_settings', 'madmax_wp_cache_sanitize_settings');
}
add_action('admin_init', 'madmax_wp_cache_register_settings');

// Sanitize the input values
function madmax_wp_cache_sanitize_settings($input) {
    $new_input = array();
    foreach ($input as $key => $value) {
        if (is_array($value)) {
            $new_input[$key] = array_map('sanitize_text_field', $value);
        } else {
            $new_input[$key] = sanitize_text_field($value);
        }
    }
    return $new_input;
}

// Define the settings page
function madmax_wp_cache_settings_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Fetch options
    $options = get_option('cacheAdapter_settings');
    
    ?>
    <div class="wrap">
        <h1><?= esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('madmax-wp-cache-options');
            do_settings_sections('madmax-wp-cache-options');
            ?>
            <table class="form-table">
                <!-- Add more fields as necessary -->
<tr>                    
    <th>Caching Backend</th>
    <td>
        <select name="cacheAdapter_settings[backend]">
            <?php
            $backend_options = [
                'files'     => 'Files',
                'memcached' => 'Memcached',
                'redis'     => 'Redis',
                'sqlite'    => 'Sqlite',
                'apcu'      => 'Apcu',
            ];
            foreach ($backend_options as $value => $label) {
                printf(                                    
                    '<option value="%1$s" %2$s>%3$s</option>',
                    $value,
                    isset($options['backend']) && $options['backend'] === $value ? 'selected' : '',
                    $label
                );
            }
            ?>
        </select>
    </td>
</tr>
<tr>
    <th>PHP Library</th>
    <td>
        <select name="cacheAdapter_settings[library]">
            <?php
            $libraries = ['phpfastcache', 'symfony', 'illuminate', 'doctrine', 'laminas', 'stash'];
            foreach ($libraries as $library) {
                printf(
                    '<option value="%1$s" %2$s>%1$s</option>',
                    $library,
                    isset($options['library']) && $options['library'] === $library ? 'selected' : ''
                );
            }
            ?>
        </select>
    </td>
</tr>
                <?php
                // Define all the setting fields dynamically
                $checkbox_fields = [
                    'page_cache'              => 'Enable page caching',
                    'preload_api_json'        => 'Enable API JSON preload caching',
                    'transient_cache'         => 'Enable transient caching',
                    'auto_method'             => 'Enable automatic caching method',
                    'post_cache'              => 'Enable post caching',
                    'output_buffer_cache'     => 'Enable output buffer caching',
                    'oembed_cache'            => 'Enable oEmbed caching',
                    'menu_fragment_cache'     => 'Enable menu fragment caching',
                    'ajax_request_cache'      => 'Enable AJAX request caching',
                    'api_json_cache'          => 'Enable API JSON caching',
                    'preload_menu_cache'      => 'Enable preload menu caching',
                    'preload_fragment_cache'  => 'Enable preload fragment caching',
                    'preload_transient_cache' => 'Enable preload transient caching',
                    'preload_object_cache'    => 'Enable preload object caching',
                    'preload_page_cache'      => 'Enable preload page caching',
                    // ... you can add more fields as needed ...
                ];
                foreach ($checkbox_fields as $id => $label) {
                    ?>
                    <tr>
                        <th><?= esc_html($label); ?></th>
                        <td>
                            <input type="checkbox" name="cacheAdapter_settings[<?= esc_attr($id); ?>]" value="1" <?= checked(1, isset($options[$id]) ? $options[$id] : 0, false); ?> />
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>

        <!-- Clear Cache Button -->
        <form action="<?php echo esc_url(admin_url('admin.php?page=madmax-wp-cache')); ?>" method="post">
            <?php wp_nonce_field('clear_cache'); ?>
            <input type="submit" name="clear_cache" class="button button-primary" value="Clear Cache">
        </form>
    </div>
    <?php
}

// Handle Clear Cache button action
if (isset($_POST['clear_cache'])) {
    if (check_admin_referer('clear_cache')) {
        clear_cache_function(); // Replace with your cache clearing logic
        add_action('admin_notices', 'show_cache_cleared_message');
    }
}

// Additional Cache Clearing Functions
function clear_cache_function() {
    // Implement your cache clearing logic here
    // You can use caching plugins or functions to clear the cache
    // Example: wp_cache_clear_cache();
}

function show_cache_cleared_message() {
    echo '<div class="notice notice-success is-dismissible"><p>Cache cleared successfully.</p></div>';
}

// info settings page
function madmax_wp_cache_info_menu() {
    add_menu_page(
        'MadMax WP Cache Info',
        'MadMax WP Cache Info',
        'manage_options',
        'madmax-wp-cache-info',
        'madmax_wp_cache_info_page',
        'dashicons-performance',
        100
    );
}
add_action( 'admin_menu', 'madmax_wp_cache_info_menu' );

function madmax_wp_plugin_menu() {
    add_options_page( 'MadMax WP Cache Info', 'MadMax WP Cache Info', 'manage_options', 'madmax_wp_cache_info', 'madmax_wp_cache_info_page' );
}
add_action( 'admin_menu', 'madmax_wp_plugin_menu' );

function madmax_wp_cache_info_page() {
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    ?>
    <div class="wrap">
        <h1>MadMax WP Cache Info</h1>

        <h2>APCu/Opcache</h2>
        <?php display_apcu_info(); ?>

        <h2>Redis</h2>
        <?php display_redis_info(); ?>

        <h2>Memcached</h2>
        <?php display_memcached_info(); ?>

        <h2>SQLite</h2>
        <?php display_sqlite_info(); ?>

        <h2>Files</h2>
        <?php display_files_info(); ?>
    </div>
    <?php
}

function rrmdir( $dir ) {
    $dir = realpath( $dir );
    if ( is_dir( $dir ) ) {
        $objects = scandir( $dir );
        foreach ( $objects as $object ) {
            if ( $object != "." && $object != ".." ) {
                $path = $dir . "/" . $object;
                if ( is_dir( $path ) ) {
                    rrmdir( $path );
                } else {
                    $deleted = unlink( $path );
                    if ( ! $deleted ) {
                        error_log( "Failed to delete file: $path" );
                    }
                }
            }
        }
        $removed = rmdir( $dir );
        if ( ! $removed ) {
            error_log( "Failed to remove directory: $dir" );
        }
    } else {
        error_log( "Directory does not exist: $dir" );
    }
}

function display_apcu_info() {
    if (extension_loaded('apcu')) {
        echo 'APCu is enabled. <br>';
        if (ini_get('apc.enabled')) {
            echo 'APCu cache is active. <br>';

            if (isset($_POST['clear_apcu_cache'])) {
                // Check for login or specific user role before clearing the cache
                if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
                    apcu_clear_cache();
                    echo 'APCu cache cleared. <br>';
                } else {
                    echo 'You do not have permission to clear the APCu cache. <br>';
                }
            }
            ?>
            <form method="post">
                <input type="submit" name="clear_apcu_cache" value="Clear APCu Cache">
            </form>
            <?php
        } else {
            echo 'APCu cache is not active. <br>';
        }
    } else {
        echo 'APCu is not enabled. <br>';
    }

    if (extension_loaded('Zend OPcache')) {
        echo 'OpCache is enabled. <br>';
        if (ini_get('opcache.enable')) {
            echo 'OpCache is active. <br>';

            if (isset($_POST['clear_opcache_cache'])) {
                // Check for login or specific user role before clearing the cache
                if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
                    opcache_reset();
                    echo 'OpCache cache cleared. <br>';
                } else {
                    echo 'You do not have permission to clear the OpCache cache. <br>';
                }
            }
            ?>
            <form method="post">
                <input type="submit" name="clear_opcache_cache" value="Clear OpCache Cache">
            </form>
            <?php
        } else {
            echo 'OpCache is not active. <br>';
        }
    } else {
        echo 'OpCache is not enabled. <br>';
    }
}

function display_redis_info() {
    if (extension_loaded('redis')) {
        echo 'Redis is enabled. <br>';
    } else {
        echo 'Redis is not enabled. <br>';
    }

    if (class_exists('Redis')) {
        echo 'Redis is active. <br>';
        $redis = new \Redis();
        $connected = $redis->connect('127.0.0.1 , 6379);
        if ($connected) {
            $redis->select(0);
            echo 'Redis is connected. <br>';
            $info = $redis->info();

            if (isset($info['redis_version'])) {
                echo 'Redis version: ' . $info['redis_version'] . '<br>';
            } else {
                echo 'Redis version not available <br>';
            }

            if (isset($info['uptime_in_seconds'])) {
                echo 'Redis uptime: ' . $info['uptime_in_seconds'] . ' seconds <br>';
            } else {
                echo 'Redis uptime not available <br>';
            }

            if (isset($info['used_memory'])) {
                echo 'Used memory: ' . format_size($info['used_memory']) . ' <br>';
            } else {
                echo 'Used memory not available <br>';
            }

            if (isset($_POST['clear_redis_cache'])) {
                $redis->flushDB();
                echo 'Redis cache cleared. <br>';
            }

            ?>
            <form method="post">
                <input type="submit" name="clear_redis_cache" value="Clear Redis Cache">
            </form>
            <?php
        } else {
            echo 'Redis is not connected. <br>';
        }
    } else {
        echo 'Redis is not active. <br>';
    }
}

function display_memcached_info() {
    if (extension_loaded('memcached')) {
        echo 'Memcached is enabled. <br>';
        // Check if Memcached is active and display more information and a button to clear/flush the cache
		$memcached = new \Memcached();
		$memcached->addServer('127.0.0.1 , 11211);
        $stats = $memcached->getStats();
		if ($stats) {
		    $server_key = '10.75.32.72:11211';
		    $server_stats = isset($stats[$server_key]) ? $stats[$server_key] : [];
		    $memcached_version = $memcached->getVersion();
		    if ($memcached_version !== false && isset($memcached_version[$server_key])) {
		        $memcached_version_number = $memcached_version[$server_key];
		        echo "Memcached version: $memcached_version_number <br>";
		    } else {
		        echo "Memcached version not available <br>";
		    }
            if (isset($server_stats['uptime'])) {
                $memcached_uptime = $server_stats['uptime'];
                echo "Memcached uptime: $memcached_uptime seconds <br>";
            } else {
                echo "Memcached uptime not available <br>";
            }
            if (isset($server_stats['bytes'])) {
                $memcached_bytes = $server_stats['bytes'];
                echo "Memcached cache size: " . format_size($memcached_bytes) . " <br>";
            } else {
                echo "Memcached cache size not available <br>";
            }
            if (isset($server_stats['curr_items'])) {
                $memcached_curr_items = $server_stats['curr_items'];
                echo "Number of items in cache: $memcached_curr_items <br>";
            } else {
                echo "Number of items in cache not available <br>";
            }
            if (isset($server_stats['total_items'])) {
                $memcached_total_items = $server_stats['total_items'];
                echo "Total items stored in cache: $memcached_total_items <br>";
            } else {
                echo "Total items stored in cache not available <br>";
            }
            if (isset($_POST['clear_memcached_cache'])) {
                $memcached->flush();
                echo 'Memcached cache cleared. <br>';
            }
            ?>
            <form method="post">
                <input type="submit" name="clear_memcached_cache" value="Clear Memcached Cache">
            </form>
            <?php
        } else {
            echo 'Memcached is not active. <br>';
        }
    } else {
        echo 'Memcached is not enabled. <br>';
    }
}

function display_sqlite_info() {
    $cacheDir = WP_CONTENT_DIR . '/cache/sqlite';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $dbFile = $cacheDir . '/file.sqlite';
    if (!file_exists($dbFile)) {
        touch($dbFile);
    }
    if (isset($_POST['clear_files_cache'])) {
        rrmdir($cacheDir);
        mkdir($cacheDir);
        echo 'File cache cleared. <br>';
    }
    if (extension_loaded('sqlite3')) {
        echo 'SQLite is enabled. <br>';
    } else {
        echo 'SQLite is not enabled. <br>';
        return;
    }
    if (class_exists('SQLite3')) {
        echo 'SQLite is available. <br>';
        // Check if SQLite is active and display more information and a button to clear/reset the cache
        try {
            $db = new SQLite3($dbFile);
        } catch (Exception $e) {
            echo 'Failed to connect to SQLite database: ' . $e->getMessage() . '<br>';
            return;
        }
        if (isset($_POST['clear_sqlite_cache'])) {
            // Execute a query to delete all data from the tables or delete the file itself
            // (adjust the query according to your database schema)
            $db->exec('DELETE FROM cache');
            echo 'SQLite cache cleared. <br>';
        }
        ?>
        <form method="post">
            <input type="submit" name="clear_sqlite_cache" value="Clear SQLite Cache">
        </form>
        <?php
    } else {
        echo 'SQLite is not available. <br>';
    }
}

function display_files_info() {
    $cacheDir = WP_CONTENT_DIR . '/cache/';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
        echo 'Cache directory created. <br>';
    }
    // Display more information about the files in the cache directory
    $total_size = folder_size($cacheDir);
    $file_count = folder_file_count($cacheDir);
    echo 'Total cache size: ' . format_size($total_size) . ' <br>';
    echo 'Number of files in cache: ' . $file_count . ' <br>';

    if (isset($_POST['clear_files_cache'])) {
        rrmdir($cacheDir);
        mkdir($cacheDir);
        echo 'File cache cleared. <br>';
    }
    ?>
    <form method="post">
        <input type="submit" name="clear_files_cache" value="Clear Files Cache">
    </form>
    <?php
}

function folder_size($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function folder_file_count($dir) {
    $file_count = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (!$file->isDir()) {
            $file_count++;
        }
    }
    return $file_count;
}

function format_size($bytes) {
    if ($bytes === null) {
        return 'N/A';
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $bytes >= 1024 && $i < 4; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . $units[$i];
}

// Define the menu and settings page
function madmax_minify_options_menu() {
    add_menu_page(
        'MadMax WP Minify',               // Page title
        'MadMax WP Minify',               // Menu title
        'manage_options',                 // Capability
        'madmax-wp-minify-options',       // Menu slug
        'madmax_minify_options_page',     // Callback function to display the page
        'dashicons-performance',          // Icon URL
        100                               // Position
    );
}
add_action('admin_menu', 'madmax_minify_options_menu');

// Register settings and settings sections
function madmax_minify_settings_init() {
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_compression');
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_minify_html', array('sanitize_callback' => 'intval'));
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_minify_css', array('sanitize_callback' => 'intval'));
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_minify_js', array('sanitize_callback' => 'intval'));
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_exclude_minify', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('madmax_phpfastcache_options_group', 'madmax_phpfastcache_script_files', array('sanitize_callback' => 'sanitize_text_field'));

    add_settings_section(
        'madmax_phpfastcache_settings_section', // Corrected ID
        'MadMax WP Minify Options', 
        null, 
        'madmax-wp-minify-options'
    );

    // Make sure to use the correct section ID in the add_settings_field calls.
    add_settings_field('madmax_phpfastcache_compression', 'Compression', 'madmax_phpfastcache_compression_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
    add_settings_field('madmax_phpfastcache_minify_html', 'Minify HTML', 'madmax_phpfastcache_minify_html_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
    add_settings_field('madmax_phpfastcache_minify_css', 'Minify CSS', 'madmax_phpfastcache_minify_css_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
    add_settings_field('madmax_phpfastcache_minify_js', 'Minify JS', 'madmax_phpfastcache_minify_js_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
    add_settings_field('madmax_phpfastcache_exclude_minify', 'Exclude Minify', 'madmax_phpfastcache_exclude_minify_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
    add_settings_field('madmax_phpfastcache_script_files', 'Script Files', 'madmax_phpfastcache_script_files_callback', 'madmax-wp-minify-options', 'madmax_phpfastcache_settings_section');
}
add_action('admin_init', 'madmax_minify_settings_init');

// Callback function to display the settings page
function madmax_minify_options_page() {
    ?>
    <div class="wrap">
        <h1>MadMax WP Minify Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('madmax_phpfastcache_options_group');
            do_settings_sections('madmax-wp-minify-options');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Callback function for Compression settings
// Callback function for Compression settings
function madmax_phpfastcache_compression_callback() {
    $compression = get_option('madmax_phpfastcache_compression', 'gzip');

    ?>
    <select name="madmax_phpfastcache_compression">
        <option value="gzip" <?php selected($compression, 'gzip'); ?>>Gzip</option>
        <option value="deflate" <?php selected($compression, 'deflate'); ?>>Deflate</option>
        <option value="br" <?php selected($compression, 'br'); ?>>Brotli</option> <!-- Added Brotli option -->
        <option value="none" <?php selected($compression, 'none'); ?>>None</option>
    </select>
    <?php
}

// Callback function for Minify HTML settings
function madmax_phpfastcache_minify_html_callback() {
    $minify_html = get_option('madmax_phpfastcache_minify_html', 0);

    ?>
    <select name="madmax_phpfastcache_minify_html">
        <option value="0" <?php selected($minify_html, 0); ?>>No</option>
        <option value="1" <?php selected($minify_html, 1); ?>>Yes</option>
    </select>
    <?php
}

// Callback function for Minify CSS settings
function madmax_phpfastcache_minify_css_callback() {
    $minify_css = get_option('madmax_phpfastcache_minify_css', 0);

    ?>
    <select name="madmax_phpfastcache_minify_css">
        <option value="0" <?php selected($minify_css, 0); ?>>No</option>
        <option value="1" <?php selected($minify_css, 1); ?>>Yes</option>
    </select>
    <?php
}

// Callback function for Minify JS settings
function madmax_phpfastcache_minify_js_callback() {
    $minify_js = get_option('madmax_phpfastcache_minify_js', 0);

    ?>
    <select name="madmax_phpfastcache_minify_js">
        <option value="0" <?php selected($minify_js, 0); ?>>No</option>
        <option value="1" <?php selected($minify_js, 1); ?>>Yes</option>
    </select>
    <?php
}

// Callback function for Exclude Minify settings
function madmax_phpfastcache_exclude_minify_callback() {
    $exclude_minify = get_option('madmax_phpfastcache_exclude_minify');

    ?>
    <input type="text" name="madmax_phpfastcache_exclude_minify" value="<?php echo esc_attr($exclude_minify); ?>">
    <?php
}

// Callback function for Script Files settings
function madmax_phpfastcache_script_files_callback() {
    $script_files = get_option('madmax_phpfastcache_script_files');

    ?>
    <textarea name="madmax_phpfastcache_script_files" rows="10" cols="50"><?php echo esc_textarea($script_files); ?></textarea>
    <?php
}

// Register action hooks and define constants
function madmax_should_execute() {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    return true;
}

function madmax_environment_check() {
    // Check for compression support
    $supports_brotli = extension_loaded('brotli');
    $supports_gzip = extension_loaded('zlib');
    $supports_deflate = function_exists('gzdeflate'); // Usually available with zlib

    // Test JavaScript minification
    $js_example = "function test() { // Example function\n    console.log('Hello, world!');\n}";
    $minified_js_example = minify_js2($js_example);
    $minification_js_works = strlen($minified_js_example) < strlen($js_example); // Direct comparison

    // Test HTML minification
    $html_example = "<!-- This is a comment -->\n<div>    <span>Hello, world!</span> </div>";
    $minified_html_example = minify_html2($html_example);
    $minification_html_works = strlen($minified_html_example) < strlen($html_example); // Direct comparison

    // Test CSS minification
    $css_example = "body { font-size: 16px; }\n\n/* Redundant Comment */";
    $minified_css_example = minify_css2($css_example);
    $minification_css_works = strlen($minified_css_example) < strlen($css_example); // Direct comparison

    // Check if essential directories are writable
    $cache_dir_writable = is_writable(WP_CONTENT_DIR . '/cache/');
    $minify_madmax_dir_writable = is_writable(WP_CONTENT_DIR . '/cache/minify-madmax/');

    // Compile results
    $results = [
        'brotli_installed' => $supports_brotli,
        'gzip_installed' => $supports_gzip,
        'deflate_installed' => $supports_deflate,
        'minification_css_works' => $minification_css_works,
        'minification_js_works' => $minification_js_works,
        'minification_html_works' => $minification_html_works,
        'cache_dir_writable' => $cache_dir_writable,
        'minify_madmax_dir_writable' => $minify_madmax_dir_writable,
    ];

    return $results;
}

// Ensure your minify_css function is defined and operational
function minify_css2($css_content) {
    if (!$css_content) return '';
    $css_content = preg_replace('/\/\*[\s\S]*?\*\//', '', $css_content); // Corrected regex for comments
    $css_content = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css_content); // Remove whitespace
    return $css_content;
}

function minify_js2($js_content) {
    if (!$js_content) return '';
    $js_content = preg_replace('/\/\/.*?\n|\/\*[\s\S]*?\*\//', '', $js_content); // Remove comments correctly
    $js_content = preg_replace('/\s+/', ' ', $js_content); // Reduce whitespace
    return trim($js_content);
}

function minify_html2($html_content) {
    if (!$html_content) return '';
    $html_content = preg_replace('/<!--.*?-->/', '', $html_content); // Remove comments
    $html_content = preg_replace('/>\s+</', '><', $html_content); // Remove space between tags
    return trim($html_content);
}

// You can call this function to check the environment, e.g., during plugin activation or from a plugin settings page.
$results = madmax_environment_check();

add_action('admin_menu', 'madmax_register_admin_page');

function madmax_register_admin_page() {
    add_menu_page(
        'MadMax Environment Check', // Page title
        'MadMax Check', // Menu title
        'manage_options', // Capability
        'madmax-environment-check', // Menu slug
        'madmax_admin_page_content', // Function to display the content of the page
        'dashicons-admin-tools', // Icon URL
        6 // Position
    );
}

function madmax_admin_page_content() {
    // Perform the environment check
    $check_results = madmax_environment_check();
    
    // Start output buffer to make HTML easier to manage
    ob_start();
    ?>
    <div class="wrap">
        <h2>MadMax Environment Check Results</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($check_results as $check_name => $is_successful): ?>
                <tr>
                    <td><?php echo esc_html($check_name); ?></td>
                    <td><?php echo $is_successful ? '✅ Pass' : '❌ Fail'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Output the buffer and clean it
    echo ob_get_clean();
}

// Hook for starting output buffering on the front-end
add_action('template_redirect', 'your_prefix_start_output_buffering', 0);

function your_prefix_start_output_buffering() {
    if (!is_admin()) { // Ensure this is a front-end request
        ob_start('your_prefix_output_callback_function');
    }
}

// Hook for sending headers on the front-end
add_action('send_headers', 'your_prefix_set_headers');

function your_prefix_set_headers() {
    if (!is_admin()) { // Ensure this is a front-end request
        // Check for your specific conditions before setting headers
        header('Content-Encoding: gzip');
        // More headers can be sent here
    }
}

// Your output callback function used with ob_start()
function your_prefix_output_callback_function($buffer) {
    // Modify $buffer as needed
    return $buffer;
}

// Define constants for the compression methods.
// Register action hooks and define constants for compression methods
define('MADMAX_MINIFY_COMPRESSION_NONE', 'none');
define('MADMAX_MINIFY_COMPRESSION_GZIP', 'gzip');
define('MADMAX_MINIFY_COMPRESSION_BROTLI', 'br');

add_action('template_redirect', 'madmax_minify_start_output_buffering', 0);
add_action('send_headers', 'madmax_minify_send_compression_headers');
add_action('shutdown', 'madmax_minify_end_compression_buffering');

// Start output buffering if not in admin or if a user is logged in
function madmax_minify_start_output_buffering() {
    if (!is_admin() && !is_user_logged_in()) {
        ob_start("madmax_minify_output_callback_function");
    }
}

// Output callback function for content minification and compression
function madmax_minify_output_callback_function($buffer) {
    // First, check if content is already compressed.
    if (!empty($_SERVER['HTTP_ACCEPT_ENCODING'])) {
        $accepted_encodings = explode(',', strtolower(preg_replace('/\s+/', '', $_SERVER['HTTP_ACCEPT_ENCODING'])));
        
        // Detect if content has been previously compressed.
        foreach (headers_list() as $header) {
            if (preg_match('/\bContent-Encoding:\s*(br|gzip)\b/', $header)) {
                // Content is already compressed, return the buffer without changes.
                return $buffer;
            }
        }

        // Apply compression based on client support and absence of prior compression.
        $compression_method = madmax_minify_get_compression_method();
        if (in_array('br', $accepted_encodings) && $compression_method === 'br') {
            header('Content-Encoding: br');
            return function_exists('brotli_compress') ? brotli_compress($buffer, 11) : $buffer;
        } elseif (in_array('gzip', $accepted_encodings) && $compression_method === 'gzip') {
            header('Content-Encoding: gzip');
            return gzencode($buffer, 9);
        }
    }

    // No compression applied, return original buffer.
    return $buffer;
}

/**
 * Start output buffering if the headers have not been sent and if the server has the necessary extension loaded.
 */
// Start output buffering if the headers have not been sent and if the server has the necessary extension loaded.
function madmax_minify_start_compression_buffering() {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    if (!headers_sent()) {
        $compression_method = madmax_minify_get_compression_method();

        if ($compression_method === MADMAX_MINIFY_COMPRESSION_GZIP && extension_loaded('zlib')) {
            ob_start('ob_gzhandler');
        } elseif ($compression_method === MADMAX_MINIFY_COMPRESSION_BROTLI && extension_loaded('brotli')) {
            ob_start(); // ob_start with no handler because PHP does not have a built-in ob_brotlihandler
        }
    }
}

/**
 * Ends output buffering and cleans up.
 */
// Ends output buffering and cleans up.
function madmax_minify_end_compression_buffering() {
    if (is_admin() || is_user_logged_in()) {
        return;
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

// Utility function to detect the content type from the current headers
function madmax_detect_content_type() {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            return trim(str_ireplace('Content-Type:', '', $header));
        }
    }
    return 'text/html'; // Default to HTML if content type is not determined
}

// End output buffering and send the content to the client
function madmax_minify_end_compression_buffering2() {
    if (!is_admin() && !is_user_logged_in() && ob_get_level() > 0) {
        ob_end_flush();
    }
}

/**
 * Retrieves the compression method from the WordPress options.
 *
 * @return string The compression method.
 */
// Retrieves the compression method from the WordPress options.
// Retrieves the preferred compression method based on client support
function madmax_minify_get_compression_method() {
    $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (strpos($accept_encoding, 'br') !== false && function_exists('brotli_compress')) {
        return MADMAX_MINIFY_COMPRESSION_BROTLI;
    } elseif (strpos($accept_encoding, 'gzip') !== false && extension_loaded('zlib')) {
        return MADMAX_MINIFY_COMPRESSION_GZIP;
    }
    return MADMAX_MINIFY_COMPRESSION_NONE;
}

/**
 * Outputs the appropriate Content-Encoding header based on the compression method.
 */
// Outputs the appropriate Content-Encoding header based on the compression method.
// Set appropriate headers for content compression
function madmax_minify_send_compression_headers() {
    if (!is_admin() && !is_user_logged_in() && !headers_sent()) {
        $compression_method = madmax_minify_get_compression_method();
        if ($compression_method !== MADMAX_MINIFY_COMPRESSION_NONE) {
            header('Content-Encoding: ' . $compression_method);
        }
        header('Vary: Accept-Encoding');
        header('Content-Type: text/html; charset=UTF-8');
    }
}

// Hook the end of output buffering to the shutdown action
add_action('shutdown', 'madmax_minify_end_compression_buffering');

function madmax_minify_send_compression_headers2() {
    if (is_admin() || is_user_logged_in()) {
        return;
    }

    $compression_method = madmax_minify_get_compression_method();

    if ($compression_method === MADMAX_MINIFY_COMPRESSION_GZIP) {
        header('Content-Encoding: gzip');
    } elseif ($compression_method === MADMAX_MINIFY_COMPRESSION_BROTLI) {
        header('Content-Encoding: br');
    }
    // Always set the content type to ensure correct interpretation.
    header('Content-Type: text/html; charset=UTF-8');
} 

function madmax_minify_send_compression_headers3() {
    if (!is_admin() && !is_user_logged_in() && !headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
}
 
// Use this function to compress a string manually if needed.
/**
 * Compress a string using the specified compression method.
 *
 * @param string $string The string to compress.
 * @param string $compression_method The compression method.
 * @return string The compressed string.
 */
// Compress a string using the specified compression method.
function madmax_minify_compress_string($string, $compression_method = '') {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return $string;
    }

    if ($compression_method === MADMAX_MINIFY_COMPRESSION_BROTLI && function_exists('brotli_compress')) {
        return brotli_compress($string, 11); // Using the highest compression level for Brotli.
    } elseif ($compression_method === MADMAX_MINIFY_COMPRESSION_GZIP) {
        return gzencode($string, 7); // Changed to 9 for a balance of efficiency and performance.
    }

    return $string;
}

// Compress WP content cache files function with improvements
// Hook for compressing WP content cache files.
add_action('wp', 'madmax_minify_compress_wp_content_cache_files');

// Function to compress WP content cache files.
function madmax_minify_compress_wp_content_cache_files() {
    // Check if the current user is an admin or has manage_options capability.
    // If so, do not proceed with compression.
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }

    // Get the compression method and appropriate file extension.
    $compression_method = madmax_minify_get_compression_method();
    $compressed_file_extension = $compression_method === MADMAX_MINIFY_COMPRESSION_BROTLI ? '.br' : '.gz';

    // Determine if the compression method is supported.
    if (!in_array($compression_method, [MADMAX_MINIFY_COMPRESSION_GZIP, MADMAX_MINIFY_COMPRESSION_BROTLI], true)) {
        return false;
    }

    // Define the cache directory path.
    $wp_content_cache_dir = WP_CONTENT_DIR . '/cache/';

    // Scan the directory for files.
    $files = @scandir($wp_content_cache_dir);

    // If reading the directory fails, stop the function.
    if ($files === false) {
        return false;
    }

    // Iterate over the files and compress them.
    foreach ($files as $file) {
        $file_path = $wp_content_cache_dir . $file;
        $file_info = pathinfo($file_path);

        // Only proceed if the file is of a type that should be compressed.
        if (isset($file_info['extension']) && in_array($file_info['extension'], ['html', 'css', 'js'], true)) {
            $file_content = @file_get_contents($file_path);
            
            // If reading the file fails, continue to the next file.
            if ($file_content === false) {
                continue;
            }

            // Compress the content and write it to a new file with the appropriate extension.
            $compressed_content = madmax_minify_compress_string($file_content, $compression_method);
            
            // If writing the compressed content fails, continue to the next file.
            if (@file_put_contents($file_path . $compressed_file_extension, $compressed_content) === false) {
                continue;
            }
        }
    }

    // Return true to indicate the process was successful.
    return true;
}

function madmax_compress_content2($content, $compression_method) {
    switch ($compression_method) {
        case 'br':
            return brotli_compress($content, 11); // Maximum compression
        case 'gzip':
            return gzencode($content, 7); // Default compression
        default:
            return $content; // No compression
    }
}

function enable_compression($compression_method, $mime_types = []) {
    // Only run the minification if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    if (!headers_sent() && ($compression_method === 'gzip' && extension_loaded('zlib')) || ($compression_method === 'brotli' && extension_loaded('brotli'))) {
        ob_start(function ($output) use ($compression_method, $mime_types) {
            foreach ($mime_types as $mime) {
                if (stripos(content_type(), $mime) !== false) {
                    $compression_level = $compression_method === 'brotli' ? 11 : 7;
                    $output = $compression_method === 'brotli' ? brotli_compress($output, $compression_level) : gzencode($output, $compression_level);
                    header('Content-Encoding: ' . ($compression_method === 'brotli' ? 'br' : 'gzip'));
                    return $output;
                }
            }
            return $output;
        });
    }
}

function content_type() {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            return str_ireplace('Content-Type:', '', $header);
        }
    }
    return '';
}

function get_supported_mime_types() {
    return [
    'text/plain',
    'text/html',
    'text/css',
    'text/javascript',
    'application/javascript',
    'application/json',
    'application/xml',
    'application/xhtml+xml',
    'text/xml',
    'image/svg+xml',
    'application/rss+xml',
    'application/atom+xml',
    'text/calendar',
    'text/vcard',
    'text/markdown',
    'text/csv',
    'application/pdf',
    'application/zip',
    'application/x-rar-compressed',
    'application/x-tar',
    'application/x-7z-compressed',
    'application/gzip',
    'application/x-bzip2',
    'application/x-compress',
    'application/x-msdownload',
    'application/vnd.ms-fontobject',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.visio',
    ];
}

// Remove the previous hook for the function to avoid duplication
remove_action('send_headers', 'enable_gzip_compression', 9999);

// Hook the function to the 'send_headers' action with a lower priority
add_action('send_headers', 'enable_gzip_compression', 10);

// Define the function to enable GZIP compression
function enable_gzip_compression($compression_level = 7, $mime_types = []) {
    // Only run the compression if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    if (!headers_sent() && extension_loaded('zlib')) {
        ob_start(function ($output) use ($compression_level, $mime_types) {
            $header = headers_list();
            $content_type = '';
            foreach ($header as $header_item) {
                if (strpos($header_item, 'Content-Type:') === 0) {
                    $content_type = str_replace('Content-Type:', '', $header_item);
                    $content_type = trim($content_type);
                    break;
                }
            }
            if (!empty($content_type) && in_array($content_type, $mime_types)) {
                $output = gzencode($output, $compression_level);
                // Add the UTF-8 charset to the Content-Type header
                header('Content-Encoding: gzip; charset=UTF-8');
                error_log('Debug: GZIP compression applied to content with Content-Type: ' . $content_type);
                return $output;
            }
            return $output;
        });
    }
}

function enable_brotli_compression($compression_level = 11, $mime_types = []) {
    // Only run the minification if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    if (!headers_sent() && extension_loaded('brotli')) {
        ob_start(function ($output) use ($compression_level, $mime_types) {
            $header = headers_list();
            $content_type = '';
            foreach ($header as $header_item) {
                if (strpos($header_item, 'Content-Type:') === 0) {
                    $content_type = str_replace('Content-Type:', '', $header_item);
                    $content_type = trim($content_type);
                    break;
                }
            }
            if (!empty($content_type) && in_array($content_type, $mime_types)) {
                $output = brotli_compress($output, $compression_level);
                // Add the UTF-8 charset to the Content-Type header
                header('Content-Encoding: br; charset=UTF-8');
                return $output;
            }
            return $output;
        });
        // Debug message for Brotli compression
        error_log('Debug: enable_brotli_compression function executed.');
    }
}

// Hook to send_headers action to enable Brotli compression
add_action('send_headers', function () {
    $supported_mime_types = get_supported_mime_types();
    enable_brotli_compression(11, $supported_mime_types);
}, 9999);

// Function to enable content compression with GZIP or Brotli
// Function to enable content compression with GZIP or Brotli
function madmax_minify_enable_compression_wp($mime_types = []) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }

    $compression_method = madmax_minify_get_compression_method();

    if (!headers_sent()) {
        if ($compression_method === MADMAX_MINIFY_COMPRESSION_GZIP && extension_loaded('zlib')) {
            ob_start(function ($output) use ($compression_method, $mime_types) {
                return madmax_minify_compress_string($output, $compression_method);
            });
        } elseif ($compression_method === MADMAX_MINIFY_COMPRESSION_BROTLI && extension_loaded('brotli')) {
            ob_start(function ($output) use ($compression_method, $mime_types) {
                return madmax_minify_compress_string($output, $compression_method);
            });
        }
    }
}
// Hook the function to enable compression on the 'send_headers' action
add_action('send_headers', 'madmax_minify_enable_compression_wp');

function madmax_process_and_cache_content($type, $content, $file_path) {
    // Determine minification
    $minified_content = madmax_minify_content_based_on_type($content, $type);

    // Determine compression method and compress
    $compression_method = madmax_get_preferred_compression_method();
    $compressed_content = madmax_compress_content($minified_content, $compression_method);

    // Define cache directory based on type and compression
    $cache_dir = WP_CONTENT_DIR . "/cache/minify-madmax/$type/";
    $compressed_ext = $compression_method ? ".$compression_method" : '';
    $cache_file = $cache_dir . md5($file_path) . $compressed_ext;

    // Ensure directory exists
    if (!file_exists($cache_dir) && !mkdir($cache_dir, 0755, true)) {
        error_log("Failed to create cache directory: $cache_dir");
        return;
    }

    // Save compressed content to cache
    file_put_contents($cache_file, $compressed_content);

    // Set appropriate headers
    if ($compression_method === 'gzip') {
        header('Content-Encoding: gzip');
    } elseif ($compression_method === 'br') {
        header('Content-Encoding: br');
    }

    return $cache_file;
}

function madmax_serve_cached_content($request_uri) {
    $hash = md5($request_uri);
    $compression_method = madmax_get_preferred_compression_method();
    $compressed_ext = $compression_method ? ".$compression_method" : '';
    $cache_file = WP_CONTENT_DIR . "/cache/minify-madmax/html/$hash$compressed_ext";

    if (file_exists($cache_file)) {
        if ($compression_method === 'gzip') {
            header('Content-Encoding: gzip');
        } elseif ($compression_method === 'br') {
            header('Content-Encoding: br');
        }
        header('Content-Type: text/html; charset=UTF-8');
        readfile($cache_file);
        exit;
    }
}

// Modify your template_redirect action to use this new function
add_action('template_redirect', function() {
    if (is_admin() || is_user_logged_in()) return;
    madmax_serve_cached_content($_SERVER['REQUEST_URI']);
});

// Utility function to check if a given file type should be minified
// Utility function to check if a given file type should be minified
function madmax_minify_should_minify($file_type) {
    $exclude_minify_list = get_option('madmax_minify_exclude_minify', '');
    $exclude_files = array_map('trim', explode(',', $exclude_minify_list));
    return !in_array($file_type, $exclude_files, true);
}

// Function to minify output
function madmax_minify_output($buffer) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return $buffer;
    }

    // Minify JavaScript
    if (get_option('madmax_phpfastcache_minify_js', false) && should_minify('js')) {
        $minifier = new Minify\JS($buffer);
        $buffer = $minifier->minify();
    }

    // Minify CSS
    if (get_option('madmax_phpfastcache_minify_css', false) && should_minify('css')) {
        $minifier = new Minify\CSS($buffer);
        $buffer = $minifier->minify();
    }

    // Minify HTML
    if (get_option('madmax_phpfastcache_minify_html', false) && should_minify('html')) {
        // You could use a more complex minifier here instead of simple search and replace
        $buffer = preg_replace(
            [
                '/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', // Remove comments
                '/\s+/', // Shorten multiple whitespace sequences
            ],
            [
                '',
                ' ',
            ],
            $buffer
        );
        if ($buffer !== null) {
        $buffer = str_replace('> <', '><', $buffer); // Remove space between tags
        }
    }
    return $buffer;
}

// Hook the minification function to WordPress
function madmax_minify_start() {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }

    if (get_option('madmax_minify_css', false) || 
        get_option('madmax_minify_js', false) || 
        get_option('madmax_minify_html', false)) {
        ob_start('madmax_minify_output');
    }
}
add_action('template_redirect', 'madmax_minify_start', -1);

// Function to set directory permissions recursively with error handling
function madmax_chmod_recursive($dir, $permissions) {
    if (is_dir($dir)) {
        if (!chmod($dir, $permissions)) {
            madmax_log_error("Failed to set permissions for directory: $dir");
            return false;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $itemPath = $dir . '/' . $item;
                if (is_dir($itemPath)) {
                    // Recursively set permissions for subdirectories
                    if (!madmax_chmod_recursive($itemPath, $permissions)) {
                        return false;
                    }
                } elseif (is_file($itemPath)) {
                    // Set permissions for files
                    if (!chmod($itemPath, $permissions)) {
                        madmax_log_error("Failed to set permissions for file: $itemPath");
                        return false;
                    }
                }
            }
        }
    }
    return true;
}

// Function to detect the user's browser and set cache directories and permissions
function madmax_set_cache_directories() {
    $cacheDir = WP_CONTENT_DIR . '/cache/';
    $cacheSubdirs = array('minify-madmax/css/', 'minify-madmax/js/', 'minify-madmax/html/');

    // Check if the cache directory exists or create it
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
        madmax_log_error("Failed to create cache directory: $cacheDir");
        return; // Early return if the main cache directory cannot be created
    }

    // Create subdirectories and set permissions
    foreach ($cacheSubdirs as $subdir) {
        $fullPath = $cacheDir . $subdir;
        // Check if the subdirectory exists or create it
        if (!is_dir($fullPath) && !mkdir($fullPath, 0755, true)) {
            madmax_log_error("Failed to create subdirectory: $fullPath");
            continue; // Skip setting permissions if the subdirectory cannot be created
        }
        // Check and set directory permissions (recursively)
        if (!madmax_chmod_recursive($fullPath, 0755)) {
            madmax_log_error("Failed to set permissions for directory: $fullPath");
        }
    }
}

// Function to create directories with error handling
function madmax_create_directory($dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        madmax_log_error("Failed to create directory: $dir");
        return false;
    }
    return true;
}

// Call the function to set cache directories and permissions
madmax_set_cache_directories();

// Function to store minified files in specified directories
function madmax_store_minified_file($content, $file_path, $compression_method) {
    if (empty($content)) {
        return;
    }

    // Determine the subdirectory based on the content type
    $subdir = '';
    if (stripos($file_path, '.css') !== false) {
        $subdir = 'css';
    } elseif (stripos($file_path, '.js') !== false) {
        $subdir = 'js';
    } elseif (stripos($file_path, '.html') !== false) {
        $subdir = 'html';
    }

    // Build the full file path with the correct file extension
    $file_extension = ($compression_method === 'br') ? 'br' : 'gzip';
    $fullPath = WP_CONTENT_DIR . '/cache/minify-madmax/' . $subdir . '/' . md5($file_path) . '.' . $file_extension;

    // Ensure that the directory exists and has the correct permissions
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Store the minified content in the file
    file_put_contents($fullPath, $content);

    // Optionally, set the correct permissions for the file
    chmod($fullPath, 0644); // Adjust permissions as needed

    // Add the UTF-8 charset to the Content-Type header for gzip
    if ($compression_method === 'gzip') {
        header('Content-Encoding: gzip; charset=UTF-8');
    }

    // Add the UTF-8 charset to the Content-Type header for brotli
    if ($compression_method === 'br') {
        header('Content-Encoding: br; charset=UTF-8');
    }

    // Log for debugging purposes
    error_log('Minified file stored at: ' . $fullPath);
}

// Example minification function for CSS - ensure minification functions are properly defined
function minify_css($css_content) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return $css_content; // No minification in admin panel or for admin users
    }

    $css_content = preg_replace('/\\*[^*]*\\*+([^/][^*]*\\*+)*/', '', $css_content); // Remove comments
    $css_content = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css_content); // Remove tabs, spaces, newlines, etc.
    return $css_content;
}


function minify_js($js_content) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return $js_content; // Corrected to return original content
    }
    $js_content = preg_replace('/\/\/.*?[\n\r]/', '', $js_content); // Remove single-line comments
    $js_content = preg_replace('/\/\*[\s\S]*?\*\//', '', $js_content); // Remove block comments
    $js_content = preg_replace('/\s+/', ' ', $js_content); // Shorten whitespace
    $js_content = str_replace(['{ ', ' }', '; ', ': '], ['{', '}', ';', ':'], $js_content); // Remove space around blocks, semicolons, and colons
    return trim($js_content);
}


function minify_html($html_content) {
    // Only run the minification if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    // Basic HTML minification - remove comments and whitespace
    $html_content = preg_replace('/<!--[\s\S]*?-->/', '', $html_content); // Remove HTML comments
    $html_content = preg_replace('/\s+/', ' ', $html_content); // Shorten multiple whitespace sequences
    $html_content = str_replace(['> <', ' />'], ['><', '/>'], $html_content); // Remove space between tags and spaces in self-closing tags
    return $html_content;
}

// Main function to handle minification and storage
function madmax_minify_and_store_wordpress($content, $file_path) {
    $file_info = pathinfo($file_path);
    $extension = $file_info['extension'] ?? '';
    $minified_content = '';

    // Detect the type of content and minify accordingly
    switch ($extension) {
        case 'css':
            $minified_content = minify_css($content); // Replace with your CSS minify function
            break;
        case 'js':
            $minified_content = minify_js($content); // Replace with your JS minify function
            break;
        case 'html':
            $minified_content = minify_html($content); // Replace with your HTML minify function
            break;
        default:
            // If the file is not recognized, return original content
            return $content;
    }

    // Check if compression is available and apply
    $compression = '';
    if (extension_loaded('brotli') && function_exists('brotli_compress')) {
        $minified_content = brotli_compress($minified_content);
        $compression = 'br';
    } elseif (extension_loaded('zlib')) {
        $minified_content = gzencode($minified_content, 7);
        $compression = 'gzip';
    }

    // Directory where to store minified files
    $cache_dir = WP_CONTENT_DIR . "/cache/minify-madmax/$extension/";

    // Ensure the directory exists
    if (!madmax_create_directory($cache_dir)) {
        madmax_log_error("Failed to create directory: $cache_dir");
        return false; // Directory creation failed, handle error as needed
    }

    // Generate the filename
    $hashed_name = md5($content); // Hash based on content for uniqueness
    $file_name = $hashed_name . ($compression ? ".$compression" : '');

    // Full Path
    $full_path = $cache_dir . $file_name;

    // Store the minified content
    if (file_put_contents($full_path, $minified_content) === false) {
        madmax_log_error("Failed to store minified content at: $full_path");
        return false;
    }

    // Set the correct permissions for the file
    if (!chmod($full_path, 0644)) {
        madmax_log_error("Failed to set permissions for file: $full_path");
        return false;
    }

    return $full_path; // Return the path to the stored file
}

// Function to serve minified files from cache directory with error handling
function madmax_serve_minified_file($file_path) {
    // Only run the minification if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    $cache_dir = WP_CONTENT_DIR . '/cache/minify-madmax/';

    $cache_subdir = '';
    if (strpos($file_path, '/css/') !== false) {
        $cache_subdir = 'css/';
    } elseif (strpos($file_path, '/js/') !== false) {
        $cache_subdir = 'js/';
    } elseif (strpos($file_path, '/html/') !== false) {
        $cache_subdir = 'html/';
    }

    // Determine the correct file extension based on the request
    $compression_method = 'gzip';
    if (strpos($file_path, '.br.') !== false) {
        $compression_method = 'br';
    }

    $cache_file_path = $cache_dir . $cache_subdir . md5($file_path) . '.' . $compression_method;

    if (file_exists($cache_file_path)) {
        if ($compression_method === 'br') {
            header('Content-Encoding: br');
        } else {
            header('Content-Encoding: gzip');
        }

        // Set appropriate Content-Type header
        header('Content-Type: text/' . $compression_method . '; charset=UTF-8');
		header('Cache-Control: public, max-age=3600'); 

        // Read and output the file
        if (readfile($cache_file_path) === false) {
            madmax_log_error("Failed to read file: $cache_file_path");
        }
        exit;
    } else {
        madmax_log_error("Minified file not found: $cache_file_path");
    }
}

// Serve minified files from cache directory
add_action('init', function () {
    if (isset($_GET['madmax_minified_file'])) {
        $file_path = sanitize_text_field($_GET['madmax_minified_file']);
        madmax_serve_minified_file($file_path);
    }
});

add_action('wp', 'madmax_compress_wp_content');

function madmax_compress_wp_content() {
    if (is_admin() || current_user_can('manage_options')) {
        return;
    }

    $compression_method = madmax_get_preferred_compression_method();
    if (empty($compression_method)) {
        return;
    }

    $wp_content_cache_dir = WP_CONTENT_DIR . '/cache/';
    madmax_compress_directory($wp_content_cache_dir, $compression_method);
}

function madmax_compress_directory($dir, $compression_method) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isDir()) {
            continue;
        }
        
        // Use getRealPath() to get the file path as a string
        $filePath = $file->getRealPath();
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        if (in_array($ext, ['html', 'css', 'js'])) {
            $content = file_get_contents($filePath);
            $compressed_content = madmax_compress_content($content, $compression_method);
            
            // Append the correct extension based on the compression method
            $compressedFilePath = $filePath . ($compression_method === 'gzip' ? '.gz' : '.br');
            file_put_contents($compressedFilePath, $compressed_content);
        }
    }
}

function madmax_compress_content($content, $compression_method) {
    switch ($compression_method) {
        case 'gzip':
            return gzencode($content, 7); // Maximum compression level for Gzip
        case 'br':
            return function_exists('brotli_compress') ? brotli_compress($content, 11) : $content; // Maximum compression level for Brotli, with a fallback in case the function isn't available
        default:
            return $content; // Return the original content if no recognized compression method is provided
    }
}


// Call this function to dynamically minify and serve the minified content for CSS and JS files.
add_action('template_redirect', 'madmax_minify_and_serve_assets');
function madmax_minify_and_serve_assets() {
    // Implementation depends on your setup: checking request URIs, serving appropriate content, etc.
    // This placeholder function is to indicate where you'd integrate such logic.
}

// Example for hooking into WordPress to modify script and style tags.
add_action('wp_enqueue_scripts', 'madmax_modify_asset_urls', PHP_INT_MAX);
function madmax_modify_asset_urls3() {
    global $wp_styles, $wp_scripts;
    foreach (array_merge($wp_styles->queue, $wp_scripts->queue) as $handle) {
        $obj = isset($wp_styles->registered[$handle]) ? $wp_styles->registered[$handle] : $wp_scripts->registered[$handle];
        if (!isset($obj->src)) continue;
        $obj->src = madmax_modify_url($obj->src);
    }
}

function madmax_modify_url($url) {
    // Logic to modify the URL, e.g., pointing to a minified version.
    return $url; // Placeholder
}

// Minify and store HTML content
function madmax_minify_html_content2($html_content) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return $html_content;
    }

    // Initialize the chosen compression method to none
    $compression_method = '';

    // Check if Brotli compression is available and preferred
    if (extension_loaded('brotli')) {
        $minified_html = brotli_compress($html_content);
        $compression_method = 'br';
    } elseif (extension_loaded('zlib')) {
        // Check if Gzip compression is available and preferred
        $minified_html = gzencode($html_content, 7); // Change compression level to 7
        $compression_method = 'gzip';
    } else {
        // No compression available
        $minified_html = $html_content;
    }

    // Determine the subdirectory based on the content type
    $subdir = 'html';

    // Build the full file path
    $fullPath = WP_CONTENT_DIR . '/cache/minify-madmax/' . $subdir . '/' . md5($_SERVER['REQUEST_URI']) . '.' . $compression_method;

    // Ensure that the directory exists and has the correct permissions
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Store the minified content in the file
    file_put_contents($fullPath, $minified_html);

    // Optionally, set the correct permissions for the file
    chmod($fullPath, 0644); // Adjust permissions as needed

    // Add the UTF-8 charset to the Content-Type header for gzip
    if ($compression_method === 'gzip') {
        header('Content-Encoding: gzip; charset=UTF-8');
    }

    // Add the UTF-8 charset to the Content-Type header for brotli
    if ($compression_method === 'br') {
        header('Content-Encoding: br; charset=UTF-8');
    }

    // Log for debugging purposes
    error_log('Minified file stored at: ' . $fullPath);

    // Return the minified content
    return $minified_html;
}

function madmax_minify_content_based_on_type($content, $type) {
    switch ($type) {
        case 'css':
            return minify_css($content);
        case 'js':
            return minify_js($content);
        case 'html':
            return minify_html($content);
        default:
            // Return unmodified content for unrecognized types
            return $content;
    }
}

function madmax_verify_compression_configuration() {
    if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
        return;
    }

    if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
        // Gzip compression is accepted by the client.
    } elseif (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'br') !== false) {
        // Brotli compression is accepted by the client.
    } else {
        // No supported compression format is accepted by the client.
        // Consider not applying compression.
    }
}

function madmax_fetch_and_cache_asset($asset_url) {
    // Check if URL is absolute, if not, try to resolve to a local file path or complete URL
    if (!preg_match('#^https?://#', $asset_url)) {
        // Attempt to convert relative path to absolute URL or local filesystem path
        $asset_url = madmax_resolve_asset_url($asset_url);
    }

    if (filter_var($asset_url, FILTER_VALIDATE_URL)) {
        // It's a valid URL, fetch content using file_get_contents() or equivalent
        $content = file_get_contents($asset_url);
        // Cache content logic here
    } else {
        // Log error or handle case where asset URL cannot be resolved
        error_log("Cannot resolve asset URL: $asset_url");
    }
}

function madmax_resolve_asset_url($relative_path) {
    // Logic to convert a relative path to a full URL or a valid filesystem path
    // This might involve checking if the file exists within the WordPress directory structure
    // and returning a site URL or a physical file path accordingly.
    $absolute_path = ABSPATH . ltrim($relative_path, '/');
    if (file_exists($absolute_path)) {
        return $absolute_path; // Or convert to URL using site_url() or similar
    }
    return false;
}

function madmax_modify_asset_urls() {
    global $wp_styles, $wp_scripts;

    // Function to resolve a URL to a local path
    function url_to_local_path($url) {
        $content_dir = untrailingslashit(WP_CONTENT_DIR);
        $content_url = untrailingslashit(content_url());
        if (strpos($url, $content_url) === 0) {
            return $content_dir . substr($url, strlen($content_url));
        }
        return false; // Not a local asset
    }

    // Process all enqueued styles and scripts
    foreach ([$wp_styles, $wp_scripts] as $queue) {
        foreach ($queue->queue as $handle) {
            $asset = isset($queue->registered[$handle]) ? $queue->registered[$handle] : false;
            if (!$asset || empty($asset->src)) continue;

            // Skip modification for admin assets or external assets
            if (strpos($asset->src, '/wp-admin/') !== false || strpos($asset->src, '/wp-includes/') !== false || preg_match('#^https?://#', $asset->src)) {
                continue;
            }

            // Attempt to convert URL to local path
            $local_path = url_to_local_path($asset->src);
            if ($local_path && file_exists($local_path)) {
                // Here you can add logic to modify the local file path or URL as needed
                // For example, replace $asset->src with a minified version's URL
                // This is just an example to demonstrate modification; actual logic will depend on your setup
                // $asset->src = str_replace('.js', '.min.js', $asset->src); // Example for JS
                // $asset->src = str_replace('.css', '.min.css', $asset->src); // Example for CSS
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'madmax_modify_asset_urls', PHP_INT_MAX);

function madmax_process_asset_for_caching_and_compression($src) {
    $parsed_url = parse_url($src);
    // Assume $base_cache_dir and $base_cache_url are defined properly
    $cache_file_path = madmax_get_cache_file_path($parsed_url['path']);

    if (madmax_should_cache_file($parsed_url['path'])) {
        $compressed_path = madmax_compress_and_cache_file($src, $cache_file_path);
        if ($compressed_path) {
            return madmax_get_cache_url_from_path($compressed_path);
        }
    }

    return $src; // Return original source if not cachable or on failure
}

function madmax_compress_and_cache_file($src, $cache_file_path) {
    // Determine preferred compression method using your existing function
    $compression_method = madmax_get_preferred_compression_method();

    // Check if a cached version already exists
    $compressed_file_extension = $compression_method === 'br' ? '.br' : ($compression_method === 'gzip' ? '.gz' : '');
    $compressed_cache_file_path = $cache_file_path . $compressed_file_extension;

    if (file_exists($compressed_cache_file_path)) {
        // Cached file exists, return its path
        return $compressed_cache_file_path;
    }

    // Fetch original content
    $content = file_get_contents($src);
    if ($content === false) {
        // Failed to fetch content, return false
        return false;
    }

    // Apply compression based on the preferred method
    switch ($compression_method) {
        case 'gzip':
            $compressed_content = gzencode($content, 7); // Maximum level of compression
            break;
        case 'br':
            $compressed_content = function_exists('brotli_compress') ? brotli_compress($content, 11) : false; // Maximum level of compression for Brotli
            break;
        default:
            // No supported compression method
            $compressed_content = false;
    }

    if ($compressed_content === false) {
        // Compression failed or not supported, handle as needed
        return false;
    }

    // Ensure the cache directory exists
    $cache_dir = dirname($compressed_cache_file_path);
    if (!is_dir($cache_dir) && !mkdir($cache_dir, 0755, true)) {
        // Failed to create cache directory
        return false;
    }

    // Save compressed content to the cache file
    if (file_put_contents($compressed_cache_file_path, $compressed_content) === false) {
        // Failed to save the compressed content
        return false;
    }

    // Return path to the cached file
    return $compressed_cache_file_path;
}

function madmax_get_preferred_compression_method() {
    $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (strpos($accept_encoding, 'br') !== false && function_exists('brotli_compress') && extension_loaded('brotli')) {
        return 'br';
    } elseif (strpos($accept_encoding, 'gzip') !== false && extension_loaded('zlib')) {
        return 'gzip';
    }
    return '';
}

function madmax_get_cache_file_url($original_url, $base_cache_dir, $base_cache_url, $type) {
    // Ensure $original_url is a string
    if (!is_string($original_url) || empty($original_url)) {
        // If not, log the error and return the original URL (or false if it's not a string)
        error_log('Invalid URL passed to madmax_get_cache_file_url: ' . var_export($original_url, true));
        return $original_url;
    }

    $parsed_url = parse_url($original_url);
    if (false === $parsed_url) {
        // URL parsing failed, log and return the original URL
        error_log('Failed to parse URL in madmax_get_cache_file_url: ' . $original_url);
        return $original_url;
    }

    $path = $parsed_url['path'];
    
    // Generate a unique filename based on the original URL
    $filename = md5($path) . (strpos($path, '.css') ? '.css' : '.js');
    $cache_dir = "$base_cache_dir/$type";
    $cache_file = "$cache_dir/$filename";
    $cache_url = "$base_cache_url/$type/$filename";

    // Check if the cache file already exists
    if (!file_exists($cache_file)) {
        // Ensure the cache directory exists
        if (!file_exists($cache_dir) && !wp_mkdir_p($cache_dir)) {
            error_log("Failed to create cache directory: $cache_dir");
            return $original_url; // Fallback to original URL on failure
        }
        
        // Fetch the original content
        $content = file_get_contents($original_url);
        if (false === $content) {
            error_log("Failed to fetch content for: " . $original_url);
            return $original_url;
        }

        // Minify and compress content based on type
        $minified_content = madmax_minify_content_based_on_type($content, $type);
        $compressed_content = madmax_compress_content($minified_content, madmax_get_preferred_compression_method());

        // Save the processed content to the cache file
        file_put_contents($cache_file, $compressed_content);
    }

    return $cache_url;
}

function madmax_get_cache_file_url3($original_url, $base_cache_dir, $base_cache_url, $type) {
    // Resolve relative URL to absolute file path
    $absolute_path = madmax_resolve_path($original_url);
    if (!$absolute_path || !file_exists($absolute_path)) {
        error_log("File does not exist or cannot be resolved: " . $original_url);
        return $original_url; // Skip processing if file doesn't exist
    }
    
    // Proceed with existing logic...
    // Now, $absolute_path refers to a valid, existing file
}

function madmax_resolve_path($relative_or_absolute_url) {
    $parsed_url = parse_url($relative_or_absolute_url);
    $path = $parsed_url['path'] ?? '';

    // Check if the path is already absolute and points to an existing file
    if (file_exists($path)) {
        return $path;
    }

    // Attempt to resolve relative path to absolute path
    $absolute_path = ABSPATH . ltrim($path, '/');
    if (file_exists($absolute_path)) {
        return $absolute_path;
    }

    // Path could not be resolved to an existing file
    return null;
}


add_action('admin_menu', 'madmax_add_admin_page');
function madmax_add_admin_page() {
    add_menu_page(
        'Compression & Cache Check', // Page title
        'Comp & Cache Check', // Menu title
        'manage_options', // Capability
        'madmax-comp-cache-check', // Menu slug
        'madmax_comp_cache_check_page' // Function to display the page content
    );
}

function madmax_comp_cache_check_page() {
    // Security check to ensure only those with appropriate permissions can access this page
    if (!current_user_can('manage_options')) {
        return;
    }

    // Output the header of the admin page
    echo '<div class="wrap">';
    echo '<h1>Compression & Cache Check</h1>';

    // Perform checks and display results
    madmax_check_compression_support();
    madmax_check_cache_directory(WP_CONTENT_DIR . '/cache/minify-madmax/');

    // Close the div wrap
    echo '</div>';
}

function madmax_check_compression_support() {
    echo '<h2>Compression Support</h2>';
    $supports_gzip = extension_loaded('zlib');
    $supports_brotli = function_exists('brotli_compress');
    $supports_deflate = function_exists('gzdeflate');

    echo "<p>GZIP Support: " . ($supports_gzip ? "Yes" : "No") . "</p>";
    echo "<p>Brotli Support: " . ($supports_brotli ? "Yes" : "No") . "</p>";
    echo "<p>Deflate Support: " . ($supports_deflate ? "Yes" : "No") . "</p>";
}

function madmax_check_cache_directory($cache_dir) {
    echo '<h2>Cache Directory Check</h2>';

    // Check if cache directory exists and is writable
    if (!is_dir($cache_dir)) {
        echo "<p>Cache directory does not exist: $cache_dir</p>";
        return;
    }
    if (!is_writable($cache_dir)) {
        echo "<p>Cache directory is not writable: $cache_dir</p>";
        return;
    }
    echo "<p>Cache directory exists and is writable.</p>";

    // Optional: Check for serving capability by writing and reading a test file (omitted for brevity)
}

function madmax_ensure_cache_directories_exist() {
    $base_cache_dir = WP_CONTENT_DIR . '/cache';
    $subdirs = ['minify-madmax/css', 'minify-madmax/js', 'minify-madmax/html'];

    if (!is_dir($base_cache_dir) && !wp_mkdir_p($base_cache_dir)) {
        error_log("Could not create base cache directory: $base_cache_dir");
        return;
    }

    foreach ($subdirs as $subdir) {
        $full_path = WP_CONTENT_DIR . '/cache/' . $subdir;
        if (!is_dir($full_path) && !wp_mkdir_p($full_path)) {
            error_log("Could not create cache subdirectory: $full_path");
        }
    }
}


function madmax_cache_page_content() {
    if (is_admin() || is_user_logged_in()) return;

    $uri = $_SERVER['REQUEST_URI'];
    $fileType = 'html'; // Default to HTML
    if (preg_match('/\.css$/', $uri)) $fileType = 'css';
    else if (preg_match('/\.js$/', $uri)) $fileType = 'js';

    $hash = md5($uri);
    $cache_dir = WP_CONTENT_DIR . "/cache/minify-madmax/$fileType";
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    $cache_file = "$cache_dir/$hash.$fileType";

    // Check if a cached version exists and is recent enough
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < HOUR_IN_SECONDS) {
        readfile($cache_file);
        exit;
    }

    // Start output buffering to capture the page content
    ob_start(function ($buffer) use ($cache_file, $fileType) {
        // Minification logic (placeholder)
        $minified_content = $buffer; // Assume minification logic based on $fileType

        // Save the minified content to the cache file
        file_put_contents($cache_file, $minified_content);
        return $buffer;
    });
}
add_action('template_redirect', 'madmax_cache_page_content');

function madmax_serve_compressed_content($request_uri) {
    $fileType = 'html'; // Default to HTML
    if (preg_match('/\.css$/', $request_uri)) $fileType = 'css';
    else if (preg_match('/\.js$/', $request_uri)) $fileType = 'js';

    $hash = md5($request_uri);
    $cache_dir = WP_CONTENT_DIR . "/cache/minify-madmax/$fileType";
    $br_file = "$cache_dir/$hash.br";
    $gzip_file = "$cache_dir/$hash.gzip";
    $compression_method = madmax_get_preferred_compression_method();

    // Serve Brotli compressed file if supported and smaller
    if ($compression_method === 'br' && file_exists($br_file) && (!file_exists($gzip_file) || filesize($br_file) <= filesize($gzip_file))) {
        header('Content-Encoding: br');
        madmax_serve_file_with_type($br_file, $fileType);
        exit;
    }

    // Serve Gzip compressed file if supported and smaller or if Brotli is not available
    if ($compression_method === 'gzip' && file_exists($gzip_file)) {
        header('Content-Encoding: gzip');
        madmax_serve_file_with_type($gzip_file, $fileType);
        exit;
    }

    // No compression or file doesn't exist, try to serve the raw file if exists
    $raw_file = "$cache_dir/$hash.$fileType";
    if (file_exists($raw_file)) {
        madmax_serve_file_with_type($raw_file, $fileType);
        exit;
    }
}

function madmax_serve_file_with_type($file, $type) {
    switch ($type) {
        case 'css':
            header('Content-Type: text/css');
            break;
        case 'js':
            header('Content-Type: application/javascript');
            break;
        default:
            header('Content-Type: text/html; charset=UTF-8');
    }
    readfile($file);
    exit;
}

// Register the template redirect action to serve cached content
add_action('template_redirect', function() {
    if (is_admin() || is_user_logged_in()) return;
    
    $request_uri = $_SERVER['REQUEST_URI'];
    madmax_serve_cached_content($request_uri);
});

/**
 * Serve cached content based on request URI.
 */
function madmax_serve_cached_content2($request_uri) {
    $fileType = 'html'; // Default file type
    if (preg_match('/\.css$/', $request_uri)) $fileType = 'css';
    elseif (preg_match('/\.js$/', $request_uri)) $fileType = 'js';

    $cache_file = madmax_get_cache_file_path($request_uri, $fileType);

    if (file_exists($cache_file)) {
        switch ($fileType) {
            case 'css':
                header('Content-Type: text/css');
                break;
            case 'js':
                header('Content-Type: application/javascript');
                break;
            default:
                header('Content-Type: text/html; charset=UTF-8');
        }

        // Determine compression method and serve
        $compression_method = madmax_get_preferred_compression_method();
        if ($compression_method === 'gzip') {
            header('Content-Encoding: gzip');
        } elseif ($compression_method === 'br') {
            header('Content-Encoding: br');
        }

        readfile($cache_file);
        exit;
    }
}

/**
 * Get the preferred compression method.
 */
function madmax_get_preferred_compression_method2() {
    // Check if the server supports Brotli and GZip
    $supports_brotli = function_exists('brotli_compress');
    $supports_gzip = function_exists('gzencode');

    // Get the Accept-Encoding header from the client
    $accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';

    // Preference order (you could adjust based on your own performance tests or preferences)
    $preference = ['br', 'gzip'];

    foreach ($preference as $encoding) {
        if ($encoding === 'br' && $supports_brotli && strpos($accept_encoding, 'br') !== false) {
            return 'br';
        } elseif ($encoding === 'gzip' && $supports_gzip && strpos($accept_encoding, 'gzip') !== false) {
            return 'gzip';
        }
    }

    // Default to no compression if neither is supported/preferred
    return '';
}

function madmax_get_cache_file_path2($original_url, $type) {
    $parsed_url = parse_url($original_url);
    $path = $parsed_url['path'];
    $filename = md5($path) . ".$type";

    $cache_dir = WP_CONTENT_DIR . "/cache/minify-madmax/$type";
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    $cache_file = "$cache_dir/$filename";

    // Determine compression
    $compression_method = madmax_get_preferred_compression_method();
    $compressed_cache_file = $cache_file . '.' . $compression_method;

    if (!file_exists($compressed_cache_file)) {
        // Fetch the original content
        $content = file_get_contents($_SERVER['DOCUMENT_ROOT'] . $path);
        if ($content === false) return $original_url; // Early return if content could not be fetched

        // Minify content based on type
        switch ($type) {
            case 'html':
            case 'php': // Assuming PHP output is HTML
                $minified_content = madmax_minify_html($content);
                break;
            case 'css':
                $minified_content = madmax_minify_css($content);
                break;
            case 'js':
                $minified_content = madmax_minify_js($content);
                break;
            default:
                $minified_content = $content; // Default case if type is unknown
        }

        // Simulate compression based on method
        $compressed_content = '';
        if ($compression_method === 'gzip') {
            $compressed_content = gzencode($minified_content, 9);
        } elseif ($compression_method === 'br') {
            $compressed_content = brotli_compress($minified_content);
        } else {
            $compressed_content = $minified_content; // No compression
        }

        file_put_contents($compressed_cache_file, $compressed_content);
    }

    return $compressed_cache_file;
}

function madmax_minify_html($content) {
    $search = array(
        '/\>[^\S ]+/s',     // strip whitespaces after tags, except space
        '/[^\S ]+\</s',     // strip whitespaces before tags, except space
        '/(\s)+/s',         // shorten multiple whitespace sequences
        '/<!--(.|\s)*?-->/' // Remove HTML comments
    );
    $replace = array(
        '>',
        '<',
        '\\1',
        ''
    );
    return preg_replace($search, $replace, $content);
}

function madmax_minify_css($content) {
    $search = array(
        '/\s+/',
        '/\;\}/'
    );
    $replace = array(
        ' ',
        '}'
    );
    return preg_replace($search, $replace, $content);
}

function madmax_minify_js($content) {
    // This is a very basic and not highly effective JS minification approach
    $search = array(
        '/\s+/',
        '/\/\*.*?\*\//', // Remove simple block comments
    );
    $replace = array(
        ' ',
        ''
    );
    return preg_replace($search, $replace, $content);
}

function madmax_get_preferred_compression_method3() {
    // Check server capabilities for Brotli and GZip compression
    $supports_brotli = function_exists('brotli_compress');
    $supports_gzip = function_exists('gzencode');

    // Retrieve the Accept-Encoding header from the client
    $accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';

    // Define the order of preference for compression methods
    $preference = ['br', 'gzip'];

    // Iterate through the preference list and select the first supported method
    foreach ($preference as $encoding) {
        if ($encoding === 'br' && $supports_brotli && strpos($accept_encoding, 'br') !== false) {
            return 'br'; // Brotli is supported by both server and client
        } elseif ($encoding === 'gzip' && $supports_gzip && strpos($accept_encoding, 'gzip') !== false) {
            return 'gzip'; // GZip is supported by both server and client
        }
    }

    // Fallback to no compression if none of the preferred methods are supported
    return '';
}

// Exclude scripts from deferring or making asynchronous
function madmax_exclude_scripts($handle) {
    // Only run the minification if we're not in the admin panel or if a user is not logged in with admin capabilities
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    $exclude_scripts = array(
        'jquery.min.js',
        'jquery-migrate.min.js',
        'elementor-pro/',
        'elementor/',
        'jet-blog/assets/js/lib/slick/slick.min.js',
        'jet-elements/',
        'jet-menu/',
        'elementorFrontendConfig',
        'ElementorProFrontendConfig',
        'hasJetBlogPlaylist',
        'JetEngineSettings',
        'jetMenuPublicSettings',
        'webpack-pro.runtime.min.js',
        'webpack.runtime.min.js',
        'elements-handlers.min.js',
        'jquery.smartmenus.min.js',
        'jquery.min.js',
        'jquery.smartmenus.min.js',
        'webpack.runtime.min.js',
        'webpack-pro.runtime.min.js',
        'frontend.min.js',
        'frontend-modules.min.js',
        'elements-handlers.min.js',
        'elementorFrontendConfig',
        'ElementorProFrontendConfig',
        'imagesloaded.min.js',
        'avada-header.js',
        'modernizr.js',
        'jquery.easing.js',
        'avadaHeaderVars',
        'jquery.min.js',
        'global.js',
        'workbox-sw.js',
        'menu.min.js',
        'jquery-3.6.3.min.js',
        'jquery-migrate-3.4.1.min.js',
        'ajax-search.js',
        'players.js',
        'single.js',
        'frontend.min.js',
        'jquery-migrate.min.js',
        'a11y.min.js',
        'jquery.timeago.fr.js',
        'a11y.min.js',
        'i18n.min.js',
        'ajax-search.js',
        'jquery.timeago.fr.js',
        'jquery.min.js',
        'back-to-top.js',
        'menu.min.js',
        'menu.js',
        'dom-ready.min.js',
        'madmax-admin.js',
        'madmax-sw.js',
        'madmax-pwa.js',
        'api-fetch.min.js',
        'data.min.js',
        'Divi/js/scripts.min.js',
        'et_pb_custom',
        'elm.style.display',
        'jquery-min',
        'owl-carousel',
        'owl-carousel-custom',
        'lightbox-min-js',
        'lightbox-js',
        'aos-js',
        'aos-custom-js',
        'nav-js',
        'blog-js',
        'contact-js',
        'jquery-migrate.min.js',
        'redux.js',
        'uiLibrary.js',
        'introductions.js',
        'checkout.min.js',
        'deprecated.min.js',
        'helpers.js',
        'componentsNew.js',
        'indexation.js',
        'replacementVariableEditor.js',
        'socialMetadataForms.js',
        'analysis.js',
        'analysisReport.js',
        'fr.js',
        'externals-redux.js',
        'editor-modules.js',
        'yoast-premium-prominent-words-indexation-2150.min.js',
        'externals-components.js',
        'first-time-configuration.js',
        'settings.js'
    );

    // Remove file extensions (if present) and check for inclusion
    $handle_without_extension = preg_replace('/\..+$/', '', $handle);
    return in_array($handle, $exclude_scripts) || in_array($handle_without_extension, $exclude_scripts);
}

// Hook to defer or async scripts while excluding some
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if (madmax_exclude_scripts($handle)) {
        $tag = str_replace(' src', ' defer="defer" src', $tag);
    }

    return $tag;
}, 10, 3);

function remove_emoji_scripts() {
    // Remove Emoji JavaScript
    wp_dequeue_script('emoji');

    // Remove Emoji Styles
    wp_dequeue_style('emoji');
}
add_action('wp_enqueue_scripts', 'remove_emoji_scripts');

function remove_emoji_meta_tag() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
}

function my_cache_plugin_deactivate() {
    // Remove settings
    $options_to_delete = array(
        'library',
        'backend',
        'page_cache',
        'object_cache',
        'transient_cache',
        'auto_method',
        'post_cache',
        'output_buffer_cache',
        'cache_oembed',
        'cache_menu_fragment',
        'handle_ajax_request',
        'cache_api_request',
        'preload_menu_cache',
        'preload_object_cache_fragment',
        'preload_api_json',
        'preload_transient_cache',
        'preload_object_cache',
        'preload_page_cache',
        'madmax_phpfastcache_compression',
        'madmax_phpfastcache_minify_html',
        'madmax_phpfastcache_minify_css',
        'madmax_phpfastcache_minify_js',
        'madmax_phpfastcache_script_files',
        'madmax_phpfastcache_exclude_minify'
    );

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }

    // Flush rewrite rules to unregister custom post types and taxonomies
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'my_cache_plugin_deactivate');

function cacheAdapter_plugin_activation() {
    // Bulk update settings to '1'
    $options_to_update = array(
        'library',
        'backend',
        'page_cache',
        'object_cache',
        'transient_cache',
        'auto_method',
        'post_cache',
        // 'page_cache', // Duplicate removed
        'output_buffer_cache',
        'cache_oembed',
        'cache_menu_fragment',
        'handle_ajax_request',
        'cache_api_request',
        'preload_menu_cache',
        'preload_object_cache_fragment',
        'preload_api_json',
        'preload_transient_cache',
        'preload_object_cache',
        'preload_page_cache',
        'madmax_phpfastcache_compression',
        'madmax_phpfastcache_minify_html',
        'madmax_phpfastcache_minify_css',
        'madmax_phpfastcache_minify_js',
        'madmax_phpfastcache_script_files',
        'madmax_phpfastcache_exclude_minify'
    );

    foreach ($options_to_update as $option) {
        update_option($option, '1');
    }

    choose_cache_system();
    // Flush rewrite rules to register custom post types and taxonomies
    flush_rewrite_rules();
}

function my_cache_plugin_activate() {
    // Register default settings as an array
    $default_options = array(
    'library' => 'phpFastCache', // Default caching library
    'backend' => '', // Default backend for caching (will be dynamically set)
    'page_cache' => false, // Whether to cache pages
    'object_cache' => false, // Whether to cache objects
    'transient_cache' => false, // Whether to cache transients
    'auto_method' => false, // Whether to automatically cache method results
    'post_cache' => false, // Whether to cache posts
    'output_buffer_cache' => false, // Whether to cache output buffer
    'cache_oembed' => false, // Whether to cache oEmbed responses
    'cache_menu_fragment' => false, // Whether to cache menu fragments
    'handle_ajax_request' => false, // Whether to handle AJAX requests for caching
    'cache_api_request' => false, // Whether to cache API requests
    'preload_menu_cache' => false, // Whether to preload menu cache
    'preload_object_cache_fragment' => false, // Whether to preload object cache fragment
    'preload_api_json' => false, // Whether to preload API JSON responses
    'preload_transient_cache' => false, // Whether to preload transient cache
    'preload_object_cache' => false, // Whether to preload object cache
    'preload_page_cache' => false, // Whether to preload page cache
    'madmax_phpfastcache_compression' => false, // Whether to enable compression
    'madmax_phpfastcache_minify_html' => false, // Whether to minify HTML
    'madmax_phpfastcache_minify_css' => false, // Whether to minify CSS
    'madmax_phpfastcache_minify_js' => false, // Whether to minify JS
    'madmax_phpfastcache_script_files' => false, // Whether to cache script files
    'madmax_phpfastcache_exclude_minify' => false // Whether to exclude files from minification
	);

    // Register default options
    foreach ($default_options as $option => $value) {
        add_option($option, $value);
    }

    // Choose cache system
    choose_cache_system();

    // Setup cache instance based on the chosen library and backend
    global $library, $backend;
    $cacheInstance = madmax_phpfastcache_setup($library, $backend);

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'my_cache_plugin_activate');
