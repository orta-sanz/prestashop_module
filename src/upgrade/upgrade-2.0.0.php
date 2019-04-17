<?php
/**
 * 2019 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2019 Packlink Shipping S.L
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueService;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\Tasks\UpgradeShopOrderDetailsTask;
use Packlink\PrestaShop\Classes\Utility\PacklinkInstaller;
use Packlink\PrestaShop\Classes\Utility\TranslationUtility;

/**
 * Upgrades module to version 2.0.0.
 *
 * @param \Packlink $module
 *
 * @return bool
 * @throws \PrestaShopException
 * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
 */
function upgrade_module_2_0_0($module)
{
    $previousShopContext = Shop::getContext();
    Shop::setContext(Shop::CONTEXT_ALL);

    Bootstrap::init();
    $installer = new PacklinkInstaller($module);

    if (!$installer->initializePlugin()) {
        return false;
    }

    Logger::logDebug(TranslationUtility::__('Upgrade to plugin v2.0.0 has started.'), 'Integration');

    $installer->removeControllers();
    removeHooks($module);

    removeObsoleteFiles($module);

    if (!$installer->addControllersAndHooks()) {
        return false;
    }

    $apiKey = \Configuration::get('PL_API_KEY');

    if (!empty($apiKey)) {
        Logger::logDebug(TranslationUtility::__('Old api key detected.'), 'Integration');

        /** @var UserAccountService $userAccountService */
        $userAccountService = ServiceRegister::getService(UserAccountService::CLASS_NAME);
        if ($userAccountService->login($apiKey)) {
            Logger::logDebug(
                TranslationUtility::__('Successfully logged in with existing api key.'),
                'Integration'
            );

            transferOrderStatusMappings();
            transferOrderReferences();
        }
    }

    removePreviousData();

    $module->enable();
    Shop::setContext($previousShopContext);

    \Configuration::loadConfiguration();

    return true;
}

/**
 * Removes old hooks.
 *
 * @param \Module $module
 */
function removeHooks($module)
{
    $registeredHooks = array(
        'actionObjectOrderHistoryAddAfter',
        'actionObjectOrderUpdateAfter',
        'actionOrderStatusPostUpdate',
        'displayOrderDetail',
        'displayBackOfficeHeader',
        'displayAdminOrderContentShip',
        'displayAdminOrderTabShip',
        'displayAdminOrder',
        'displayHeader',
    );

    foreach ($registeredHooks as $hook) {
        $module->unregisterHook($hook);
    }
}

/**
 * Removes obsolete files.
 *
 * @param Module $module
 */
function removeObsoleteFiles($module)
{
    Logger::logDebug(TranslationUtility::__('Removing obsolete files.'), 'Integration');

    $installPath = $module->getLocalPath();
    removeDirectory($installPath . 'ajax');
    removeDirectory($installPath . 'api');
    removeDirectory($installPath . 'pdf');
    removeDirectory($installPath . 'libraries');
    removeDirectory($installPath . 'classes/helper');
    removeDirectory($installPath . 'views/templates/front');

    removeFile($installPath . 'classes/PLOrder.php');

    removeFile($installPath . 'controllers/admin/AdminGeneratePdfPlController.php');
    removeFile($installPath . 'controllers/admin/AdminTabPacklinkController.php');

    removeFile($installPath . 'upgrade/Upgrade-1.3.0.php');
    removeFile($installPath . 'upgrade/Upgrade-1.4.0.php');
    removeFile($installPath . 'upgrade/Upgrade-1.5.0.php');
    removeFile($installPath . 'upgrade/Upgrade-1.6.0.php');
    removeFile($installPath . 'upgrade/Upgrade-1.6.3.php');

    removeFile($installPath . 'views/css/style16.css');
    removeFile($installPath . 'views/img/add.gif');
    removeFile($installPath . 'views/img/delivery.gif');
    removeFile($installPath . 'views/img/down.png');
    removeFile($installPath . 'views/img/printer.gif');
    removeFile($installPath . 'views/img/search.gif');
    removeFile($installPath . 'views/img/up.png');
    removeFile($installPath . 'views/js/order_detail.js');
    removeFile($installPath . 'views/templates/admin/_pl_action.tpl');
    removeFile($installPath . 'views/templates/admin/_pl_action15.tpl');
    removeFile($installPath . 'views/templates/hook/back.tpl');
    removeFile($installPath . 'views/templates/hook/expedition.tpl');
    removeFile($installPath . 'views/templates/hook/expedition15.tpl');
    removeFile($installPath . 'views/templates/hook/order_details.tpl');

    removeFile($installPath . 'status.php');
}

