<?php
namespace Omeka\Service;

use DirectoryIterator;
use Omeka\Module\Manager as ModuleManager;
use SplFileInfo;
use Zend\Config\Reader\Ini as IniReader;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Factory for creating Omeka's module manager
 */
class ModuleManagerFactory implements FactoryInterface
{
    /**
     * Create the module manager
     * 
     * @param ServiceLocatorInterface $serviceLocator
     * @return array
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $manager = new ModuleManager;
        $iniReader = new IniReader;
        $connection = $serviceLocator->get('Omeka\Connection');

        // Get all modules from the filesystem.
        foreach (new DirectoryIterator(OMEKA_PATH . '/modules') as $dir) {

            // Module must be a directory
            if (!$dir->isDir() || $dir->isDot()) {
                continue;
            }

            $module = $manager->registerModule($dir->getBasename());

            // Module directory must contain config/module.ini
            $iniFile = new SplFileInfo($dir->getPathname() . '/config/module.ini');
            if (!$iniFile->isReadable() || !$iniFile->isFile()) {
                $module->setState(ModuleManager::STATE_INVALID_INI);
                continue;
            }

            $module->setIni($iniReader->fromFile($iniFile->getRealPath()));

            // Module INI must be valid
            if (!$manager->iniIsValid($module)) {
                $module->setState(ModuleManager::STATE_INVALID_INI);
                continue;
            }

            // Module directory must contain Module.php
            $moduleFile = new SplFileInfo($dir->getPathname() . '/Module.php');
            if (!$moduleFile->isReadable() || !$moduleFile->isFile()) {
                $module->setState(ModuleManager::STATE_INVALID_MODULE);
                continue;
            }

            // Module class must extend Omeka\Module\AbstractModule
            require_once $moduleFile->getRealPath();
            $moduleClass = $dir->getBasename() . '\Module';
            if (!class_exists($moduleClass)
                || !is_subclass_of($moduleClass, 'Omeka\Module\AbstractModule')
            ) {
                $module->setState(ModuleManager::STATE_INVALID_MODULE);
                continue;
            }
        }

        // Get all modules from the database, if installed.
        $dbModules = array();
        if ($serviceLocator->get('Omeka\Status')->isInstalled()) {
            $statement = $connection->prepare("SELECT * FROM module");
            $statement->execute();
            $dbModules = $statement->fetchAll();
        }

        foreach ($dbModules as $moduleRow) {

            if (!$manager->isRegistered($moduleRow['id'])) {
                // Module installed but not in filesystem
                $module = $manager->registerModule($moduleRow['id']);
                $module->setDb($moduleRow);
                $module->setState(ModuleManager::STATE_NOT_FOUND);
                continue;
            }

            $module = $manager->getModule($moduleRow['id']);
            $module->setDb($moduleRow);

            if ($module->getState()) {
                // Module already has state.
                continue;
            }

            $moduleIni = $module->getIni();
            if (version_compare($moduleIni['version'], $moduleRow['version'], '>')) {
                // Module in filesystem is newer version than the installed one.
                $module->setState(ModuleManager::STATE_NEEDS_UPGRADE);
                continue;
            }

            if ($moduleRow['is_active']) {
                // Module valid, installed, and active
                $module->setState(ModuleManager::STATE_ACTIVE);
            } else {
                // Module valid, installed, and not active
                $module->setState(ModuleManager::STATE_NOT_ACTIVE);
            }
        }

        foreach ($manager->getModules() as $id => $module) {
            if (!$module->getState()) {
                // Module in filesystem but not installed
                $module->setState(ModuleManager::STATE_NOT_INSTALLED);
            }
        }

        return $manager;
    }
}
