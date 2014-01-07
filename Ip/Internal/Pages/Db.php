<?php
/**
 * @package ImpressPages
 *
 *
 */
namespace Ip\Internal\Pages;





class Db {



    /**
     * TODOXX check zone and language url's if they don't match system folder #139
     * Beginning of page URL can conflict with CMS system/core folders. This function checks if the folder can be used in URL beginning.
     *
     * @param $folderName
     * @return bool true if URL is reserved for CMS core
     *
     */
    public function usedUrl($folderName)
    {
        $systemDirs = array();
        // TODOXX make it smart with overriden paths #139
        $systemDirs['Plugin'] = 1;
        $systemDirs['Theme'] = 1;
        $systemDirs['File'] = 1;
        $systemDirs['install'] = 1;
        $systemDirs['update'] = 1;
        if(isset($systemDirs[$folderName])){
            return true;
        } else {
            return false;
        }
    }




    public static function pageInfo($pageId){
        //check when root page id given
        $sql = "
        SELECT
            mte.*
        FROM
            ".ipTable('zone_to_page', 'mte')."
        WHERE
            mte.element_id = :pageId
        ";

        $params = array(
            'pageId' => $pageId
        );
        $answer = ipDb()->fetchRow($sql, $params);
        if ($answer) {
            return $answer;
        }

        //non root page id given
        $voidZone = new \Ip\Internal\Content\Zone(array());
        $breadcrumb = $voidZone->getBreadcrumb($pageId);
        $pageId = $breadcrumb[0]->getId();

        $sql = "
        SELECT
            mte.*
        FROM
            ".ipTable('zone_to_page', 'mte').",
            ".ipTable('page', 'page')."
        WHERE
            page.id = :pageId
            AND
            page.parent = mte.element_id
        ";

        $params = array(
            'pageId' => $pageId
        );
        return ipDb()->fetchRow($sql, $params);
    }


    public static function getZoneName($zoneId){
        $sql = "
        SELECT
            `name`
        FROM
            ".ipTable('zone')."
        WHERE
            id = :id";

        $params = array(
            'id' => (int)$zoneId
        );

        return ipDb()->fetchValue($sql, $params);
    }


    /**
     * @param $zoneId
     * @param $languageId
     * @return mixed
     * @throws \Ip\Exception
     */
    public static function rootId($zoneId, $languageId)
    {
        $sql = '
            SELECT
                mte.element_id
            FROM ' . ipTable('zone_to_page', 'mte') . ', ' . ipTable('language', 'l') . '
            WHERE l.id = :languageId AND  mte.language_id = l.id AND zone_id = :zoneId';

        $where = array(
            'languageId' => $languageId,
            'zoneId' => $zoneId
        );

        $pageId = ipDb()->fetchValue($sql, $where);
        if (!$pageId) {
            $pageId = self::createRootZoneElement($zoneId, $languageId);
        }

        if (!$pageId) {
            throw new \Ip\Exception("Failed to create root zone element. Zone: ". $zoneId . ', ' . $languageId);
        }

        return $pageId;
    }

    /**
     * @param $zoneId
     * @param $languageId
     * @throws \Ip\Exception
     */
    protected static function createRootZoneElement($zoneId, $languageId)
    {
        $pageId = ipDb()->insert('page', array('visible' => 1));

        ipDb()->insert('zone_to_page', array(
                'language_id' => $languageId,
                'zone_id' => $zoneId,
                'element_id' => $pageId,
            ));
        return $pageId;
    }


    public static function deleteRootZoneElements($languageId)
    {
        return ipDb()->delete('zone_to_page', array('language_id' => $languageId));
    }

    public static function isChild($pageId, $parentId)
    {
        $page = self::getPage($pageId);
        if (!$page) {
            return FALSE;
        }
        if ($page['parent'] == $parentId) {
            return TRUE;
        }

        if ($page['parent']) {
            return self::isChild($page['parent'], $parentId);
        }

        return FALSE;
    }


    /**
     * Get page children
     * @param int $elementId
     * @return array
     */
    public static function pageChildren($parentId)
    {
        return ipDb()->select('*', 'page', array('parent' => $parentId), 'ORDER BY `row_number`');
    }

    /**
     *
     * Get page
     * @param int $id
     * @return array
     */
    private static function getPage($id)
    {
        $rs = ipDb()->select('*', 'page', array('id' => $id));
        return $rs ? $rs[0] : null;
    }


    /**
     * @param int $language_id
     * @return array all website zones with meta tags for specified language
     */
    public static function getZones($languageId)
    {
        $sql = 'SELECT m.*, p.url, p.description, p.keywords, p.title
                FROM ' . ipTable('zone', 'm') . ', ' . ipTable('zone_to_language', 'p') . '
                WHERE
                    p.zone_id = m.id
                    AND p.language_id = ?
                ORDER BY m.row_number';

        return ipDb()->fetchAll($sql, array($languageId));
    }

