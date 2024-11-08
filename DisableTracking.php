<?php
/**
 * Piwik - free/libre analytics platform.
 *
 * @see    http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\DisableTracking;

use Exception;
use Piwik\API\Request;
use Piwik\Cache;
use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Log;


class DisableTracking extends
    Plugin {


    const TABLEDISABLETRACKINGMAP = 'disable_site_tracking';


    /**
     * @return array The information for each tracked site if it is disabled or not.
     * @throws \Exception
     */
    public static function getSitesStates() {
        $ret = array();

        $sql = '
            SELECT
            `idsite` as `id`,
            `name`,
            `main_url`
            FROM
            `' . Common::prefixTable('site') . '`
            ORDER BY
            `name` ASC
        ';

        $rows = Db::query($sql);

        while (($row = $rows->fetch()) !== FALSE) {
            $ret[] = array(
                'id'       => $row['id'],
                'label'    => $row['name'],
                'url'      => $row['main_url'],
                'disabled' => FALSE,
            );
        }

        // Get disabled states seperately to not destroy our db query resultset.
        for ($i = 0; $i < count($ret); $i++) {
            $ret[$i]['disabled'] = self::isSiteTrackingDisabled($ret[$i]['id']);
        }

        return $ret;
    }


    /**
     * Enables tracking for all sites except the given siteIds.
     *
     * @param array $siteIds The sites to exclude from process.
     *
     * @throws \Exception
     */
    public static function setDisabledSiteTracking($siteIds = array()) {
        $allExistingIds = [];
        $cache = Cache::getEagerCache();

        // Get all site ids in our "disabled tracking map"
        $sql = '
            SELECT
            `siteId` as `id`
            FROM
            `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
        ';
        $rows = Db::query($sql);
        while (($row = $rows->fetch()) !== FALSE) {
            $allExistingIds[] = $row['id'];
        }

        // Remove ids, which shouldn't be disabled any longer
        $idsToDelete = array_diff(
            $allExistingIds,
            $siteIds
        );
        $sql = '
            DELETE FROM
                `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
            WHERE
                siteId in (?)
        ';
        Db::query(
            $sql,
            [
                join(
                    ",",
                    $idsToDelete
                ),
            ]
        );

        foreach ($idsToDelete as $siteId) {
            $cache->delete('DisableTracking_' . $siteId);
        }

        // Remove ids, which now should be disabled
        $idsToAdd = array_diff(
            $siteIds,
            $allExistingIds
        );
        $sql = '
                INSERT INTO `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
                    (siteId, created_at)
                VALUES
                    (?, NOW())
            ';
        foreach ($idsToAdd as $siteId) {
            Db::query(
                $sql,
                $siteId
            );

            $cache->delete('DisableTracking_' . $siteId);
        }
    }


    /**
     * Register the events to listen on in this plugin.
     *
     * @return array the array of events and related listener
     */
    public function registerEvents() {
        return array(
            'Tracker.initRequestSet' => 'newTrackingRequest',
        );
    }


    /**
     * Event-Handler for a new tracking request.
     */
    public function newTrackingRequest() {
        if (isset($_GET['idsite']) === TRUE) {
            $siteId = intval($_GET['idsite']);

            if ($this->isSiteTrackingDisabled($siteId) === TRUE) {
                // End tracking here, as of tracking for this page should be disabled, admin sais.
                die();
            }
        }
    }


    /**
     * Check if site tracking is disabled.
     *
     * @return bool Whether new tracking requests are ok or not.
     * @throws \Exception
     */
    public static function isSiteTrackingDisabled($siteId) {
        $cache = Cache::getEagerCache();

        if ($cache->contains('DisableTracking_' . $siteId)) {
            return $cache->fetch('DisableTracking_' . $siteId);
        } else {
            $sql = '
                SELECT
                    count(*) AS `disabled`
                FROM `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
                WHERE
                    siteId = ? AND
                    deleted_at IS NULL;
            ';

            $state = Db::fetchAll(
                $sql,
                $siteId
            );

            $isSiteTrackingDisabled = boolval($state[0]['disabled']);
            $cache->save('DisableTracking_' . $siteId, $isSiteTrackingDisabled);

            return $isSiteTrackingDisabled;
        }
    }


    /**
     * Generate table to store disable states while install plugin.
     *
     * @throws \Exception if an error occurred
     */
    public function install() {
        try {
            $sql = 'CREATE TABLE `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '` (
                    id INT NOT NULL AUTO_INCREMENT,
                    siteId INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    deleted_at DATETIME,
                    PRIMARY KEY (id)
                )  DEFAULT CHARSET=utf8';
            Db::exec($sql);
        } catch (Exception $e) {
            // ignore error if table already exists (1050 code is for 'table already exists')
            if (Db::get()
                    ->isErrNo(
                        $e,
                        '1050'
                    ) === FALSE) {
                throw $e;
            }
        }
    }


    /**
     * Remove plugins table, while uninstall the plugin.
     */
    public function uninstall() {
        Db::dropTables(Common::prefixTable(self::TABLEDISABLETRACKINGMAP));
    }


    /**
     * Save new input.
     */
    public static function save() {
        $disabled = array();

        foreach ($_POST as $key => $state) {
            if (strpos(
                    $key,
                    '-'
                ) !== FALSE) {
                $id = preg_split(
                    "/-/",
                    $key
                );
                $id = $id[1];

                if ($state === 'on') {
                    $disabled[] = $id;
                }
            }
        }

        self::setDisabledSiteTracking($disabled);
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

        foreach ($idSites as $idSite) {
            if ('on' === $disabled) {
                self::disableSiteTracking($idSite);
            } else {
                self::enableSiteTracking($idSite);
            }

            $cache = Cache::getEagerCache();
            $cache->delete('DisableTracking_' . $idSite);
        }
    }

    /**
     * Disables tracking for the given site.
     *
     * @param int $siteId the site to disable tracking for
     *
     * @throws Exception if an error occurred
     */
    protected static function disableSiteTracking($siteId)
    {
        if (empty(Request::processRequest('SitesManager.getSiteFromId', ['idSite' => $siteId]))) {
            throw new Exception('Invalid site ID');
        }

        if (!self::isSiteTrackingDisabled($siteId)) {
            $sql = '
                INSERT INTO `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
                    (siteId, created_at)
                VALUES
                    (?, NOW())
            ';
            Db::query($sql, $siteId);
        }
    }

    /**
     * Enables tracking for the given site.
     *
     * @param int $siteId the site to enable tracking for
     *
     * @throws Exception if an error occurred
     */
    protected static function enableSiteTracking($siteId)
    {
        if (empty(Request::processRequest('SitesManager.getSiteFromId', ['idSite' => $siteId]))) {
            throw new Exception('Invalid site ID');
        }

        if (self::isSiteTrackingDisabled($siteId)) {
            $sql = '
                DELETE FROM
                    `' . Common::prefixTable(self::TABLEDISABLETRACKINGMAP) . '`
                WHERE
                    `siteId` = ?
            ';
            Db::query($sql, $siteId);
        }
    }
}
