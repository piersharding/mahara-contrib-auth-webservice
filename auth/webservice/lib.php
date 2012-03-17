<?php
/**
 * Mahara: Electronic portfolio, weblog, resume builder and social networking
 * Copyright (C) 2006-2011 Catalyst IT Ltd and others; see:
 *                         http://wiki.mahara.org/Contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    mahara
 * @subpackage auth-webservice
 * @author     Catalyst IT Ltd
 * @author     Piers Harding
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2011 Catalyst IT Ltd http://catalyst.net.nz
 *
 */

defined('INTERNAL') || die();
require_once(get_config('docroot') . 'auth/internal/lib.php');

$path = get_config('docroot') . 'webservice/libs/zend';
set_include_path($path . PATH_SEPARATOR . get_include_path());

require_once(get_config('docroot') . '/webservice/lib.php');
require_once(get_config('docroot') . 'api/xmlrpc/lib.php');

/**
 * if the local_right_nav_update doesn't exist, then when can
 * inject the app token itme in the menu
 */
if (!function_exists('local_right_nav_update')) {
    function local_right_nav_update(&$menu) {
        $menu = ($menu ? $menu : array());
        foreach ($menu as $item) {
            if ($item['path'] == 'settings/apps') {
                return;
            }
        }
        $menu[]=
                array(
                        'path' =>  'settings/apps',
                        'url' => 'webservice/apptokens.php',
                        'title' => get_string('apptokens', 'auth.webservice'),
                        'weight' => 40,
                        'selected' => false,
                        'submenu' => array(),
                );
    }
}

/**
 * The webservice authentication method, which authenticates users against the
 * Mahara database, but ensures that these users can only be used for webservices
 */
class AuthWebservice extends AuthInternal {

    public function __construct($id = null) {
        $this->has_instance_config = false;
        $this->type       = 'webservice';
        if (!empty($id)) {
            return $this->init($id);
        }
        return true;
    }

    /**
     * Attempt to authenticate user
     *
     * @param object $user     As returned from the usr table
     * @param string $password The password being used for authentication
     * @return bool            True/False based on whether the user
     *                         authenticated successfully
     * @throws AuthUnknownUserException If the user does not exist
     */
    public function authenticate_user_account($user, $password, $from='elsewhere') {
        // deny from anywhere other than a webservice context
        if ($from != 'webservice') {
            return false;
        }
        $this->must_be_ready();
        return $this->validate_password($password, $user->password, $user->salt);
    }

    /**
     * Given a password that the user has sent, the password we have for them
     * and the salt we have, see if the password they sent is correct.
     *
     * @param string $theysent The password the user sent
     * @param string $wehave   The password we have in the database for them
     * @param string $salt     The salt we have.
     */
    private function validate_password($theysent, $wehave, $salt) {
        $this->must_be_ready();

        if ($salt == '*') {
            // This is a special salt that means this user simply CAN'T log in.
            // It is used on the root user (id=0)
            return false;
        }

        // The main type - a salted sha1
//         $sha1sent = $this->encrypt_password($theysent, $salt);
        $sha1sent = $this->encrypt_password($theysent, $salt, '$2a$' . get_config('bcrypt_cost') . '$', get_config('passwordsaltmain'));
        return $sha1sent == $wehave;
    }
}

/**
 * Plugin configuration class
 */
class PluginAuthWebservice extends PluginAuth {

    public static function has_config() {
        return true;
    }

    public static function get_config_options() {
        redirect('/webservice/admin/index.php');
    }

    public static function has_instance_config() {
        return false;
    }

    public static function get_instance_config_options() {
        return array();
    }

    public static function menu_items($smarty=null, $selected=null) {
        global $SELECTEDSUBNAV, $USER;

        $items = array(
            'webservice' => array(
                'path'   => 'webservice',
                'url'    => 'webservice/admin/index.php',
                'title'  => get_string('webservices', 'auth.webservice'),
                'weight' => 10,
        		'selected' => false,
                'submenu' => array(),
            ),
            'webservice/oauthconfig' => array(
                'path'   => 'webservice/oauthconfig',
                'url'    => 'webservice/admin/oauthv1sregister.php',
                'title'  => get_string('oauth', 'auth.webservice'),
                'weight' => 10,
        		'selected' => false,
                'submenu' => array(),
            ),
            'webservice/logs' => array(
                'path'   => 'webservice/logs',
                'url'    => 'webservice/admin/webservicelogs.php',
                'title'  => get_string('webservicelogs', 'auth.webservice'),
                'weight' => 20,
        		'selected' => false,
                'submenu' => array(),
            ),
            'webservice/testclient' => array(
                'path'   => 'webservice/testclient',
                'url'    => 'webservice/testclient.php',
                'title'  => get_string('testclient', 'auth.webservice'),
                'weight' => 30,
                'selected' => false,
                'submenu' => array(),
            ),
        );

        if ($USER->is_logged_in() && $smarty) {
            //     $main = main_nav();
            $SELECTEDSUBNAV = ($SELECTEDSUBNAV ? $SELECTEDSUBNAV : array());
            $items = array_merge($SELECTEDSUBNAV, $items);
            $apps = false;
            $SELECTEDSUBNAV = array();
            foreach ($items as $sub) {
                $sub['selected'] = ($selected == $sub['path'] ? true : false);
                $SELECTEDSUBNAV[]= $sub;
                if ($sub['path'] == 'settings/apps') {
                    $apps = true;
                }
            }
            if (!$apps) {
                $SELECTEDSUBNAV[]=
                    array(
                        'path' =>  'settings/apps',
                        'url' => 'webservice/apptokens.php',
                        'title' => get_string('apptokens', 'auth.webservice'),
                        'weight' => 40,
                        'selected' => ($selected == 'settings/apps' ? true : false),
                        'submenu' => array(),
                    );
            }
            $smarty->assign('SELECTEDSUBNAV', $SELECTEDSUBNAV);
        }
        return $items;
    }

    /*
    * cron cleanup service for web service logs
    * set this to go daily at 5 past 1
    */
    public static function get_cron() {
        return array(
            (object)array(
                    'callfunction' => 'clean_webservice_logs',
                    'hour'         => '01',
                    'minute'       => '05',
            ),
        );
    }

    /**
     * The web services cron callback
     * clean out the old records that are N seconds old
     */
    public static function clean_webservice_logs() {
        $LOG_AGE = 8 * 24 * 60 * 60; // 8 days
        delete_records_select('external_services_logs', 'timelogged < ?', array(time() - $LOG_AGE));
    }

    public static function postinst($prevversion) {

        if ($prevversion == 0) {
        // force the upgrade to get the intial services loaded
            external_reload_webservices();
            // can't do the following as it requires a lot postinst for dependencies on data in core
            //             // ensure that we have a webservice auth_instance
            //             $authinstance = get_record('auth_instance', 'institution', 'mahara', 'authname', 'webservice');
            //             if (empty($authinstance)) {
            //                 $authinstance = (object)array(
            //                             'instancename' => 'webservice',
            //                             'priority'     => 2,
            //                             'institution'  => 'mahara',
            //                             'authname'     => 'webservice',
            //                 );
            //                 insert_record('auth_instance', $authinstance);
            //             }
        }
    }
}