/**
 * Removes directory.
 *
 * @param string $name
 */
function removeDirectory($name)
{
    $iterator = new RecursiveDirectoryIterator($name, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            removeFile($file->getPathname());
        }
    }

    @rmdir($name);
}

/**
 * Removes file if it exists.
 *
 * @param string $path File path
 */
function removeFile($path)
{
    if (file_exists($path)) {
        @unlink($path);
    }
}

/**
 * Transfers existing order mappings to new module.
 */
function transferOrderStatusMappings()
{
    $mappings = getOrderStatusMappingsMap();

    $orderStatusMappings = array();

    foreach ($mappings as $oldMapping => $newMapping) {
        $value = Configuration::get($oldMapping);
        if (!empty($value)) {
            $orderStatusMappings[$newMapping] = $value;
        }
    }

    getConfigService()->setOrderStatusMappings($orderStatusMappings);
}

/**
 * Transfers old order references to new plugin.
 */
function transferOrderReferences()
{
    $packlinkOrders = getPacklinkOrders();
    if (!empty($packlinkOrders)) {
        $config = getConfigService();

        /** @var QueueService $queue */
        $queue = ServiceRegister::getService(QueueService::CLASS_NAME);
        try {
            $queue->enqueue($config->getDefaultQueueName(), new UpgradeShopOrderDetailsTask($packlinkOrders));
        } catch (\Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException $e) {
            Logger::logError(
                TranslationUtility::__(
                    'Cannot enqueue UpgradeShopDetailsTask because: %s',
                    array($e->getMessage())
                ),
                'Integration'
            );
        }
    }
}

/**
 * Removes previously used databases.
 */
function removePreviousData()
{
    Logger::logDebug(TranslationUtility::__('Deleting old plugin data.'));

    $db = Db::getInstance();

    $sql = 'DROP TABLE IF EXISTS ' . bqSQL(_DB_PREFIX_ . 'packlink_orders');
    $db->execute($sql);

    $sql_wait = 'DROP TABLE IF EXISTS ' . bqSQL(_DB_PREFIX_ . 'packlink_wait_draft');
    $db->execute($sql_wait);

    $configs = getConfigurationKeys();
    foreach ($configs as $config) {
        Configuration::deleteByName($config);
    }
}

/**
 * Returns map of old statuses mapped to new statuses.
 *
 * @return array
 */
function getOrderStatusMappingsMap()
{
    return array(
        'PL_ST_AWAITING' => \Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus::STATUS_PENDING,
        'PL_ST_PENDING' => \Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus::STATUS_ACCEPTED,
        'PL_ST_READY' => \Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus::STATUS_READY,
        'PL_ST_TRANSIT' => \Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus::STATUS_IN_TRANSIT,
        'PL_ST_DELIVERED' => \Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus::STATUS_DELIVERED,
    );
}

/**
 * Returns list of old configuration keys used by Packlink module before v2.0.0
 *
 * @return array
 */
function getConfigurationKeys()
{
    return array(
        'PL_IMPORT',
        'PL_CREATE_DRAFT_AUTO',
        'PL_API_KEY',
        'PL_API_KG',
        'PL_API_CM',
        'PL_API_VERSION',
        'PL_ST_AWAITING',
        'PL_ST_PENDING',
        'PL_ST_READY',
        'PL_ST_TRANSIT',
        'PL_ST_DELIVERED',
    );
}

/**
 * Returns array of reference ids.
 *
 * @return array
 */
function getPacklinkOrders()
{
    $db = Db::getInstance();
    $query = new DbQuery();
    $query->select('p.id_order')
        ->select('p.draft_reference')
        ->select('o.date_add')
        ->from('packlink_orders', 'p')
        ->innerJoin('orders', 'o', 'p.id_order = o.id_order');

    try {
        $result = $db->executeS($query);
    } catch (PrestaShopDatabaseException $e) {
        $result = array();
        Logger::logError(
            TranslationUtility::__('Cannot retrieve order references because: %s', array($e->getMessage())),
            'Integration'
        );
    }

    return empty($result) ? array() : $result;
}

/**
 * Retrieves configuration from service register.
 *
 * @return \Packlink\BusinessLogic\Configuration
 */
function getConfigService()
{
    /** @noinspection PhpIncompatibleReturnTypeInspection */
    return ServiceRegister::getService(\Logeecom\Infrastructure\Configuration\Configuration::CLASS_NAME);
}
