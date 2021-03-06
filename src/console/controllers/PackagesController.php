<?php

namespace craftnet\console\controllers;

use craftnet\composer\Package;
use craftnet\Module;
use yii\base\Exception;
use yii\console\Controller;
use yii\helpers\Console;
use yii\helpers\Inflector;

/**
 * Manages Composer packages.
 *
 * @property Module $module
 */
class PackagesController extends Controller
{
    /**
     * @var bool Whether to update package releases even if their SHA hasn't changed
     */
    public $force = false;

    /**
     * @var bool Whether this is a managed package or just a dependency of a managed package
     */
    public $managed = false;

    /**
     * @var string The package's VCS repository URL
     */
    public $repository;

    /**
     * @var string The Composer package type
     */
    public $type = 'library';

    /**
     * @var bool Whether the action should be added to the queue
     */
    public $queue = false;

    /**
     * @var bool Whether the Composer repository JSON files should be regenerated after the action is complete
     */
    public $dumpJson = false;

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        // Convert kebab-case names to camelCase
        if (strpos($name, '-') !== false) {
            $name = lcfirst(Inflector::id2camel($name));
            return $this->$name;
        }
        return parent::__get($name);
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        // Convert kebab-case names to camelCase
        if (strpos($name, '-') !== false) {
            $name = lcfirst(Inflector::id2camel($name));
            $this->$name = $value;
            return;
        }
        parent::__set($name, $value);
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        $options = parent::options($actionID);
        $options[] = 'dump-json';

        switch ($actionID) {
            case 'add':
                $options[] = 'managed';
                $options[] = 'repository';
                $options[] = 'type';
                break;
            case 'update':
            case 'update-deps':
            case 'update-managed-packages':
                $options[] = 'force';
                $options[] = 'queue';
                break;
            case 'create-webhook':
            case 'create-all-webhooks':
                $options[] = 'force';
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        $aliases = parent::optionAliases();
        $aliases['f'] = 'force';
        $aliases['m'] = 'managed';
        $aliases['r'] = 'repository';
        $aliases['t'] = 'type';
        $aliases['q'] = 'queue';
        return $aliases;
    }

    /**
     * @inheritdoc
     */
    public function afterAction($action, $result)
    {
        if ($this->dumpJson) {
            echo 'dump json!'.($this->queue ? 'yes' : 'no')."\n";
            $this->module->getJsonDumper()->dump($this->queue);
        }

        return parent::afterAction($action, $result);
    }

    /**
     * Adds a new Composer package.
     *
     * @param string $name The package name
     */
    public function actionAdd(string $name)
    {
        $packageManager = $this->module->getPackageManager();
        $package = new Package([
            'name' => $name,
            'type' => $this->type,
            'repository' => $this->repository,
            'managed' => $this->managed,
        ]);
        $packageManager->savePackage($package);
        Console::output("Done adding {$name}");
        if ($this->confirm('Update its versions now?')) {
            $packageManager->updatePackage($name);
        }
        if ($this->confirm('Dump new Composer JSON?')) {
            $this->module->getJsonDumper()->dump();
        }
    }

    /**
     * Removes a Composer package.
     *
     * @param string $name The package name
     */
    public function actionRemove(string $name)
    {
        $packageManager = $this->module->getPackageManager();
        try {
            $package = $packageManager->getPackage($name);
        } catch (Exception $e) {
            Console::output("{$name} doesn't exist.");
            return;
        }
        if ($this->confirm("Are you sure you want to remove {$name}?")) {
            $packageManager->removePackage($package);
            Console::output("{$name} removed.");
        }
    }

    /**
     * Updates our version records for a Composer package.
     *
     * @param string $name The package name
     */
    public function actionUpdate(string $name)
    {
        $this->module->getPackageManager()->updatePackage($name, $this->force, $this->queue);
    }

    /**
     * Updates our version records for all non-managed Composer packages.
     */
    public function actionUpdateDeps()
    {
        $this->module->getPackageManager()->updateDeps($this->force, $this->queue, $errors);

        if (!empty($errors)) {
            $this->stderr('Done, but encountered the following errors:'.PHP_EOL, Console::FG_RED);
            foreach ($errors as $packageName => $packageErrors) {
                $this->stderr("* {$packageName}:".PHP_EOL, Console::FG_RED);
                foreach ($packageErrors as $error) {
                    $this->stderr("  - {$error}".PHP_EOL, Console::FG_RED);
                }
            }
        } else {
            $this->stdout('Done'.PHP_EOL, Console::FG_GREEN);
        }
    }

    /**
     * Updates our version records for all managed Composer packages.
     */
    public function actionUpdateManagedPackages()
    {
        $this->module->getPackageManager()->updateManagedPackages($this->force, $this->queue);
    }

    /**
     * Creates a VCS webhook for a given package.
     *
     * @param string $name The package name
     */
    public function actionCreateWebhook(string $name)
    {
        $this->module->getPackageManager()->createWebhook($name, $this->force);
    }

    /**
     * Deletes a VCS webhook for a given package.
     *
     * @param string $name The package name
     */
    public function actionDeleteWebhook(string $name)
    {
        $this->module->getPackageManager()->deleteWebhook($name);
    }

    /**
     * Creates new webhooks for all managed packages.
     *
     * @param string $name The package name
     */
    public function actionCreateAllWebhooks()
    {
        $packageManager = $this->module->getPackageManager();
        $names = $packageManager->getPackageNames();

        foreach ($names as $name) {
            $package = $packageManager->getPackage($name);
            if ($package->managed) {
                $packageManager->createWebhook($package, $this->force);
            }
        }
    }

    /**
     * Deletes webhooks for all managed packages.
     *
     * @param string $name The package name
     */
    public function actionDeleteAllWebhooks()
    {
        $packageManager = $this->module->getPackageManager();
        $names = $packageManager->getPackageNames();

        foreach ($names as $name) {
            $package = $packageManager->getPackage($name);
            if ($package->managed) {
                $packageManager->deleteWebhook($package);
            }
        }
    }
}
