<?php

namespace Packlink\Lib;

/**
 * Class Core
 *
 * @package Packlink\Lib
 */
class Core
{
    public static function postUpdate()
    {
        $from = __DIR__ . '/../vendor/packlink/integration-core/src/BusinessLogic/Resources';
        $to = __DIR__ . '/../views';

        self::copyDirectory($from . '/img/carriers', $to . '/img/carriers');
        self::copyDirectory($from . '/js', $to . '/js/core');
        self::copyDirectory($from . '/LocationPicker/js', $to . '/js/location');
        self::copyDirectory($from . '/LocationPicker/css', $to . '/css');
    }

    /**
     * @param string $src
     * @param string $dst
     */
    private static function copyDirectory($src, $dst)
    {
        $dir = opendir($src);
        self::mkdir($dst);

        while (false !== ($file = readdir($dir))) {
            if (($file !== '.') && ($file !== '..')) {
                if (is_dir($src . '/' . $file)) {
                    self::mkdir($dst . '/' . $file);

                    self::copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    /**
     * @param $dst
     *
     */
    private static function mkdir($dst)
    {
        if (!file_exists($dst) && !mkdir($dst) && !is_dir($dst)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dst));
        }
    }
}
