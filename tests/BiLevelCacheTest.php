<?php

namespace thamtechunit\caching\multilevel;

use thamtech\caching\multilevel\BiLevelCache;
use Yii;

class BiLevelCacheTest extends \thamtechunit\caching\multilevel\TestCase
{
    private $_cacheInstance = null;

    protected function setUp()
    {
        parent::setUp();
        $this->getCacheInstance()->flush();
    }

    protected function getCacheInstance()
    {
        if ($this->_cacheInstance === null) {
            $this->_cacheInstance = Yii::createObject([
                'class' => BiLevelCache::class,
                'policy' => BiLevelCache::WRITE_AROUND,
                'level1' => [
                    'class' => 'yii\caching\ArrayCache',
                    'keyPrefix' => 'level1',
                ],
                'level2' => [
                    'class' => 'yii\caching\FileCache',
                    'keyPrefix' => 'level2',
                ],
            ]);
        }

        return $this->_cacheInstance;
    }

    /**
     * @expectedException yii\base\InvalidConfigException
     */
    public function testBadPolicy()
    {
        Yii::createObject([
            'class' => BiLevelCache::class,
            'policy' => 'unrecognized', // not a valid policy
            'level1' => [
                'class' => 'yii\caching\ArrayCache',
                'keyPrefix' => 'level1',
            ],
            'level2' => [
                'class' => 'yii\caching\ArrayCache',
                'keyPrefix' => 'level2',
            ],
        ]);
    }

    /**
     * @expectedException yii\base\InvalidConfigException
     */
    public function testMissingLevel1()
    {
        Yii::createObject([
            'class' => BiLevelCache::class,
            'level2' => [
                'class' => 'yii\caching\ArrayCache',
                'keyPrefix' => 'level2',
            ],
        ]);
    }

    /**
     * @expectedException yii\base\InvalidConfigException
     */
    public function testMissingLevel2()
    {
        Yii::createObject([
            'class' => BiLevelCache::class,
            'level1' => [
                'class' => 'yii\caching\ArrayCache',
                'keyPrefix' => 'level1',
            ],
        ]);
    }

    public function testBuildKey()
    {
        $cache = $this->getCacheInstance();

        $key1 = $cache->level1->buildKey(['abckey']);
        $key2 = $cache->level2->buildKey(['abckey']);
        $key = $cache->buildKey(['abckey']);

        $this->assertEquals($key, $key2);
        $this->assertNotEquals($key, $key1);
    }

    public function testGetSet()
    {
        $cache = $this->getCacheInstance();

        $this->assertFalse($cache->exists('abc'));
        $cache->level1->set('abc', 'def');
        $this->assertTrue($cache->exists('abc'));
        $cache->set('abc', '123');
        $this->assertTrue($cache->exists('abc'));

        $this->assertFalse($cache->level1->exists('abc'));
        $this->assertTrue($cache->level2->exists('abc'));

        // this should pull 'abc' key into level1
        $this->assertEquals('123', $cache->get('abc'));
        $this->assertEquals('123', $cache->level1->get('abc')); // should exist in level1 now
        $this->assertEquals('123', $cache->get('abc'));
    }

    public function testGetAdd()
    {
        $cache = $this->getCacheInstance();

        $this->assertFalse($cache->exists('abc'));
        $cache->level1->add('abc', 'def');
        $this->assertTrue($cache->exists('abc'));
        $cache->add('abc', '123');
        $this->assertTrue($cache->exists('abc'));
        $cache->add('abc', '456');

        $this->assertFalse($cache->level1->exists('abc'));
        $this->assertTrue($cache->level2->exists('abc'));

        // this should pull 'abc' key into level1
        $this->assertEquals('123', $cache->get('abc'));
        $this->assertEquals('123', $cache->level1->get('abc')); // should exist in level1 now
        $this->assertEquals('123', $cache->get('abc'));
    }

    public function testDelete()
    {
        $cache = $this->getCacheInstance();
        $cache->level1->set('abc', 'def');
        $cache->level2->set('abc', 'def');

        $this->assertTrue($cache->delete('abc'));
        $this->assertFalse($cache->exists('abc'));
    }

    public function testDeleteLevel2Only()
    {
        $cache = $this->getCacheInstance();
        $cache->level2->set('abc', 'def');

        $this->assertTrue($cache->exists('abc'));
        $this->assertTrue($cache->delete('abc'));
        $this->assertFalse($cache->exists('abc'));
    }

    public function testDeleteLevel1Only()
    {
        $cache = $this->getCacheInstance();
        $cache->level1->set('abc', 'def');

        $this->assertTrue($cache->exists('abc'));
        $this->assertTrue($cache->delete('abc'));
        $this->assertFalse($cache->exists('abc'));
    }

