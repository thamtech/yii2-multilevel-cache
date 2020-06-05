<?php
/**
 * @copyright Copyright(c) 2020 Thamtech, LLC
 * @link https://github.com/thamtech/yii2-multilevel-cache
 * @license https://opensource.org/licenses/BSD-3-Clause
**/

namespace thamtech\caching\multilevel;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\di\Instance;

/**
 * BiLevelCache composes two cache components into a
 * [mutual inclusion](https://en.wikiversity.org/wiki/Multilevel_caching)
 * multi-level cache.
 *
 * The default policy is `WRITE_AROUND`. Writes are made to `level2` with
 * corresponding deletes in `level1` to remove stale data.
 *
 * A `WRITE_THROUGH` policy may be implemented in the future in which writes
 * are made to both `level1` and `level2`.
 *
 * @author Tyler Ham <tyler@thamtech.com>
 */
class BiLevelCache extends Component implements CacheInterface
{
    const WRITE_AROUND = 'write-around';

    /**
     * @var int duration in seconds before a cache entries duplicated to level1
     * will expire from level1.
     *
     * The default is null meaning that the level1 cache component's default
     * duration will be used. The value 0 means infinite.
     */
    public $level1AutoDuration;

    /**
     * @var string policy, one of:
     *   - [[BiLevelCache::WRITE_AROUND]]
     */
    private $_policy = self::WRITE_AROUND;

    /**
     * @var CacheInterface level 1 cache component
     */
    private $_level1;

    /**
     * @var CacheInterface level 2 cache component
     */
    private $_level2;

    /**
     * {{@inheritdoc}}
     */
    public function init()
    {
        parent::init();

        // ensure that both level1 and level2 cache components have been set
        if (empty($this->level1) || empty($this->level2)) {
            throw new InvalidConfigException('You must specify both level1 and level2 cache components.');
        }
    }

    /**
     * Builds a normalized cache key from a given key using the `level2` cache.
     *
     * @param mixed $key the key to be normalized
     * @return string the generated cache key
     */
    public function buildKey($key)
    {
        return $this->level2->buildKey($key);
    }

    /**
     * Retrieves a value from cache with a specified key.
     *
     * First, `level1` cache is checked. If `level1` has the value, it is
     * returned. Otherwise, the call is passed on to `level2` cache.
     *
     * @param mixed $key a key identifying the cached value. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     *
     * @return mixed the value stored in cache, false if the value is not in the cache, expired,
     * or the dependency associated with the cached data has changed.
     */
    public function get($key)
    {
        $value = $this->level1->get($key);
        if ($value !== false) {
            return $value;
        }

        $value = $this->level2->get($key);
        if ($value !== false) {
            // populate in level1
            $this->level1->set($key, $value, $this->level1AutoDuration); // future work: consider how to handle $dependency
        }

        return $value;
    }

    /**
     * Checks whether a specified key exists in either level1 or level2 cache.
     *
     * Note that this method does not check whether the dependency associated
     * with the cached data, if there is any, has changed. So a call to [[get]]
     * may return false while exists returns true.
     *
     * @param mixed $key a key identifying the cached value. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     *
     * @return bool true if a value exists in cache, false if the value is not in the cache or expired.
     */
    public function exists($key)
    {
        return $this->level1->exists($key) || $this->level2->exists($key);
    }

    /**
     * Retrieves multiple values from caches with the specified keys.
     *
     * @param string[] $keys list of string keys identifying the cached values
     *
     * @return array list of cached values corresponding to the specified keys. The array
     * is returned in terms of (key, value) pairs.
     * If a value is not cached or expired, the corresponding array value will be false.
     */
    public function multiGet($keys)
    {
        // For now, we just defer to the level2 cache.
        // Future work could first check level1, and then fall back to level2
        // only for keys that weren't found in level1.
        return $this->level2->multiGet($keys);
    }

