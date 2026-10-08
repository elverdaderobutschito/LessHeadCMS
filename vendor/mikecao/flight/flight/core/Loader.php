<?php

declare(strict_types=1);

namespace flight\core;

use Throwable;

/**
 * Responsible for instantiating classes. It maintains a list of reusable
 * instances and can generate new instances with custom constructor arguments.
 * It also performs automatic class loading.
 *
 * @copyright 2011 Mike Cao https://mikecao.com
 * @license https://docs.flightphp.com/license MIT
 */
class Loader
{
    /**
     * Registered classes.
     *
     * @var array<string, array{
     *   class-string<object>|callable(mixed ...$constructorArguments): object,
     *   mixed[],
     *   ?callable(object $instance): void,
     * }>
     */
    protected array $classes = [];

    /** If this is disabled, classes can load with underscores */
    protected static bool $v2ClassLoading = true;

    /** @var array<string, object> Class instances */
    protected array $instances = [];

    /** @var string[] Autoload directories */
    protected static array $dirs = [];

    /**
     * Registers a class.
     *
     * @template T of object
     * @param string $name Class alias.
     * @param class-string<T>|callable(mixed ...$constructorArguments): T $class Class factory.
     * @param mixed[] $params Class constructor arguments.
     * @param ?callable(T $instance): void $callback After instantiation callable.
     */
    public function register(string $name, $class, array $params = [], ?callable $callback = null): void
    {
        unset($this->instances[$name]);
        $this->classes[$name] = [$class, $params, $callback];
    }

    /**
     * Unregisters a class.
     *
     * @param string $name Class alias.
     */
    public function unregister(string $name): void
    {
        unset($this->classes[$name]);
    }

    /**
     * Gets an instance of a registered class.
     *
     * @param string $name Class alias.
     * @param bool $shared Whether to return a shared instance or create a new one.
     * @return ?object
     * @throws Throwable
     */
    public function load(string $name, bool $shared = true): ?object
    {
        $instance = null;

        if (!isset($this->classes[$name])) {
            return null;
        }

        [$factory, $constructorArguments, $onAfterInstantiating] = $this->classes[$name];
        $exists = isset($this->instances[$name]);

        if ($shared) {
            $instance = $exists
                ? $this->getInstance($name)
                : $this->newInstance($factory, $constructorArguments);

            $this->instances[$name] ??= $instance;
        } else {
            $instance = $this->newInstance($factory, $constructorArguments);
        }

        if ($onAfterInstantiating && (!$shared || !$exists)) {
            $onAfterInstantiating($instance);
        }

        return $instance;
    }

    /**
     * Gets a single instance.
     *
     * @param string $name Class alias.
     * @return ?object
     */
    public function getInstance(string $name): ?object
    {
        return $this->instances[$name] ?? null;
    }

    /**
     * Gets a new instance of a class.
     *
     * @template T of object = object
     * @param class-string<T>|callable(mixed ...$constructorArguments): T $class Class factory.
     * @param mixed[] $params Class constructor arguments.
     * @return T
     * @throws Throwable
     */
    public function newInstance($class, array $params = []): object
    {
        if (is_callable($class)) {
            return $class(...$params);
        }

        return new $class(...$params);
    }

    /**
     * Gets a registered class factory, constructor arguments and after instantiation callable.
     *
     * @param string $name Class alias.
     * @return ?array{
     *   class-string<object>|callable(mixed ...$constructorArguments): object,
     *   mixed[],
     *   ?callable(object $instance): void,
     * }
     */
    public function get(string $name)
    {
        return $this->classes[$name] ?? null;
    }

    /** Resets the Loader by clearing registered classes and instances */
    public function reset(): void
    {
        $this->classes = [];
        $this->instances = [];
    }

    // Autoloading Functions

    /**
     * Starts/stops autoloader.
     *
     * @param bool $enabled Enable/disable autoloading.
     * @param string|string[] $dirs Autoload directories.
     */
    public static function autoload(bool $enabled = true, $dirs = []): void
    {
        if ($enabled) {
            spl_autoload_register([__CLASS__, 'loadClass']);
        } else {
            spl_autoload_unregister([__CLASS__, 'loadClass']); // @codeCoverageIgnore
        }

        if (!empty($dirs)) {
            self::addDirectory($dirs);
        }
    }

    /**
     * Autoloads classes.
     *
     * Classes are not allowed to have underscores in their names.
     *
     * @param string $class Class name
     */
    public static function loadClass(string $class): void
    {
        $replace_chars = self::$v2ClassLoading === true ? ['\\', '_'] : ['\\'];
        $classFile = str_replace($replace_chars, '/', $class) . '.php';

        foreach (self::$dirs as $dir) {
            $filePath = "$dir/$classFile";

            if (file_exists($filePath)) {
                require_once $filePath;
                return;
            }
        }
    }

    /**
     * Adds a directory for autoloading classes.
     *
     * @param string|string[] $dir Directory path.
     */
    public static function addDirectory($dir): void
    {
        if (is_iterable($dir)) {
            foreach ($dir as $value) {
                self::addDirectory($value);
            }

            return;
        }

        if (!is_string($dir)) {
            return;
        }

        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);

        if (in_array($dir, self::$dirs)) {
            return;
        }

        self::$dirs[] = $dir;
    }


    /**
     * Sets v2 class loading mode.
     *
     * When true (default), underscores in class names are converted to directory
     * separators (e.g. "Foo_Bar" loads "Foo/Bar.php"). Set to false to disable
     * this conversion and treat underscores as literal characters in the filename.
     *
     * @param bool $value True to convert underscores to directory separators (v2 behaviour); false to disable.
     */
    public static function setV2ClassLoading(bool $value): void
    {
        self::$v2ClassLoading = $value;
    }
}