    public function testMultiGet()
    {
        $cache = $this->getCacheInstance();
        $cache->level1->set('abc', '123');
        $cache->level1->set('def', '456');
        $cache->level2->set('abc', '222-123');
        $cache->level2->set('def', '222-456');

        $values = $cache->multiGet(['abc', 'def']);
        $this->assertEquals(['abc' => '222-123', 'def' => '222-456'], $values);
    }

    public function testMultiSet()
    {
        $cache = $this->getCacheInstance();
        $cache->level1->set('abc', '123');
        $cache->level1->set('def', '456');
        $cache->level2->set('abc', '222-123');
        $cache->level2->set('def', '222-456');

        $values = [
            'abc' => '333-123',
            'ghi' => '333-789',
        ];
        $failedKeys = $cache->multiSet($values);
        $this->assertEmpty($failedKeys);

        $this->assertFalse($cache->level1->exists('abc')); // should have been deleted
        $this->assertTrue($cache->level1->exists('def')); // should remain uneffected
        $this->assertFalse($cache->level1->exists('ghi')); // should have been deleted

        $this->assertTrue($cache->level2->exists('abc'));
        $this->assertTrue($cache->level2->exists('def'));
        $this->assertTrue($cache->level2->exists('ghi'));

        $this->assertTrue($cache->exists('abc'));
        $this->assertTrue($cache->exists('def'));
        $this->assertTrue($cache->exists('ghi'));

        $this->assertEquals('333-123', $cache->get('abc'));
        $this->assertEquals('456', $cache->get('def'));
        $this->assertEquals('333-789', $cache->get('ghi'));

        // the above should have re-populated `abc` and added `ghi` to level1
        $this->assertTrue($cache->level1->exists('abc'));
        $this->assertTrue($cache->level1->exists('def'));
        $this->assertTrue($cache->level1->exists('ghi'));

        $this->assertEquals('333-123', $cache->level1->get('abc'));
        $this->assertEquals('456', $cache->level1->get('def'));
        $this->assertEquals('333-789', $cache->level1->get('ghi'));
    }

    public function testMultiAdd()
    {
        $cache = $this->getCacheInstance();
        $cache->level1->set('abc', '123');
        $cache->level1->set('def', '456');
        $cache->level2->set('abc', '222-123');
        $cache->level2->set('def', '222-456');

        $values = [
            'abc' => '333-123',
            'ghi' => '333-789',
        ];
        $failedKeys = $cache->multiAdd($values);
        $this->assertEquals([$cache->buildKey('abc')], $failedKeys);

        $this->assertTrue($cache->level1->exists('abc')); // should remain unaffected
        $this->assertTrue($cache->level1->exists('def')); // should remain uneffected
        $this->assertFalse($cache->level1->exists('ghi'));

        $this->assertTrue($cache->level2->exists('abc'));
        $this->assertTrue($cache->level2->exists('def'));
        $this->assertTrue($cache->level2->exists('ghi'));

        $this->assertTrue($cache->exists('abc'));
        $this->assertTrue($cache->exists('def'));
        $this->assertTrue($cache->exists('ghi'));

        $this->assertEquals('123', $cache->get('abc'));
        $this->assertEquals('456', $cache->get('def'));
        $this->assertEquals('333-789', $cache->get('ghi'));

        // the above should have added `ghi` to level1
        $this->assertTrue($cache->level1->exists('abc'));
        $this->assertTrue($cache->level1->exists('def'));
        $this->assertTrue($cache->level1->exists('ghi'));

        $this->assertEquals('123', $cache->level1->get('abc'));
        $this->assertEquals('456', $cache->level1->get('def'));
        $this->assertEquals('333-789', $cache->level1->get('ghi'));
    }

    public function testGetOrSet()
    {
        $cache = $this->getCacheInstance();

        $value = $cache->getOrSet('abc', function() {
            return '123';
        });
        $this->assertEquals('123', $value);

        $this->assertFalse($cache->level1->exists('abc'));
        $this->assertTrue($cache->level2->exists('abc'));
        $this->assertEquals('123', $cache->level2->get('abc'));

        $value = $cache->getOrSet('abc', function() {
            $this->assertTrue(false);
            return '456';
        });
        $this->assertEquals('123', $value);

        $this->assertTrue($cache->level1->exists('abc'));
        $this->assertTrue($cache->level2->exists('abc'));
        $this->assertEquals('123', $cache->level1->get('abc'));
        $this->assertEquals('123', $cache->level2->get('abc'));
    }
}