    /**
     * Stores a value identified by a key into cache.
     * If the cache already contains such a key, the existing value and
     * expiration time will be replaced with the new ones, respectively.
     *
     * Under the WRITE_AROUND policy, the corresponding value will be set in
     * level2 and deleted from level1.
     *
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param mixed $value the value to be cached
     * @param int $duration default duration in seconds before the cache will expire. If not set,
     * default [[defaultDuration]] value is used.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return bool whether the value is successfully stored into cache
     */
    public function set($key, $value, $duration = null, $dependency = null)
    {
        $result = $this->level2->set($key, $value, $duration, $dependency);
        if ($result) {
            $this->level1->delete($key);
        }
        return $result;
    }

    /**
     * Stores multiple items in cache. Each item contains a value identified by a key.
     * If the cache already contains such a key, the existing value and
     * expiration time will be replaced with the new ones, respectively.
     *
     * Under the WRITE_AROUND policy, the corresponding values will be set in
     * level2 and deleted from level1.
     *
     * @param array $items the items to be cached, as key-value pairs.
     * @param int $duration default number of seconds in which the cached values will expire. 0 means never expire.
     * @param Dependency $dependency dependency of the cached items. If the dependency changes,
     * the corresponding values in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return array array of failed keys
     */
    public function multiSet($items, $duration = 0, $dependency = null)
    {
        $failedKeys = $this->level2->multiSet($items, $duration, $dependency);
        foreach (array_keys($items) as $key) {
            $this->level1->delete($key);
        }
        return $failedKeys;
    }

    /**
     * Stores a value identified by a key into cache if the cache does not contain this key.
     * Nothing will be done if the cache already contains the key.
     *
     * Under the WRITE_AROUND policy, the corresponding value will be added in
     * level2 and deleted from level1 if it was successfully added to level2.
     *
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param mixed $value the value to be cached
     * @param int $duration the number of seconds in which the cached value will expire. 0 means never expire.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return bool whether the value is successfully stored into cache
     */
    public function add($key, $value, $duration = 0, $dependency = null)
    {
        $isAdded = $this->level2->add($key, $value, $duration, $dependency);
        if ($isAdded) {
            $this->level1->delete($key);
        }
        return $isAdded;
    }

    /**
     * Stores multiple items in cache. Each item contains a value identified by a key.
     * If the cache already contains such a key, the existing value and expiration time will be preserved.
     *
     * Under the WRITE_AROUND policy, the corresponding values will be added in
     * level2 and deleted from level1 if successfully added to level2.
     *
     * @param array $items the items to be cached, as key-value pairs.
     * @param int $duration default number of seconds in which the cached values will expire. 0 means never expire.
     * @param Dependency $dependency dependency of the cached items. If the dependency changes,
     * the corresponding values in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is false.
     * @return array array of failed keys
     */
    public function multiAdd($items, $duration = 0, $dependency = null)
    {
        $failedKeys = $this->level2->multiAdd($items, $duration, $dependency);
        foreach (array_keys($items) as $key) {
            if (in_array($this->level2->buildKey($key), $failedKeys)) {
                // don't delete keys that weren't added to level2
                continue;
            }
            $this->level1->delete($key);
        }
        return $failedKeys;
    }

    /**
     * Deletes a value with the specified key from both level1 and level2 caches.
     *
     * @param mixed $key a key identifying the value to be deleted from cache. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @return bool if no error happens during deletion
     */
    public function delete($key)
    {
        $result1 = $this->level1->delete($key);
        $result2 = $this->level2->delete($key);
        return  $result1 || $result2;
    }

    /**
     * Deletes all values from both level1 and level2 caches.
     * Be careful of performing this operation if the cache is shared among multiple applications.
     * @return bool whether the flush operation was successful.
     */
    public function flush()
    {
        return $this->level1->flush() && $this->level2->flush();
    }

    /**
     * Set the level1 cache component
     *
     * @param array|string|CacheInterface|Cache|Instance $cache the cache component
     *     or reference to the desired cache component.
     *
     *     The cache may be specified as a configuration array for a
     *     [[CacheInterface]], the string name of an application component ID,
     *     an Instance object, or a [[CacheInterface]] object.
     *
     * @throws InvalidConfigException if the parameter is not a valid cache reference
     */
    public function setLevel1($cache)
    {
        $this->_level1 = Instance::ensure($cache, '\yii\caching\CacheInterface');
    }

