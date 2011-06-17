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
        $sha1sent = $this->encrypt_password($theysent, $salt);
        return $sha1sent == $wehave;
    }
}

/**
 * Plugin configuration class
 */
class PluginAuthWebservice extends PluginAuth {

    public static function has_config() {
        return false;
    }

    public static function get_config_options() {
        return array();
    }

    public static function has_instance_config() {
        return false;
    }

    public static function get_instance_config_options() {
        return array();
    }
}
