<?php

namespace DbPortable\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The model classes of the application's app/Models directory.
 */
final class ModelDiscovery
{
    /**
     * @return list<class-string<Model>>
     */
    public static function appModels(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        try {
            $namespace = app()->getNamespace().'Models\\';
        } catch (RuntimeException) {
            return [];   // no PSR-4 namespace for app/ in composer.json
        }

        $models = [];

        /** @var SplFileInfo $file */
        foreach ((new Filesystem)->allFiles($directory) as $file) {
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }
}