    /**
     * Get the level1 cache component
     *
     * @return CacheInterface
     */
    public function getLevel1()
    {
        return $this->_level1;
    }

    /**
     * Set the level2 cache component
     *
     * @param array|string|CacheInterface|Cache|Instance $cache the cache component
     *     or reference to the desired cache component.
     *
     *     The cache may be specified as a configuration array for a
     *     [[CacheInterface]], the string name of an application component ID,
     *     an Instance object, or a [[CacheInterface]] object.
     *
     * @throws InvalidConfigException if the parameter is not a valid cache reference
     */
    public function setLevel2($cache)
    {
        $this->_level2 = Instance::ensure($cache, '\yii\caching\CacheInterface');
    }

    /**
     * Get the level2 cache component
     *
     * @return CacheInterface
     */
    public function getLevel2()
    {
        return $this->_level2;
    }

    /**
     * Set the policy.
     *
     * @param string $policy one of the following:
     *   - [[BiLevelCache::WRITE_AROUND]]
     */
    public function setPolicy($policy)
    {
        $validPolicies = [
            self::WRITE_AROUND,
        ];

        if (!in_array($policy, $validPolicies)) {
            throw new InvalidConfigException(sprintf('Unrecognized policy: %s', $policy));
        }

        $this->_policy = $policy;
    }

    /**
     * Get the policy.
     *
     * @return string
     */
    public function getPolicy()
    {
        return $this->_policy;
    }

    /**
     * Returns whether there is a cache entry with a specified key.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key a key identifying the cached value
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->get($key) !== false;
    }

    /**
     * Retrieves the value from cache with a specified key.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key a key identifying the cached value
     * @return mixed the value stored in cache, false if the value is not in the cache or expired.
     */
    public function offsetGet($key)
    {
        return $this->get($key);
    }

    /**
     * Stores the value identified by a key into cache.
     * If the cache already contains such a key, the existing value will be
     * replaced with the new ones. To add expiration and dependencies, use the [[set()]] method.
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key the key identifying the value to be cached
     * @param mixed $value the value to be cached
     */
    public function offsetSet($key, $value)
    {
        $this->set($key, $value);
    }

    /**
     * Deletes the value with the specified key from cache
     * This method is required by the interface [[\ArrayAccess]].
     * @param string $key the key of the value to be deleted
     */
    public function offsetUnset($key)
    {
        $this->delete($key);
    }

    /**
     * Method combines both [[set()]] and [[get()]] methods to retrieve value identified by a $key,
     * or to store the result of $callable execution if there is no cache available for the $key.
     *
     * Usage example:
     *
     * ```php
     * public function getTopProducts($count = 10) {
     *     $cache = $this->cache; // Could be Yii::$app->cache
     *     return $cache->getOrSet(['top-n-products', 'n' => $count], function () use ($count) {
     *         return Products::find()->mostPopular()->limit($count)->all();
     *     }, 1000);
     * }
     * ```
     *
     * @param mixed $key a key identifying the value to be cached. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @param callable|\Closure $callable the callable or closure that will be used to generate a value to be cached.
     * If you use $callable that can return `false`, then keep in mind that [[getOrSet()]] may work inefficiently
     * because the [[yii\caching\Cache::get()]] method uses `false` return value to indicate the data item is not found
     * in the cache. Thus, caching of `false` value will lead to unnecessary internal calls.
     * @param int $duration default duration in seconds before the cache will expire. If not set,
     * [[defaultDuration]] value will be used.
     * @param Dependency $dependency dependency of the cached item. If the dependency changes,
     * the corresponding value in the cache will be invalidated when it is fetched via [[get()]].
     * This parameter is ignored if [[serializer]] is `false`.
     * @return mixed result of $callable execution
     */
    public function getOrSet($key, $callable, $duration = null, $dependency = null)
    {
        if (($value = $this->get($key)) !== false) {
            return $value;
        }

        $value = call_user_func($callable, $this);
        if (!$this->set($key, $value, $duration, $dependency)) {
            Yii::warning('Failed to set cache value for key ' . json_encode($key), __METHOD__);
        }

        return $value;
    }
}