    /**
     * @param $zoneName
     * @param $pageId
     * @param $params
     * @return bool
     */
    public static function updatePage($zoneName, $pageId, $params){
        $values = array();

        $zone = ipContent()->getZone($zoneName);
        if (!$zone) {
            throw new \Ip\Exception("Page doesn't exist");
        }

        $oldPage = $zone->getPage($pageId);
        $oldUrl = $oldPage->getLink(true);

        if (isset($params['navigationTitle'])) {
            $values['button_title'] = $params['navigationTitle'];
        }

        if (isset($params['pageTitle'])) {
            $values['page_title'] = $params['pageTitle'];
        }

        if (isset($params['keywords'])) {
            $values['keywords'] = $params['keywords'];
        }

        if (isset($params['description'])) {
            $values['description'] = $params['description'];
        }

        if (isset($params['url'])) {
            if ($params['url'] == '') {
                if (isset($params['pageTitle']) && $params['pageTitle'] != '') {
                    $params['url'] = self::makeUrl($params['pageTitle'], $pageId);
                } else {
                    if (isset($params['navigationTitle']) && $params['navigationTitle'] != '') {
                        $params['url'] = self::makeUrl($params['navigationTitle'], $pageId);
                    } else {
                        $params['url'] = self::makeUrl('page', $pageId);
                    }
                }
            } else {
                $tmpUrl = str_replace("/", "-", $params['url']);
                $i = 1;
                while (!self::availableUrl($tmpUrl, $pageId)) {
                    $tmpUrl = $params['url'].'-'.$i;
                    $i++;
                }
                $params['url'] = $tmpUrl;
            }

            $values['url'] = $params['url'];
        }

        if (isset($params['createdOn']) && strtotime($params['createdOn']) !== false) {
            $values['created_on'] = $params['createdOn'];
        }

        if (isset($params['lastModified']) && strtotime($params['lastModified']) !== false) {
            $values['last_modified'] = $params['lastModified'];
        }

        if (isset($params['type'])) {
            $values['type'] = $params['type'];
        }

        if (isset($params['redirectURL'])) {
            $values['redirect_url'] = $params['redirectURL'];
        }

        if (isset($params['visible'])) {
            $values['visible'] = $params['visible'];
        }

        if (isset($params['parentId'])) {
            $values['parent'] = $params['parentId'];
        }

        if (isset($params['rowNumber'])) {
            $values['row_number'] = $params['rowNumber'];
        }

        if (isset($params['cached_html'])) {
            $values['cached_html'] = $params['cached_html'];
        }

        if (isset($params['cached_text'])) {
            $values['cached_text'] = $params['cached_text'];
        }

        if (count($values) == 0) {
            return true; //nothing to update.
        }

        ipDb()->update('page', $values, array('id' => $pageId));

        if (isset($params['url']) && $oldPage->getUrl() != $params['url']) {
            $newPage = $zone->getPage($pageId);
            $newUrl = $newPage->getLink(true);
            ipDispatcher()->notify('Ip.urlChanged', array('oldUrl' => $oldUrl, 'newUrl' => $newUrl));
        }

        if (!empty($params['layout']) && \Ip\Internal\File\Functions::isFileInDir($params['layout'], ipThemeFile(''))) {
            $layout = $params['layout'] == $zone->getLayout() ? false : $params['layout']; // if default layout - delete layout
            self::changePageLayout($zone->getAssociatedModule(), $pageId, $layout);
        }

        return true;
    }

    /**
     * @param $groupName
     * @param $moduleName
     * @param $pageId
     * @param $newLayout
     * @return bool whether layout was changed or not
     */
    private static function changePageLayout($moduleName, $pageId, $newLayout) {
        $dbh = ipDb()->getConnection();

        $sql = 'SELECT `layout`
                FROM ' . ipTable('page_layout') . '
                WHERE module_name = :moduleName
                    AND `page_id`   = :pageId';
        $q = $dbh->prepare($sql);
        $q->execute(
            array(
                'moduleName' => $moduleName,
                'pageId' => $pageId,
            )
        );
        $oldLayout = $q->fetchColumn(0);

        $wasLayoutChanged = false;

        if (empty($newLayout)) {
            if ($oldLayout) {
                $sql = 'DELETE FROM ' . ipTable('page_layout') . '
                        WHERE `module_name` = :moduleName
                            AND `page_id` = :pageId';
                $q = $dbh->prepare($sql);
                $result = $q->execute(
                    array(
                        'moduleName' => $moduleName,
                        'pageId' => $pageId,
                    )
                );
                $wasLayoutChanged = true;
            }
        } elseif ($newLayout != $oldLayout && file_exists(ipThemeFile($newLayout))) {
            if (!$oldLayout) {
                ipDb()->insert('page_layout', array(
                        'module_name' => $moduleName,
                        'page_id' => $pageId,
                        'layout' => $newLayout
                    ), true);
                $wasLayoutChanged = true;
            } else {
                ipDb()->update('page_layout', array(
                        'layout' => $newLayout,
                    ), array(
                        'module_name' => $moduleName,
                        'page_id' => $pageId,
                    ));
                $wasLayoutChanged = true;
            }
        }

        return $wasLayoutChanged;
    }

