<?php
/**
 * Piwik - free/libre analytics platform.
 *
 * @see    http://piwik.org
 *
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\DisableTracking;

use Piwik\API\Request;
use Piwik\Cache;
use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugin;

/**
 * Disable Tracking plugin.
 */
class DisableTracking extends Plugin
{
    /**
     * Database table name.
     */
    const TABLE_DISABLE_TRACKING_MAP = 'disable_site_tracking';

    /**
     * Get the list of websites with their current archiving status.
     *
     * @throws \Exception if an error occurred
     *
     * @return array the information for each tracked site if it is disabled or not
     */
    public static function getSitesStates()
    {
        $sites = Request::processRequest('SitesManager.getAllSites');

        foreach ($sites as $site) {
            $ret[] = [
                'id' => $site['idsite'],
                'label' => $site['name'],
                'url' => $site['main_url'],
                'disabled' => self::isSiteTrackingDisabled($site['idsite']),
            ];
        }

        return isset($ret) ? $ret : [];
    }

    /**
     * Register the events to listen on in this plugin.
     *
     * @return array the array of events and related listener
     */
    public function registerEvents()
    {
        return [
            'Tracker.isExcludedVisit' => 'isExcludedVisit',
        ];
    }

    /**
     * Event-Handler for a new tracking request.
     *
     * @throws \Exception if an error occurred
     */
    public function isExcludedVisit(&$excluded, $request)
    {
        $siteId = $request->getIdSite();

        if (self::isSiteTrackingDisabled($siteId)) {
            $excluded = true;
        }
    }

    /**
     * Check if site tracking is disabled.
     *
     * @param int $siteId the site id to check
     *
     * @throws \Exception if an error occurred
     *
     * @return bool 'true' if tracking is disabled, 'false' otherwise
     */
    public static function isSiteTrackingDisabled($siteId)
    {
        $cache = Cache::getEagerCache();

        if ($cache->contains('DisableTracking_' . $siteId)) {
            return $cache->fetch('DisableTracking_' . $siteId);
        } else {
            $sql = '
                SELECT
                  count(*) AS `disabled`
                FROM ' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP) . '
                WHERE
                    siteId = :siteId AND
                    deleted_at IS NULL;
            ';

            $state = Db::fetchAll($sql, [':siteId' => $siteId]);
            $isSiteTrackingDisabled = (bool) $state[0]['disabled'];
            $cache->save('DisableTracking_' . $siteId, $isSiteTrackingDisabled);

            return $isSiteTrackingDisabled;
        }
    }

    /**
     * Generate table to store disable states while install plugin.
     *
     * @throws \Exception if an error occurred
     */
    public function install()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP) . ' (
                        id INT NOT NULL AUTO_INCREMENT,
                        siteId INT NOT NULL,
                        created_at DATETIME NOT NULL,
                        deleted_at DATETIME,
                        PRIMARY KEY (id)
                    )  DEFAULT CHARSET=utf8';
        Db::exec($sql);
    }

    /**
     * Remove plugins table, while uninstall the plugin.
     */
    public function uninstall()
    {
        Db::dropTables(Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP));
    }

    /**
     * Save new input.
     *
     * @throws \Exception if an error occurred
     */
    public static function save()
    {
        foreach ($_POST as $key => $state) {
            $key = Common::sanitizeInputValue($key);
            if (false !== strpos($key, '-')) {
                $id = explode('-', $key);
                $id = $id[1];
                if ('on' === Common::sanitizeInputValue($state)) {
                    self::disableSiteTracking($id);
                    $disabled[] = $id;
                }
            }
        }

        self::enableAllSiteTrackingExcept(isset($disabled) ? $disabled : []);
    }

    /**
     * Change disabled status for the websites.
     *
     * @param array $idSites the list of websites
     * @param string $disabled 'on' to archive, 'off' to re-enable
     *
     * @throws \Exception if an error occurred
     */
    public static function changeDisableState($idSites, $disabled)
    {
        Piwik::checkUserHasAdminAccess($idSites);

        if (!self::sitesExist($idSites)) {
            throw new \Exception('Check given site ids');
        }

        foreach ($idSites as $key => $idSite) {
            if ('on' === $disabled) {
                if (!self::isSiteTrackingDisabled($idSite)) {
                    self::disableSiteTracking($idSite);
                }
            } else {
                $sql = 'UPDATE `' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP) . '`
                        SET
                            `deleted_at`= NOW()
                        WHERE 
                            `deleted_at` IS NULL
                            AND
                            `siteId` = :idSite';
                Db::query($sql, [':idSite' => $idSite]);
            }

            $cache = Cache::getEagerCache();
            $cache->delete('DisableTracking_' . $idSite);
        }
    }

    /**
     * Disables tracking for the given site.
     *
     * @param int $id the site do enable tracking for
     *
     * @throws \Exception if an error occurred
     */
    private static function disableSiteTracking($id)
    {
        if (empty(Request::processRequest('SitesManager.getSiteFromId', ['idSite' => $id]))) {
            throw new \Exception('Invalid site ID');
        }

        if (!self::isSiteTrackingDisabled($id)) {
            $sql = '
                    INSERT INTO `' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP) . '`
                        (siteId, created_at)
                    VALUES
                        (:siteId, NOW())
                ';
            Db::query($sql, [':siteId' => $id]);
        }
    }

    /**
     * Enables tracking for all sites except the given siteIds.
     *
     * @param array $siteIds the sites to exclude from process
     *
     * @throws \Exception if an error occurred
     */
    private static function enableAllSiteTrackingExcept($siteIds)
    {
        $sql = '
                UPDATE
                    `' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP) . '`
                SET
                    `deleted_at`= NOW()
                WHERE 
                    `deleted_at` IS NULL
            ';
        if (0 !== count($siteIds)) {
            $sql .= ' AND `siteId` NOT IN (?)';
        }
        Db::query(
            $sql,
            implode(',', $siteIds)
        );

        $sql = '
                SELECT
                    siteId
                FROM ' . Common::prefixTable(self::TABLE_DISABLE_TRACKING_MAP);
        $idSites = Db::fetchAll($sql);

        foreach ($idSites as $idSite) {
            $cache = Cache::getEagerCache();
            $cache->delete('DisableTracking_' . $idSite['siteId']);
        }
    }

    /**
     * Checks the given site IDs exists.
     *
     * @param array $ids the website ids list
     *
     * @return bool true if all the IDs exists, false otherwise
     */
    private static function sitesExist($ids)
    {
        $sites = Request::processRequest('SitesManager.getSitesIdWithAdminAccess');
        if (empty($sites)) {
            return false;
        }

        return empty(array_diff($ids, $sites));
    }
}
