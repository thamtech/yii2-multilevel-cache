Yii2 Multilevel Cache
=====================

Multilevel cache is a [Yii2](http://www.yiiframework.com) cache component to
support multi-level caching.

Configure a fast, local cache component like
[ArrayCache](https://www.yiiframework.com/doc/api/2.0/yii-caching-arraycache)
as the `level1` cache, and configure a slower cache component like
[FileCache](https://www.yiiframework.com/doc/api/2.0/yii-caching-filecache) as the
`level2` cache. The multilevel cache component will automatically check the
`level1` cache first and only check `level2` and populate `level1` with
the result when `level1` misses.


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

```
php composer.phar require --prefer-dist thamtech/yii2-multilevel-cache
```

or add

```
"thamtech/yii2-multilevel-cache": "*"
```

to the `require` section of your `composer.json` file.

Usage
-----

Application configuration example:

```php
<?php
'components' => [
    'cache' => [
        'class' => 'thamtech\caching\multilevel\BiLevelCache',
        'level1' => [
            'class' => 'yii\caching\ArrayCache',
            'serializer' => false,
        ],
        'level2' => [
            'class' => 'yii\caching\FileCache',
        ],
    ],
],
```

See Also
--------

* [Yii2 Refresh-Ahead Cache](https://github.com/thamtech/yii2-multilevel-cache)