    /**
     *
     * Insert new page
     * @param int $parentId
     * @param array $params
     */
    public static function addPage($parentId, $params)
    {
        $row = array(
            'parent' => $parentId,
            'row_number' => self::getMaxIndex($parentId) + 1,

        );

        //TODOXX sync page service naming. #140
        if (isset($params['button_title'])) {
            $params['navigationTitle'] = $params['button_title'];
        }
        if (isset($params['page_title'])) {
            $params['pageTitle'] = $params['page_title'];
        }
        if (isset($params['redirect_url'])) {
            $params['redirectURL'] = $params['redirect_url'];
        }

        if (isset($params['navigationTitle'])) {
            $row['button_title'] = $params['navigationTitle'];
        }

        if (isset($params['pageTitle'])) {
            $row['page_title'] = $params['pageTitle'];
        }

        if (isset($params['keywords'])) {
            $row['keywords'] = $params['keywords'];
        }

        if (isset($params['description'])) {
            $row['description'] = $params['description'];
        }

        if (isset($params['url'])) {
            $row['url'] = $params['url'];
        }

        if (isset($params['createdOn'])) {
            $row['created_on'] = $params['createdOn'];
        } else {
            $row['created_on'] = date('Y-m-d');
        }

        if (isset($params['lastModified'])) {
            $row['last_modified'] = $params['lastModified'];
        } else {
            $row['last_modified'] = date('Y-m-d');
        }

        if (isset($params['type'])) {
            $row['type'] = $params['type'];
        }

        if (isset($params['redirectURL'])) {
            $row['redirect_url'] = $params['redirectURL'];
        }

        if (isset($params['visible'])) {
            $row['visible'] = (int)$params['visible'];
        }

        if (isset($params['cached_html'])) {
            $row['cached_html'] = $params['cached_html'];
        }

        if (isset($params['cached_text'])) {
            $row['cached_text'] = $params['cached_text'];
        }

        return ipDb()->insert('page', $row);
    }

    private static function getMaxIndex($parentId) {
        $rs = ipDb()->select("MAX(`row_number`) AS `max_row_number`", 'page', array('parent' => $parentId));
        return $rs ? $rs[0]['max_row_number'] : null;
    }


    /**
     *
     * Delete menu element record
     * @param int $id
     */
    public static function deletePage($id)
    {
        ipDb()->delete('page', array('id' => $id));
    }


    public static function copyPage($nodeId, $newParentId, $newIndex)
    {
        $db = ipDb();
        $rs = $db->select('*', 'page', array('id' => $nodeId));
        if (!$rs) {
            trigger_error("Element does not exist");
        }

        $copy = $rs[0];
        unset($copy['id']);
        $copy['parent'] = $newParentId;
        $copy['row_number'] = $newIndex;
        $copy['url'] = self::ensureUniqueUrl($copy['url']);

        return ipDb()->insert('page', $copy);
    }


    /**
     * @param string $url
     * @param int $allowed_id
     * @returns bool true if url is available ignoring $allowed_id page.
     */
    public static function availableUrl($url, $allowedId = null){

        $rs = ipDb()->select('`id`', 'page', array('url' => $url));

        if (!$rs) {
            return true;
        }

        if ($allowedId && $rs[0]['id'] == $allowedId) {
            return true;
        }

        return false;
    }

    /**
     *
     * Create unique URL
     * @param string $url
     * @param int $allowed_id
     */
    public static function makeUrl($url, $allowed_id = null)
    {

        if ($url == '') {
            $url = 'page';
        }

        $url = mb_strtolower($url);
        $url = \Ip\Internal\Text\Transliteration::transform($url);

        $replace = array(
            " " => "-",
            "/" => "-",
            "\\" => "-",
            "\"" => "-",
            "\'" => "-",
            "„" => "-",
            "“" => "-",
            "&" => "-",
            "%" => "-",
            "`" => "-",
            "!" => "-",
            "@" => "-",
            "#" => "-",
            "$" => "-",
            "^" => "-",
            "*" => "-",
            "(" => "-",
            ")" => "-",
            "{" => "-",
            "}" => "-",
            "[" => "-",
            "]" => "-",
            "|" => "-",
            "~" => "-",
            "." => "-",
            "'" => "",
            "?" => "",
            ":" => "",
            ";" => "",
        );
        $url = strtr($url, $replace);

        if ($url == ''){
            $url = '-';
        }

        $url = preg_replace('/-+/', '-', $url);

        if (self::availableUrl($url, $allowed_id)) {
            return $url;
        }

        $i = 1;
        while (!self::availableUrl($url.'-'.$i, $allowed_id)) {
            $i++;
        }

        return $url.'-'.$i;
    }
    
    

    public static function ensureUniqueUrl($url, $allowedId = null) {
        $url = str_replace("/", "-", $url);

        if(self::availableUrl($url, $allowedId))
          return $url;

        $i = 1;
        while(!self::availableUrl($url.'-'.$i, $allowedId)) {
          $i++;
        }

        return $url.'-'.$i;
    }

}