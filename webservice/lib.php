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
 * @subpackage webservice
 * @author     Catalyst IT Ltd
 * @author     Piers Harding
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2011 Catalyst IT Ltd http://catalyst.net.nz
 *
 */

defined('INTERNAL') || die();

$path = get_config('docroot') . 'webservice/libs/zend';
set_include_path($path . PATH_SEPARATOR . get_include_path());
require_once(get_config('docroot') . 'webservice/locallib.php');
require_once(get_config('docroot') . 'artefact/lib.php');
require_once(get_config('docroot') . 'webservice/libs/net.php');
require_once(get_config('docroot') . 'api/xmlrpc/lib.php');

/**
 * The directory within a component that contains the web service files
 */
define('WEBSERVICE_DIRECTORY', 'webservice');

/**
 * Security token used for allowing access
 * from external application such as web services.
 * Scripts do not use any session, performance is relatively
 * low because we need to load access info in each request.
 * Scripts are executed in parallel.
 */
define('EXTERNAL_TOKEN_PERMANENT', 0);

/**
 * Security token used for allowing access
 * of embedded applications, the code is executed in the
 * active user session. Token is invalidated after user logs out.
 * Scripts are executed serially - normal session locking is used.
 */
define('EXTERNAL_TOKEN_EMBEDDED', 1);

/**
 * OAuth Token type for registered applications oauth v1
 */
define('EXTERNAL_TOKEN_OAUTH1', 2);

/**
 * OAuth Token type for registered applications oauth v1
 */
define('EXTERNAL_TOKEN_USER', 3);

/**
 * Personal User Tokens expiry time
 */
define('EXTERNAL_TOKEN_USER_EXPIRES', (30 * 24 * 60 * 60));

define('WEBSERVICE_AUTHMETHOD_USERNAME', 0);
define('WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN', 1);
define('WEBSERVICE_AUTHMETHOD_SESSION_TOKEN', 2);
define('WEBSERVICE_AUTHMETHOD_OAUTH_TOKEN', 3);
define('WEBSERVICE_AUTHMETHOD_USER_TOKEN', 4);

// strictness check
define('MUST_EXIST', 2);

/** Get remote addr constant */
define('GETREMOTEADDR_SKIP_HTTP_CLIENT_IP', '1');

/** Get remote addr constant */
define('GETREMOTEADDR_SKIP_HTTP_X_FORWARDED_FOR', '2');

/**
 * Check that a user is in the institution
 *
 * @param array $user array('id' => .., 'username' => ..)
 * @param string $institution
 * @return boolean true on yes
 */
function mahara_external_in_institution($user, $institution) {
    $institutions = array_keys(load_user_institutions($user->id));
    $auth_instance = get_record('auth_instance', 'id', $user->authinstance);
    $institutions[]= $auth_instance->institution;
    if (!in_array($institution, $institutions)) {
        return false;
    }
    return true;
}

/**
 * parameter definition for output of any Atom generator
 *
 * Returns description of method result value
 * @return external_description
 */
function mahara_external_atom_returns() {
    return new external_single_structure(
    array(
            'id'      => new external_value(PARAM_RAW, 'Atom document Id'),
            'title'   => new external_value(PARAM_RAW, 'Atom document Title'),
            'link'    => new external_value(PARAM_RAW, 'Atom document Link'),
            'email'   => new external_value(PARAM_RAW, 'Atom document Author Email'),
            'name'    => new external_value(PARAM_RAW, 'Atom document Author Name'),
            'updated' => new external_value(PARAM_RAW, 'AAtom document Updated date'),
            'uri'     => new external_value(PARAM_RAW, 'Atom document URI'),
            'entries' => new external_multiple_structure(
                                new external_single_structure(
                                            array(
                                                    'id'        => new external_value(PARAM_RAW, 'Atom entry Id'),
                                                    'link'      => new external_value(PARAM_RAW, 'Atom entry Link'),
                                                    'email'     => new external_value(PARAM_RAW, 'Atom entry Author Link'),
                                                    'name'      => new external_value(PARAM_RAW, 'Atom entry Author Name'),
                                                    'updated'   => new external_value(PARAM_RAW, 'Atom entry updated date'),
                                                    'published' => new external_value(PARAM_RAW, 'Atom entry published date'),
                                                    'title'     => new external_value(PARAM_RAW, 'Atom entry Title'),
                                                    'summary'   => new external_value(PARAM_RAW, 'Atom entry Summary', VALUE_OPTIONAL),
                                                    'content'   => new external_value(PARAM_RAW, 'Atom entry Content', VALUE_OPTIONAL),
                                                    ), 'Atom entry', VALUE_OPTIONAL)
                    , 'Entries', VALUE_OPTIONAL),
                )
    );
}

/**
 * validate the user for webservices access
 * the account must use the webservice auth plugin
 * the account must have membership for the selected auth_instance
 *
 * @param object $dbuser
 */
function webservice_validate_user($dbuser) {
    global $SESSION;
    if (!empty($dbuser)) {
        $auth_instance = get_record('auth_instance', 'id', $dbuser->authinstance);
        if ($auth_instance->authname == 'webservice') {
            $memberships = count_records('usr_institution', 'usr', $dbuser->id);
            if ($memberships == 0) {
                // auth instance should be a mahara one
                if ($auth_instance->institution == 'mahara') {
                    return $auth_instance;
                }
            }
            else {
                $membership = get_record('usr_institution', 'usr', $dbuser->id, 'institution', $auth_instance->institution);
                if (!empty($membership)) {
                    return $auth_instance;
                }
            }
        }
    }
    return NULL;
}

/**
 * List all installed component web service directories
 *
 * @return array of web service plugin directories
 */
function get_ws_subsystems() {
    static $plugindirs = null;

    if (!$plugindirs) {
        // add the root webservice first which is empty because it is docroot, and local
        $plugindirs = array(WEBSERVICE_DIRECTORY, 'local/' . WEBSERVICE_DIRECTORY);

        foreach (plugin_types_installed() as $t) {
            foreach (plugins_installed($t) as $name => $plugindata) {
                $plugindir = $t . '/' . $name;
                if (!empty($plugindata->authplugin)) {
                    $plugindir = 'auth/' . $plugindata->authplugin . '/' . $plugindir;
                }
                $plugindirs[] = $plugindir . '/' . WEBSERVICE_DIRECTORY;
            }
        }
    }

    return $plugindirs;
}

/**
 *  Generate a web services token
 * @param string $tokentype
 * @param integer $serviceorid
 * @param integer $userid
 * @param string $institution
 * @param integer $validuntil
 * @param string $iprestriction
 * @throws WebserviceException
 * @return string token
 */
function webservice_generate_token($tokentype, $serviceorid, $userid, $institution = 'mahara',  $validuntil=0, $iprestriction=''){
    global $USER;
    // make sure the token doesn't exist (even if it should be almost impossible with the random generation)
    $numtries = 0;
    do {
        $numtries ++;
        $generatedtoken = md5(uniqid(rand(),1));
        if ($numtries > 5){
            throw new WebserviceException('tokengenerationfailed');
        }
    } while (record_exists('external_tokens', 'token', $generatedtoken));
    $newtoken = new stdClass();
    $newtoken->token = $generatedtoken;
    if (!is_object($serviceorid)){
        $service = get_record('external_services', 'id', $serviceorid);
    } else {
        $service = $serviceorid;
    }
    $newtoken->externalserviceid = $service->id;
    $newtoken->tokentype = $tokentype;
    $newtoken->userid = $userid;
    if ($tokentype == EXTERNAL_TOKEN_EMBEDDED){
        $newtoken->sid = session_id();
    }

    $newtoken->institution = $institution;
    $newtoken->creatorid = $USER->get('id');
    $newtoken->timecreated = time();
    $newtoken->publickeyexpires = time();
    $newtoken->wssigenc = 0;
    $newtoken->publickey = '';
    $newtoken->validuntil = $validuntil;
    if (!empty($iprestriction)) {
        $newtoken->iprestriction = $iprestriction;
    }
    insert_record('external_tokens', $newtoken);
    return $newtoken->token;
}

/**
 * Create and return a session linked token. Token to be used for html embedded client apps that want to communicate
 * with the Moodle server through web services. The token is linked to the current session for the current page request.
 * It is expected this will be called in the script generating the html page that is embedding the client app and that the
 * returned token will be somehow passed into the client app being embedded in the page.
 * @param string $servicename name of the web service. Service name as defined in db/services.php
 * @param int $context context within which the web service can operate.
 * @return int returns token id.
 */
function webservice_create_service_token($servicename, $userid, $institution = 'mahara',  $validuntil=0, $iprestriction=''){
    $service = get_record('external_services', 'name', $servicename, '*');
    return webservice_generate_token(EXTERNAL_TOKEN_EMBEDDED, $service, $userid, $institution,  $validuntil, $iprestriction);
}

/**
 * Returns detailed function information
 * @param string|object $function name of external function or record from external_function
 * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
 *                        MUST_EXIST means throw exception if no record or multiple records found
 * @return object description or false if not found or exception thrown
 */
function webservice_function_info($function, $strictness=MUST_EXIST) {
    if (!is_object($function)) {
        if (!$function = get_record('external_functions', 'name', $function, NULL, NULL, NULL, NULL, '*')) {
            return false;
        }
    }

    //first find and include the ext implementation class
    $function->classpath = empty($function->classpath) ? get_config('docroot') . $function->component : get_config('docroot') . $function->classpath;
    if (!file_exists($function->classpath . '/functions.php')) {
        throw new WebserviceCodingException(get_string('cannotfindimplfile', 'auth.webservice'));
    }
    require_once($function->classpath . '/functions.php');

    $function->parameters_method = $function->methodname . '_parameters';
    $function->returns_method    = $function->methodname . '_returns';

    // make sure the implementaion class is ok
    if (!method_exists($function->classname, $function->methodname)) {
        throw new WebserviceCodingException(get_string('missingimplofmeth', 'auth.webservice') . $function->classname . '::' . $function->methodname);
    }
    if (!method_exists($function->classname, $function->parameters_method)) {
        throw new WebserviceCodingException(get_string('missingparamdesc', 'auth.webservice'));
    }
    if (!method_exists($function->classname, $function->returns_method)) {
        throw new WebserviceCodingException(get_string('missingretvaldesc', 'auth.webservice'));
    }

    // fetch the parameters description
    $function->parameters_desc = call_user_func(array($function->classname, $function->parameters_method));
    if (!($function->parameters_desc instanceof external_function_parameters)) {
        throw new WebserviceCodingException(get_string('invalidparamdesc', 'auth.webservice'));
    }

    // fetch the return values description
    $function->returns_desc = call_user_func(array($function->classname, $function->returns_method));
    // null means void result or result is ignored
    if (!is_null($function->returns_desc) and !($function->returns_desc instanceof external_description)) {
        throw new WebserviceCodingException(get_string('invalidretdesc', 'auth.webservice'));
    }

    //now get the function description
    //TODO: use localised lang pack descriptions, it would be nice to have
    //      easy to understand descriptions in admin UI,
    //      on the other hand this is still a bit in a flux and we need to find some new naming
    //      conventions for these descriptions in lang packs
    $function->description = null;

    $servicesfile = $function->classpath . '/services.php';

    if (file_exists($servicesfile)) {
        $functions = null;
        include($servicesfile);
        if (isset($functions[$function->name]['description'])) {
            $function->description = $functions[$function->name]['description'];
        }
    }

    return $function;
}

/**
 * General web service library
 */
class webservice {

    /**
     * Get the list of all functions for given service ids
     * @param array $serviceids
     * @return array functions
     */
    public function get_external_functions($serviceids) {
        global $WS_FUNCTIONS;

        if (!empty($serviceids)) {
            $where = (count($serviceids) == 1 ? ' = '.array_shift($serviceids) : ' IN (' . implode(',', $serviceids) . ')');
            $sql = "SELECT f.*
                      FROM {external_functions} f
                     WHERE f.name IN (SELECT sf.functionname
                                        FROM {external_services_functions} sf
                                       WHERE sf.externalserviceid $where)";
            $functions = get_records_sql_array($sql, array());
        } else {
            $functions = array();
        }

        // stash functions for intro spective RPC calls later
        $WS_FUNCTIONS = array();
        foreach ($functions as $function) {
            $WS_FUNCTIONS[$function->name] = array('id' => $function->id);
        }

        return $functions;
    }
}

/**
 * Base class for external api methods.
 */
class external_api {
    private static $contextrestriction;

    /**
     * Set context restriction for all following subsequent function calls.
     * @param stdClass $contex
     * @return void
     */
    public static function set_context_restriction($context) {
        self::$contextrestriction = $context;
    }

    /**
     * This method has to be called before every operation
     * that takes a longer time to finish!
     *
     * @param int $seconds max expected time the next operation needs
     * @return void
     */
    public static function set_timeout($seconds=360) {
        $seconds = ($seconds < 300) ? 300 : $seconds;
        set_time_limit($seconds);
    }

    /**
     * Validates submitted function parameters, if anything is incorrect
     * WebserviceInvalidParameterException is thrown.
     * This is a simple recursive method which is intended to be called from
     * each implementation method of external API.
     * @param external_description $description description of parameters
     * @param mixed $params the actual parameters
     * @return mixed params with added defaults for optional items, invalid_parameters_exception thrown if any problem found
     */
    public static function validate_parameters(external_description $description, $params) {
        if ($description instanceof external_value) {
            if (is_array($params) or is_object($params)) {
                throw new WebserviceInvalidParameterException(get_string('errorscalartype', 'auth.webservice'));
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($params) or $params === 0 or $params === 1 or $params === '0' or $params === '1') {
                    return (bool)$params;
                }
            }
            return validate_param($params, $description->type, $description->allownull, get_string('errorinvalidparamsapi', 'auth.webservice'));

        } else if ($description instanceof external_single_structure) {
            if (!is_array($params)) {
                throw new WebserviceInvalidParameterException(get_string('erroronlyarray', 'auth.webservice'));
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $params)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new WebserviceInvalidParameterException(get_string('errormissingkey', 'auth.webservice', $key));
                    }
                    if ($subdesc->required == VALUE_DEFAULT) {
                        try {
                            $result[$key] = self::validate_parameters($subdesc, $subdesc->default);
                        } catch (WebserviceInvalidParameterException $e) {
                            throw new WebserviceParameterException('invalidextparam', $key);
                        }
                    }
                } else {
                    try {
                        $result[$key] = self::validate_parameters($subdesc, $params[$key]);
                    } catch (WebserviceInvalidParameterException $e) {
                        //it's ok to display debug info as here the information is useful for ws client/dev
                        throw new WebserviceParameterException('invalidextparam',"key: $key (debuginfo: " . $e->debuginfo.") ");
                    }
                }
                unset($params[$key]);
            }
            if (!empty($params)) {
                //list all unexpected keys
                $keys = '';
                foreach($params as $key => $value) {
                    $keys .= $key . ',';
                }
                throw new WebserviceInvalidParameterException(get_string('errorunexpectedkey', 'auth.webservice', $keys));
            }
            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($params)) {
                throw new WebserviceInvalidParameterException(get_string('erroronlyarray', 'auth.webservice'));
            }
            $result = array();
            foreach ($params as $param) {
                $result[] = self::validate_parameters($description->content, $param);
            }
            return $result;

        } else {
            throw new WebserviceInvalidParameterException(get_string('errorinvalidparamsdesc', 'auth.webservice'));
        }
    }

    /**
     * Clean response
     * If a response attribute is unknown from the description, we just ignore the attribute.
     * If a response attribute is incorrect, WebserviceInvalidResponseException is thrown.
     * Note: this function is similar to validate parameters, however it is distinct because
     * parameters validation must be distinct from cleaning return values.
     * @param external_description $description description of the return values
     * @param mixed $response the actual response
     * @return mixed response with added defaults for optional items, WebserviceInvalidResponseException thrown if any problem found
     */
    public static function clean_returnvalue(external_description $description, $response) {
        if ($description instanceof external_value) {
            if (is_array($response) or is_object($response)) {
                throw new WebserviceInvalidResponseException(get_string('errorscalartype', 'auth.webservice'));
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($response) or $response === 0 or $response === 1 or $response === '0' or $response === '1') {
                    return (bool)$response;
                }
            }
            return validate_param($response, $description->type, $description->allownull, get_string('errorinvalidresponseapi', 'auth.webservice'));

        } else if ($description instanceof external_single_structure) {
            if (!is_array($response)) {
                throw new WebserviceInvalidResponseException(get_string('erroronlyarray', 'auth.webservice'));
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $response)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new WebserviceParameterException('errorresponsemissingkey', $key);
                    }
                    if ($subdesc instanceof external_value) {
                        if ($subdesc->required == VALUE_DEFAULT) {
                            try {
                                $result[$key] = self::clean_returnvalue($subdesc, $subdesc->default);
                            } catch (Exception $e) {
                                throw new WebserviceParameterException('invalidextresponse',$key . " (" . $e->getMessage() . ")");
                            }
                        }
                    }
                } else {
                    try {
                        $result[$key] = self::clean_returnvalue($subdesc, $response[$key]);
                    } catch (Exception $e) {
                        //it's ok to display debug info as here the information is useful for ws client/dev
                        throw new WebserviceParameterException('invalidextresponse', $key . " (" . $e->getMessage() . ")");
                    }
                }
                unset($response[$key]);
            }

            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($response)) {
                throw new WebserviceInvalidResponseException(get_string('erroronlyarray', 'auth.webservice'));
            }
            $result = array();
            foreach ($response as $param) {
                $result[] = self::clean_returnvalue($description->content, $param);
            }
            return $result;

        } else {
            throw new WebserviceInvalidResponseException(get_string('errorinvalidresponsedesc', 'auth.webservice'));
        }
    }
}

/**
 * Common ancestor of all parameter description classes
 */
abstract class external_description {
    /** @property string $description description of element */
    public $desc;
    /** @property bool $required element value required, null not allowed */
    public $required;
    /** @property mixed $default default value */
    public $default;

    /**
     * Contructor
     * @param string $desc
     * @param bool $required
     * @param mixed $default
     */
    public function __construct($desc, $required, $default) {
        $this->desc = $desc;
        $this->required = $required;
        $this->default = $default;
    }
}

/**
 * Scalar alue description class
 */
class external_value extends external_description {
    /** @property mixed $type value type PARAM_XX */
    public $type;
    /** @property bool $allownull allow null values */
    public $allownull;

    /**
     * Constructor
     * @param mixed $type
     * @param string $desc
     * @param bool $required
     * @param mixed $default
     * @param bool $allownull
     */
    public function __construct($type, $desc='', $required=VALUE_REQUIRED,
    $default=null, $allownull=NULL_ALLOWED) {
        parent::__construct($desc, $required, $default);
        $this->type      = $type;
        $this->allownull = $allownull;
    }
}

/**
 * Associative array description class
 */
class external_single_structure extends external_description {
    /** @property array $keys description of array keys key=>external_description */
    public $keys;

    /**
     * Constructor
     * @param array $keys
     * @param string $desc
     * @param bool $required
     * @param array $default
     */
    public function __construct(array $keys, $desc='',
    $required=VALUE_REQUIRED, $default=null) {
        parent::__construct($desc, $required, $default);
        $this->keys = $keys;
    }
}

/**
 * Bulk array description class.
 */
class external_multiple_structure extends external_description {
    /** @property external_description $content */
    public $content;

    /**
     * Constructor
     * @param external_description $content
     * @param string $desc
     * @param bool $required
     * @param array $default
     */
    public function __construct(external_description $content, $desc='',
    $required=VALUE_REQUIRED, $default=null) {
        parent::__construct($desc, $required, $default);
        $this->content = $content;
    }
}

/**
 * Description of top level - PHP function parameters.
 * @author skodak
 *
 */
class external_function_parameters extends external_single_structure {
}
/**
 * Is protocol enabled?
 * @param string $protocol name of WS protocol
 * @return bool
 */
function webservice_protocol_is_enabled($protocol) {
    if (!get_config('webservice_enabled')) {
        return false;
    }
    return get_config('webservice_'.$protocol.'_enabled');
}

//=== WS classes ===

/**
 * Mandatory interface for all test client classes.
 * @author Petr Skoda (skodak)
 */
interface webservice_test_client_interface {
    /**
     * Execute test client WS request
     * @param string $serverurl
     * @param string $function
     * @param array $params
     * @return mixed
     */
    public function simpletest($serverurl, $function, $params);
}

/**
 * Mandatory interface for all web service protocol classes
 * @author Petr Skoda (skodak)
 */
interface webservice_server_interface {
    /**
     * Process request from client.
     * @return void
     */
    public function run();
}

/**
 * Abstract web service base class.
 * @author Petr Skoda (skodak)
 */
abstract class webservice_server implements webservice_server_interface {

    /** @property string $wsname name of the web server plugin */
    protected $wsname = null;

    /** @property string $username name of local user */
    protected $username = null;

    /** @property string $password password of the local user */
    protected $password = null;

    /** @property string $service service for wsdl look up */
    protected $service = null;

    /** @property int $userid the local user */
    protected $userid = null;

    /** @property integer $authmethod authentication method one of WEBSERVICE_AUTHMETHOD_* */
    protected $authmethod;

    /** @property string $token authentication token*/
    protected $token = null;

    /** @property int restrict call to one service id*/
    protected $restricted_serviceid = null;

    /** @property string info to add to logging*/
    protected $info = null;
    /**
     * Contructor
     * @param integer $authmethod authentication method one of WEBSERVICE_AUTHMETHOD_*
     */
    public function __construct($authmethod) {
        $this->authmethod = $authmethod;
    }

    /**
     * Authenticate user using username+password or token.
     * This function sets up $USER global.
     * It is safe to use has_capability() after this.
     * This method also verifies user is allowed to use this
     * server.
     * @return void
     */
    protected function authenticate_user() {
        global $USER, $SESSION, $WEBSERVICE_INSTITUTION, $WEBSERVICE_OAUTH_USER;

        if ($this->authmethod == WEBSERVICE_AUTHMETHOD_USERNAME) {
            $this->auth = 'USER';
            //we check that authentication plugin is enabled
            //it is only required by simple authentication
            $plugin = get_record('auth_installed', 'name', 'webservice');
            if (empty($plugin) || $plugin->active != 1) {
                throw new WebserviceAccessException(get_string('wsauthnotenabled', 'auth.webservice'));
            }

            if (!$this->username) {
                throw new WebserviceAccessException(get_string('missingusername', 'auth.webservice'));
            }

            if (!$this->password) {
                throw new WebserviceAccessException(get_string('missingpassword', 'auth.webservice'));
            }

            // special web service login
            safe_require('auth', 'webservice');

            // get the user
            $user = get_record('usr', 'username', $this->username);
            if (empty($user)) {
                throw new WebserviceAccessException(get_string('wrongusernamepassword', 'auth.webservice'));
            }

            // user account is nolonger validly configured
            if (!$auth_instance = webservice_validate_user($user)) {
                throw new WebserviceAccessException(get_string('invalidaccount', 'auth.webservice'));
            }
            // set the global for the web service users defined institution
            $WEBSERVICE_INSTITUTION = $auth_instance->institution;

            // get the institution from the external user
            $ext_user = get_record('external_services_users', 'userid', $user->id);
            if (empty($ext_user)) {
                throw new WebserviceAccessException(get_string('wrongusernamepassword', 'auth.webservice'));
            }
            // determine the internal auth instance
            $auth_instance = get_record('auth_instance', 'institution', $ext_user->institution, 'authname', 'webservice');
            if (empty($auth_instance)) {
                throw new WebserviceAccessException(get_string('wrongusernamepassword', 'auth.webservice'));
            }

            // authenticate the user
            $auth = new AuthWebservice($auth_instance->id);
            if (!$auth->authenticate_user_account($user, $this->password, 'webservice')) {
                // log failed login attempts
                throw new WebserviceAccessException(get_string('wrongusernamepassword', 'auth.webservice'));
            }

        }
        else if ($this->authmethod == WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN){
            $this->auth = 'TOKEN';
            $user = $this->authenticate_by_token(EXTERNAL_TOKEN_PERMANENT);
        }
        else if ($this->authmethod == WEBSERVICE_AUTHMETHOD_OAUTH_TOKEN){
            //OAuth
            $this->auth = 'OAUTH';
            // special web service login
            safe_require('auth', 'webservice');

            // get the user - the user that authorised the token
            $user = get_record('usr', 'id', $this->oauth_token_details['user_id']);
            if (empty($user)) {
                throw new WebserviceAccessException(get_string('wrongusernamepassword', 'auth.webservice'));
            }
            // check user is member of configured OAuth institution
            $institutions = array_keys(load_user_institutions($this->oauth_token_details['user_id']));
            $auth_instance = get_record('auth_instance', 'id', $user->authinstance);
            $institutions[]= $auth_instance->institution;
            if (!in_array($this->oauth_token_details['institution'], $institutions)) {
                throw new WebserviceAccessException(get_string('institutiondenied', 'auth.webservice'));
            }

            // set the global for the web service users defined institution
            $WEBSERVICE_INSTITUTION = $this->oauth_token_details['institution'];
            // set the note of the OAuth service owner
            $WEBSERVICE_OAUTH_USER = $this->oauth_token_details['service_user'];
        } else {
            $this->auth = 'OTHER';
            $user = $this->authenticate_by_token(EXTERNAL_TOKEN_USER);
        }

        // now fake user login, the session is completely empty too
        $USER->reanimate($user->id, $user->authinstance);
    }

    protected function authenticate_by_token($tokentype){
        global $WEBSERVICE_INSTITUTION;

        if ($tokentype == EXTERNAL_TOKEN_PERMANENT || $tokentype == EXTERNAL_TOKEN_USER) {
            $token = get_record('external_tokens', 'token', $this->token);
            // trap personal tokens with no valid until time set
            if ($token && $token->tokentype == EXTERNAL_TOKEN_USER && $token->validuntil == 0 && (($token->timecreated - time()) > EXTERNAL_TOKEN_USER_EXPIRES)) {
                delete_records('external_tokens', 'token', $this->token);
                throw new WebserviceAccessException(get_string('invalidtimedtoken', 'auth.webservice'));
            }
        }
        else {
            $token = get_record('external_tokens', 'token', $this->token, 'tokentype', $tokentype);
        }
        if (!$token) {
            // log failed login attempts
            throw new WebserviceAccessException(get_string('invalidtoken', 'auth.webservice'));
        }
        // tidy up the uath method - this could be user token or session token
        if ($token->tokentype != EXTERNAL_TOKEN_PERMANENT) {
            $this->auth = 'OTHER';
        }

        /**
         * check the valid until date
         */
        if ($token->validuntil and $token->validuntil < time()) {
            delete_records('external_tokens', 'token', $this->token, 'tokentype', $tokentype);
            throw new WebserviceAccessException(get_string('invalidtimedtoken', 'auth.webservice'));
        }

        //assumes that if sid is set then there must be a valid associated session no matter the token type
        if ($token->sid){
            $session = session_get_instance();
            if (!$session->session_exists($token->sid)){
                delete_records('external_tokens', 'sid', $token->sid);
                throw new WebserviceAccessException(get_string('invalidtokensession', 'auth.webservice'));
            }
        }

        if ($token->iprestriction and !address_in_subnet(getremoteaddr(), $token->iprestriction)) {
            throw new WebserviceAccessException(get_string('invalidiptoken', 'auth.webservice'));
        }

        $this->restricted_serviceid = $token->externalserviceid;

        $user = get_record('usr', 'id', $token->userid, 'deleted', 0);

        // log token access
        set_field('external_tokens', 'lastaccess', time(), 'id', $token->id);

        // set the global for the web service users defined institution
        $WEBSERVICE_INSTITUTION = $token->institution;

        return $user;
    }
}

/**
 * Special abstraction of our srvices that allows
 * interaction with stock Zend ws servers.
 * @author Petr Skoda (skodak)
 */
abstract class webservice_zend_server extends webservice_server {

    /** @property string name of the zend server class : Zend_XmlRpc_Server, Zend_Soap_Server, Zend_Soap_AutoDiscover, ...*/
    protected $zend_class;

    /** @property object Zend server instance */
    protected $zend_server;

    /** @property string $service_class virtual web service class with all functions user name execute, created on the fly */
    protected $service_class;

    /** @property string $functionname the name of the function that is executed */
    protected $functionname = null;

    /**
     * Contructor
     * @param integer $authmethod authentication method - one of WEBSERVICE_AUTHMETHOD_*
     */
    public function __construct($authmethod, $zend_class) {
        parent::__construct($authmethod);
        $this->zend_class = $zend_class;
    }

    /**
     * Process request from client.
     * @param bool $simple use simple authentication
     * @return void
     */
    public function run() {
        global $WEBSERVICE_FUNCTION_RUN, $USER, $WEBSERVICE_INSTITUTION, $WEBSERVICE_START;
        $WEBSERVICE_START = microtime(true);

        // we will probably need a lot of memory in some functions
        raise_memory_limit('128M');

        // set some longer timeout, this script is not sending any output,
        // this means we need to manually extend the timeout operations
        // that need longer time to finish
        external_api::set_timeout();

        // now create the instance of zend server
        $this->init_zend_server();

        // set up exception handler first, we want to sent them back in correct format that
        // the other system understands
        // we do not need to call the original default handler because this ws handler does everything
        set_exception_handler(array($this, 'exception_handler'));

        // init all properties from the request data
        $this->parse_request();

        // process wsdl only, and without a user
        $xml = null;
        if ($this->service && isset($_REQUEST['wsdl'])) {
            $dbservice = get_record('external_services', 'name', $this->service);
            if (empty($dbservice)) {
                // throw error
                throw new WebserviceAccessException(get_string('invalidservice', 'auth.webservice'));
            }
            $serviceids = array($dbservice->id);
            $this->load_services($serviceids);
        }
        else {
            // Manipulate the payload if necessary
            $xml = $this->modify_payload();

            // this sets up $USER and $SESSION and context restrictions
            $this->authenticate_user();
        }

        // make a list of all functions user is allowed to excecute
        $this->init_service_class();

        // tell server what functions are available
        $this->zend_server->setClass($this->service_class);

        // set additional functions
        $this->fixup_functions();

        //send headers
        $this->send_headers();

        // execute and return response, this sends some headers too
        $response = $this->zend_server->handle($xml);
        // store the info of the error
        if (is_object($response) && get_class($response) == 'Zend_XmlRpc_Server_Fault') {
            $ex = $response->getException();
            $this->info = 'exception: ' . get_class($ex) . ' message: ' . $ex->getMessage() . ' debuginfo: ' . (isset($ex->debuginfo) ? $ex->debuginfo : '');
        }

        // session cleanup
        $this->session_cleanup();

        // allready all done if we were doing wsdl
        if (param_variable('wsdl', 0)) {
            die;
        }

        // modify the result
        $response = $this->modify_result($response);

        $time_end = microtime(true);
        $time_taken = $time_end - $WEBSERVICE_START;

        //log the web service request
        if (!isset($_REQUEST['wsdl']) && !empty($WEBSERVICE_FUNCTION_RUN)) {
            $class = get_class($this);
            if (preg_match('/soap/', $class)) {
                $class = 'SOAP';
            }
            else if (preg_match('/xmlrpc/', $class)) {
                $class = 'XML-RPC';
            }
            $log = (object)  array('timelogged' => time(),
                                   'userid' => $USER->get('id'),
                                   'externalserviceid' => $this->restricted_serviceid,
                                   'institution' => $WEBSERVICE_INSTITUTION,
                                   'protocol' => $class,
                                   'auth' => $this->auth,
                                   'functionname' => $WEBSERVICE_FUNCTION_RUN,
                                   'timetaken' => "" . $time_taken,
                                   'uri' => $_SERVER['REQUEST_URI'],
                                   'info' => ($this->info ? $this->info : ''),
                                   'ip' => getremoteaddr());
            insert_record('external_services_logs', $log, 'id', true);
        }
        else {
            // this is WSDL or methodsignature for XML-RPC
        }

        //finally send the result
        // force the content length as this was going wrong
        header('Content-Length: ' . strlen($response));
        echo $response;
        flush();
        die;
    }

    /**
     * Chance for each protocol to modify the function processing list
     *
     */
    protected function fixup_functions() {

        return null;
    }

    /**
     * Chance for each protocol to modify the incoming
     * raw payload - eg: SOAP and auth headers
     *
     * @return content
     */
    protected function modify_payload() {

        return null;
    }

    /**
     * Chance for each protocol to modify the out going
     * raw payload - eg: SOAP encryption and signatures
     *
     * @param string $response The raw response value
     *
     * @return content
     */
    protected function modify_result($response) {

        return $response;
    }

    /**
     * Load virtual class needed for Zend api
     * @return void
     */
    protected function init_service_class() {
        global $USER;

        // first ofall get a complete list of services user is allowed to access
        if ($this->restricted_serviceid) {
            $wscond1 = 'AND s.id = ? ';
            $wscond2 = 'AND s.id = ? ';
        } else {
            $wscond1 = '';
            $wscond2 = '';
        }

        // now make sure the function is listed in at least one service user is allowed to use
        // allow access only if:
        //  1/ entry in the external_services_users table if required
        //  2/ validuntil not reached
        //  3/ has capability if specified in service desc
        //  4/ iprestriction

        $sql = "SELECT s.*, NULL AS iprestriction
                  FROM {external_services} s
                  JOIN {external_services_functions} sf ON (sf.externalserviceid = s.id AND s.restrictedusers = ?)
                 WHERE s.enabled = ? $wscond1

                 UNION

                SELECT s.*, su.iprestriction
                  FROM {external_services} s
                  JOIN {external_services_functions} sf ON (sf.externalserviceid = s.id AND s.restrictedusers = ?)
                  JOIN {external_services_users} su ON (su.externalserviceid = s.id AND su.userid = ?)
                 WHERE s.enabled = ? AND su.validuntil IS NULL OR su.validuntil < ? $wscond2";

        $params = array(0, 1);
        $wscond1 && $params[]= $this->restricted_serviceid;
        $params[]= 1;
        $params[]= $USER->get('id');
        $params[]= 1;
        $params[]= time();
        $wscond2 && $params[]= $this->restricted_serviceid;

        $serviceids = array();
        $rs = get_recordset_sql($sql, $params);

        // now make sure user may access at least one service
        $remoteaddr = getremoteaddr();
        $allowed = false;
        foreach ($rs as $service) {
            // FIXME - had to cast to object
            $service = (object)$service;
            if (isset($serviceids[$service->id])) {
                continue;
            }
            if ($service->iprestriction and !address_in_subnet($remoteaddr, $service->iprestriction)) {
                // wrong request source ip, sorry
                continue;
            }
            $serviceids[$service->id] = $service->id;
        }
        $rs->close();

        $this->load_services($serviceids);
    }

    /**
     * load service function definitions for service discovery and exectution
     *
     * @param array $serviceids
     */
    protected function load_services($serviceids) {
        global $USER;

        // now get the list of all functions
        $wsmanager = new webservice();
        $functions = $wsmanager->get_external_functions($serviceids);

        // now make the virtual WS class with all the fuctions for this particular user
        $methods = '';
        foreach ($functions as $function) {
            $methods .= $this->get_virtual_method_code($function);
        }

        // let's use unique class name, there might be problem in unit tests
        $classname = 'webservices_virtual_class_000000';
        while(class_exists($classname)) {
            $classname++;
        }

        $code = '
/**
 * Virtual class web services for user id ' . $USER->get('id') . '
 */
class ' . $classname . ' {
' . $methods . '

    public function Header ($data) {
        return true;
    }

    public function Security ($data) {
        //error_log("username: " . $data->UsernameToken->Username);
        //error_log("password: " . $data->UsernameToken->Password);
        //throw new WebserviceAccessException(get_string("accessnotallowed", "webservice"));
        return true;
    }
}
';

        // load the virtual class definition into memory
        eval($code);
        $this->service_class = $classname;
    }

    /**
     * returns virtual method code
     * @param object $function
     * @return string PHP code
     */
    protected function get_virtual_method_code($function) {
        $function = webservice_function_info($function);

        //arguments in function declaration line with defaults.
        $paramanddefaults      = array();
        //arguments used as parameters for external lib call.
        $params      = array();
        $params_desc = array();
        foreach ($function->parameters_desc->keys as $name=>$keydesc) {
            $param = '$' . $name;
            $paramanddefault = $param;
            //need to generate the default if there is any
            if ($keydesc instanceof external_value) {
                if ($keydesc->required == VALUE_DEFAULT) {
                    if ($keydesc->default===null) {
                        $paramanddefault .= '=null';
                    } else {
                        switch($keydesc->type) {
                            case PARAM_BOOL:
                                $paramanddefault .= '=' . $keydesc->default; break;
                            case PARAM_INT:
                                $paramanddefault .= '=' . $keydesc->default; break;
                            case PARAM_FLOAT;
                            $paramanddefault .= '=' . $keydesc->default; break;
                            default:
                                $paramanddefault .= '=\'' . $keydesc->default . '\'';
                        }
                    }
                } else if ($keydesc->required == VALUE_OPTIONAL) {
                    //it does make sens to declare a parameter VALUE_OPTIONAL
                    //VALUE_OPTIONAL is used only for array/object key
                    throw new WebserviceException('parametercannotbevalueoptional');
                }
                //for the moment we do not support default for other structure types
            } else {
                if ($keydesc->required == VALUE_DEFAULT) {
                    //accept empty array as default
                    if (isset($keydesc->default) and is_array($keydesc->default)
                    and empty($keydesc->default)) {
                        $paramanddefault .= '=array()';
                    } else {
                        throw new WebserviceException('errornotemptydefaultparamarray', $name);
                    }
                }
                if ($keydesc->required == VALUE_OPTIONAL) {
                    throw new WebserviceException('erroroptionalparamarray', $name);
                }
            }
            $params[] = $param;
            $paramanddefaults[] = $paramanddefault;
            $type = $this->get_phpdoc_type($keydesc);
            $params_desc[] = '     * @param ' . $type . ' $' . $name . ' ' . $keydesc->desc;
        }
        $params                = implode(', ', $params);
        $paramanddefaults      = implode(', ', $paramanddefaults);
        $params_desc           = implode("\n", $params_desc);

        $serviceclassmethodbody = $this->service_class_method_body($function, $params);

        if (is_null($function->returns_desc)) {
            $return = '     * @return void';
        } else {
            $type = $this->get_phpdoc_type($function->returns_desc);
            $return = '     * @return ' . $type . ' ' . $function->returns_desc->desc;
        }

        // now crate the virtual method that calls the ext implementation

        $code = '
    /**
     * ' . $function->description . '
     *
' . $params_desc . '
' . $return . '
     */
    public function ' . $function->name . '(' . $paramanddefaults . ') {
        global $WEBSERVICE_FUNCTION_RUN;
        $WEBSERVICE_FUNCTION_RUN = \'' . $function->name . '\';
' . $serviceclassmethodbody . '
    }
';
        return $code;
    }

    protected function get_phpdoc_type($keydesc) {
        if ($keydesc instanceof external_value) {
            switch($keydesc->type) {
                // 0 or 1 only for now
                case PARAM_BOOL:
                case PARAM_INT:
                    $type = 'int'; break;
                case PARAM_FLOAT;
                $type = 'double'; break;
                default:
                    $type = 'string';
            }

        } else if ($keydesc instanceof external_single_structure) {
            $classname = $this->generate_simple_struct_class($keydesc);
            $type = $classname;

        } else if ($keydesc instanceof external_multiple_structure) {
            $type = 'array';
        }

        return $type;
    }

    protected function generate_simple_struct_class(external_single_structure $structdesc) {
        //only 'object' is supported by SOAP, 'struct' by XML-RPC MDL-23083
        return 'object|struct';
    }

    /**
     * You can override this function in your child class to add extra code into the dynamically
     * created service class. For example it is used in the amf server to cast types of parameters and to
     * cast the return value to the types as specified in the return value description.
     * @param stdClass $function
     * @param array $params
     * @return string body of the method for $function ie. everything within the {} of the method declaration.
     */
    protected function service_class_method_body($function, $params){
        //cast the param from object to array (validate_parameters except array only)
        $castingcode = '';
        if ($params){
            $paramstocast = explode(',', $params);
            foreach ($paramstocast as $paramtocast) {
                //clean the parameter from any white space
                $paramtocast = trim($paramtocast);
                $castingcode .= $paramtocast .
                '=webservice_zend_server::cast_objects_to_array(' . $paramtocast . ');';
            }

        }

        $descriptionmethod = $function->methodname . '_returns()';
        $callforreturnvaluedesc = $function->classname . '::' . $descriptionmethod;
        return $castingcode . '    if (' . $callforreturnvaluedesc . ' == null)  {' .
        $function->classname . '::' . $function->methodname . '(' . $params . ');
                        return null;
                    }
                    return external_api::clean_returnvalue(' . $callforreturnvaluedesc . ', ' . $function->classname . '::' . $function->methodname . '(' . $params . '));';
    }

    /**
     * Recursive function to recurse down into a complex variable and convert all
     * objects to arrays.
     * @param mixed $param value to cast
     * @return mixed Cast value
     */
    public static function cast_objects_to_array($param){
        if (is_object($param)){
            $param = (array)$param;
        }
        if (is_array($param)){
            $toreturn = array();
            foreach ($param as $key=> $param){
                $toreturn[$key] = self::cast_objects_to_array($param);
            }
            return $toreturn;
        } else {
            return $param;
        }
    }

    /**
     * Set up zend service class
     * @return void
     */
    protected function init_zend_server() {
        $this->zend_server = new $this->zend_class();
    }

    /**
     * This method parses the $_REQUEST superglobal and looks for
     * the following information:
     *  1/ user authentication - username+password or token (wsusername, wspassword and wstoken parameters)
     *
     * @return void
     */
    protected function parse_request() {
        if ($this->authmethod == WEBSERVICE_AUTHMETHOD_USERNAME) {
            //note: some clients have problems with entity encoding :-(
            if (isset($_REQUEST['wsusername'])) {
                $this->username = $_REQUEST['wsusername'];
            }
            if (isset($_REQUEST['wspassword'])) {
                $this->password = $_REQUEST['wspassword'];
            }
            if (isset($_REQUEST['wsservice'])) {
                $this->service = $_REQUEST['wsservice'];
            }
        } else {
            if (isset($_REQUEST['wstoken'])) {
                $this->token = $_REQUEST['wstoken'];
            }
        }
    }

    /**
     * Internal implementation - sending of page headers.
     * @return void
     */
    protected function send_headers() {
        header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: no-cache');
        header('Accept-Ranges: none');
    }

    /**
     * Specialised exception handler, we can not use the standard one because
     * it can not just print html to output.
     *
     * @param exception $ex
     * @return void does not return
     */
    public function exception_handler($ex) {
        // detect active db transactions, rollback and log as error
        db_rollback();

        // some hacks might need a cleanup hook
        $this->session_cleanup($ex);

        // now let the plugin send the exception to client
        $this->send_error($ex);

        // not much else we can do now, add some logging later
        exit(1);
    }

    /**
     * Send the error information to the WS client
     * formatted as XML document.
     * @param exception $ex
     * @return void
     */
    protected function send_error($ex=null) {
        $this->send_headers();
        echo $this->zend_server->fault($ex);
    }

    /**
     * Future hook needed for emulated sessions.
     * @param exception $exception null means normal termination, $exception received when WS call failed
     * @return void
     */
    protected function session_cleanup($exception=null) {
        if ($this->authmethod == WEBSERVICE_AUTHMETHOD_USERNAME) {
            // nothing needs to be done, there is no persistent session
        } else {
            // close emulated session if used
        }
    }

}

/**
 * Web Service server base class, this class handles both
 * simple and token authentication.
 * @author Petr Skoda (skodak)
 */
abstract class webservice_base_server extends webservice_server {

    /** @property array $parameters the function parameters - the real values submitted in the request */
    protected $parameters = null;

    /** @property string $functionname the name of the function that is executed */
    protected $functionname = null;

    /** @property object $function full function description */
    protected $function = null;

    /** @property mixed $returns function return value */
    protected $returns = null;

    /**
     * This method parses the request input, it needs to get:
     *  1/ user authentication - username+password or token
     *  2/ function name
     *  3/ function parameters
     *
     * @return void
     */
    abstract protected function parse_request();

    /**
     * Send the result of function call to the WS client.
     * @return void
     */
    abstract protected function send_response();

    /**
     * Send the error information to the WS client.
     * @param exception $ex
     * @return void
     */
    abstract protected function send_error($ex=null);

    /**
     * Process request from client.
     * @return void
     */
    public function run() {
        global $WEBSERVICE_FUNCTION_RUN, $USER, $WEBSERVICE_INSTITUTION, $WEBSERVICE_START;

        $WEBSERVICE_START = microtime(true);

        // we will probably need a lot of memory in some functions
        raise_memory_limit('128M');

        // set some longer timeout, this script is not sending any output,
        // this means we need to manually extend the timeout operations
        // that need longer time to finish
        external_api::set_timeout();

        // set up exception handler first, we want to sent them back in correct format that
        // the other system understands
        // we do not need to call the original default handler because this ws handler does everything
        set_exception_handler(array($this, 'exception_handler'));

        // init all properties from the request data
        $this->parse_request();

        // authenticate user, this has to be done after the request parsing
        // this also sets up $USER and $SESSION
        $this->authenticate_user();

        // find all needed function info and make sure user may actually execute the function
        $this->load_function_info();

        // finally, execute the function - any errors are catched by the default exception handler
        $this->execute();

        $time_end = microtime(true);
        $time_taken = $time_end - $WEBSERVICE_START;

        //log the web service request
        $log = (object)  array('timelogged' => time(),
                               'userid' => $USER->get('id'),
                               'externalserviceid' => $this->restricted_serviceid,
                               'institution' => $WEBSERVICE_INSTITUTION,
                               'protocol' => 'REST',
                               'auth' => $this->auth,
                               'functionname' => $this->functionname,
                               'timetaken' => "" . $time_taken,
                               'uri' => $_SERVER['REQUEST_URI'],
                               'info' => '',
                               'ip' => getremoteaddr());
        insert_record('external_services_logs', $log, 'id', true);

        // send the results back in correct format
        $this->send_response();

        // session cleanup
        $this->session_cleanup();

        die;
    }

    /**
     * Specialised exception handler, we can not use the standard one because
     * it can not just print html to output.
     *
     * @param exception $ex
     * @return void does not return
     */
    public function exception_handler($ex) {
        global $WEBSERVICE_FUNCTION_RUN, $USER, $WEBSERVICE_INSTITUTION, $WEBSERVICE_START;

        // detect active db transactions, rollback and log as error
        db_rollback();

        $time_end = microtime(true);
        $time_taken = $time_end - $WEBSERVICE_START;

        //log the error on the web service request
        $log = (object)  array('timelogged' => time(),
                               'userid' => $USER->get('id'),
                               'externalserviceid' => $this->restricted_serviceid,
                               'institution' => $WEBSERVICE_INSTITUTION,
                               'protocol' => 'REST',
                               'auth' => $this->auth,
                               'functionname' => ($WEBSERVICE_FUNCTION_RUN ? $WEBSERVICE_FUNCTION_RUN : $this->functionname),
                               'timetaken' => '' . $time_taken,
                               'uri' => $_SERVER['REQUEST_URI'],
                               'info' => 'exception: ' . get_class($ex) . ' message: ' . $ex->getMessage() . ' debuginfo: ' . (isset($ex->debuginfo) ? $ex->debuginfo : ''),
                               'ip' => getremoteaddr());
        insert_record('external_services_logs', $log, 'id', true);

        // some hacks might need a cleanup hook
        $this->session_cleanup($ex);

        // now let the plugin send the exception to client
        $this->send_error($ex);

        // not much else we can do now, add some logging later
        exit(1);
    }

    /**
     * Future hook needed for emulated sessions.
     * @param exception $exception null means normal termination, $exception received when WS call failed
     * @return void
     */
    protected function session_cleanup($exception=null) {
        if ($this->authmethod == WEBSERVICE_AUTHMETHOD_USERNAME) {
            // nothing needs to be done, there is no persistent session
        } else {
            // close emulated session if used
        }
    }

    /**
     * Fetches the function description from database,
     * verifies user is allowed to use this function and
     * loads all paremeters and return descriptions.
     * @return void
     */
    protected function load_function_info() {
        global $USER;

        if (empty($this->functionname)) {
            throw new WebserviceInvalidParameterException(get_string('missingfuncname', 'webserivce'));
        }

        // function must exist
        $function = webservice_function_info($this->functionname);
        if (!$function) {
            throw new WebserviceAccessException(get_string('accessextfunctionnotconf', 'auth.webservice'));
        }

        // first ofall get a complete list of services user is allowed to access
        if ($this->restricted_serviceid) {
            $wscond1 = 'AND s.id = ? ';
            $wscond2 = 'AND s.id = ? ';
        } else {
            $wscond1 = '';
            $wscond2 = '';
        }

        // now let's verify access control

        // now make sure the function is listed in at least one service user is allowed to use
        // allow access only if:
        //  1/ entry in the external_services_users table if required
        //  2/ validuntil not reached
        //  3/ has capability if specified in service desc
        //  4/ iprestriction

        $sql = "SELECT s.*, NULL AS iprestriction
                  FROM {external_services} s
                  JOIN {external_services_functions} sf ON (sf.externalserviceid = s.id AND (s.restrictedusers = ? OR s.tokenusers = ?) AND sf.functionname = ?)
                 WHERE s.enabled = ? $wscond1

                 UNION

                SELECT s.*, su.iprestriction
                  FROM {external_services} s
                  JOIN {external_services_functions} sf ON (sf.externalserviceid = s.id AND s.restrictedusers = ? AND sf.functionname = ?)
                  JOIN {external_services_users} su ON (su.externalserviceid = s.id AND su.userid = ?)
                 WHERE s.enabled = ? AND su.validuntil IS NULL OR su.validuntil < ? $wscond2";

        $params = array(0, 1, $function->name, 1);
        $wscond1 && $params[]= $this->restricted_serviceid;
        $params[]= 1;
        $params[]= $function->name;
        $params[]= $USER->get('id');
        $params[]= 1;
        $params[]= time();
        $wscond2 && $params[]= $this->restricted_serviceid;
        $rs = get_recordset_sql($sql, $params);
        // now make sure user may access at least one service
        $remoteaddr = getremoteaddr();
        $allowed = false;
        $serviceids = array();
        foreach ($rs as $service) {
            $serviceids[]= $service['id'];
            if ($service['iprestriction'] and !address_in_subnet($remoteaddr, $service['iprestriction'])) {
                // wrong request source ip, sorry
                continue;
            }
            $allowed = true;
            // one service is enough, no need to continue
            break;
        }
        $rs->close();
        if (!$allowed) {
            throw new WebserviceAccessException(get_string('accesstofunctionnotallowed', 'auth.webservice', $this->functionname));
        }
        // now get the list of all functions - this triggers the stashing of
        // functions in the context
        $wsmanager = new webservice();
        $functions = $wsmanager->get_external_functions($serviceids);

        // we have all we need now
        $this->function = $function;
    }

    /**
     * Execute previously loaded function using parameters parsed from the request data.
     * @return void
     */
    protected function execute() {
        // validate params, this also sorts the params properly, we need the correct order in the next part
        $params = call_user_func(array($this->function->classname, 'validate_parameters'), $this->function->parameters_desc, $this->parameters);

        // execute - yay!
        $this->returns = call_user_func_array(array($this->function->classname, $this->function->methodname), array_values($params));
    }
}

/**
 * Delete all service and external functions information defined in the specified component.
 * @param string $component name of component (mahara, local, etc.)
 * @param bool $dir does this component name have the directory on it
 * @return void
 */
function external_delete_descriptions($component, $dir=true) {

    if ($dir) {
        $component .= ($component ? '/' : '') . WEBSERVICE_DIRECTORY;
    }
    $params = array($component);

    delete_records_select('external_services_users', "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    delete_records_select('external_tokens', "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    delete_records_select('external_services_functions', "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    delete_records_select('oauth_server_token', "osr_id_ref IN (SELECT id FROM {oauth_server_registry} WHERE externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?))", $params);
    delete_records_select('oauth_server_registry', "externalserviceid IN (SELECT id FROM {external_services} WHERE component = ?)", $params);
    delete_records('external_services', 'component', $component);
    delete_records('external_functions', 'component', $component);
}

/**
 * The web services cron callback
 * clean out the old records that are N seconds old
 */
function webservice_clean_webservice_logs() {
    $LOG_AGE = 8 * 24 * 60 * 60; // 8 days
    delete_records_select('external_services_logs', 'timelogged < ?', array(time() - $LOG_AGE));
}

/**
 * Reload the webservice descriptions for all plugins
 *
 * @return bool true = success
 */

function external_reload_webservices() {

    // first - prune all components that are nolonger available/installed
    $dead_components = get_records_sql_array('SELECT DISTINCT component AS component FROM {external_functions} WHERE component NOT IN ('.
                                                implode(', ', array_fill(1, count(get_ws_subsystems()), '?')).')', get_ws_subsystems());
    if ($dead_components) {
        foreach ($dead_components as $component) {
            external_delete_descriptions($component->component, false);
        }
    }
    foreach (get_ws_subsystems() as $component) {
        external_reload_component($component, false);
    }

    return true;
}


/**
 * Reload the webservice descriptions for a single plugins
 *
 * @param string $component
 * @param bool $dir does this component name have the directory on it
 * @return bool true = success
 */

function external_reload_component($component, $dir=true) {

    // are there web service plugins
    if ($dir) {
        $component .= ($component ? '/' : '') . WEBSERVICE_DIRECTORY;
    }
    $basepath = get_config('docroot') . $component;

    // is there a webservice directory with the right files
    if (!file_exists($basepath) || !file_exists($basepath.'/services.php')) {
        external_delete_descriptions($component);
        return false;
    }
    $defpath = $basepath . '/services.php';

    // load new info
    $functions = array();
    $services = array();
    include($defpath);

    // update all function first
    $dbfunctions = get_records_array('external_functions', 'component', $component);
    if (!empty($dbfunctions)) {
        foreach ($dbfunctions as $dbfunction) {
            if (empty($functions[$dbfunction->name])) {
                // the functions is nolonger available for use
                delete_records('external_services_functions', 'functionname', $dbfunction->name);
                delete_records('external_functions', 'id', $dbfunction->id);
                continue;
            }
            $function = $functions[$dbfunction->name];
            unset($functions[$dbfunction->name]);
            $function['classpath'] = empty($function['classpath']) ? $component : $function['classpath'];

            $update = false;
            if ($dbfunction->classname != $function['classname']) {
                $dbfunction->classname = $function['classname'];
                $update = true;
            }
            if ($dbfunction->methodname != $function['methodname']) {
                $dbfunction->methodname = $function['methodname'];
                $update = true;
            }
            if ($dbfunction->classpath != $function['classpath']) {
                $dbfunction->classpath = $function['classpath'];
                $update = true;
            }
            if ($update) {
                update_record('external_functions', $dbfunction);
            }
        }
    }

    foreach ($functions as $fname => $function) {
        $dbfunction = new stdClass();
        $dbfunction->name       = $fname;
        $dbfunction->classname  = $function['classname'];
        $dbfunction->methodname = $function['methodname'];
        $dbfunction->classpath  = empty($function['classpath']) ? null : $function['classpath'];
        $dbfunction->component  = $component;
        $dbfunction->id = insert_record('external_functions', $dbfunction);
    }
    unset($functions);

    // now deal with services
    $dbservices = get_records_array('external_services', 'component', $component);

    if (!empty($dbservices)) {
        foreach ($dbservices as $dbservice) {
            if (empty($services[$dbservice->name])) {
                delete_records('external_services_functions', 'externalserviceid', $dbservice->id);
                delete_records('external_services_users', 'externalserviceid', $dbservice->id);
                delete_records('external_tokens', 'externalserviceid', $dbservice->id);
                delete_records_select('oauth_server_token', "osr_id_ref IN (SELECT id FROM {oauth_server_registry} WHERE externalserviceid = ?)", array($dbservice->id));
                delete_records_select('oauth_server_registry', "externalserviceid = ?", array($dbservice->id));
                delete_records('external_services', 'id', $dbservice->id);
                continue;
            }
            $service = $services[$dbservice->name];
            unset($services[$dbservice->name]);
            $service['enabled'] = empty($service['enabled']) ? 0 : $service['enabled'];
            $service['restrictedusers'] = ((isset($service['restrictedusers']) && $service['restrictedusers'] == 1) ? 1 : 0);
            $service['tokenusers'] = ((isset($service['tokenusers']) && $service['tokenusers'] == 1) ? 1 : 0);

            $update = false;
            if ($dbservice->enabled != $service['enabled']) {
                $dbservice->enabled = $service['enabled'];
                $update = true;
            }
            if ($dbservice->restrictedusers != $service['restrictedusers']) {
                $dbservice->restrictedusers = $service['restrictedusers'];
                $update = true;
            }
            if ($dbservice->tokenusers != $service['tokenusers']) {
                $dbservice->tokenusers = $service['tokenusers'];
                $update = true;
            }
            if ($update) {
                update_record('external_services', $dbservice);
            }

            $functions = get_records_array('external_services_functions', 'externalserviceid', $dbservice->id);
            if (!empty($functions)) {
                foreach ($functions as $function) {
                    $key = array_search($function->functionname, $service['functions']);
                    if ($key === false) {
                        delete_records('external_services_functions', 'id', $function->id);
                    } else {
                        unset($service['functions'][$key]);
                    }
                }
            }
            foreach ($service['functions'] as $fname) {
                $newf = new stdClass();
                $newf->externalserviceid = $dbservice->id;
                $newf->functionname      = $fname;
                insert_record('external_services_functions', $newf);
            }
            unset($functions);
        }
    }
    foreach ($services as $name => $service) {
        $dbservice = new stdClass();
        $dbservice->name               = $name;
        $dbservice->enabled            = empty($service['enabled']) ? 0 : $service['enabled'];
        $dbservice->restrictedusers    = ((isset($service['restrictedusers']) && $service['restrictedusers'] == 1) ? 1 : 0);
        $dbservice->tokenusers         = ((isset($service['tokenusers']) && $service['tokenusers'] == 1) ? 1 : 0);
        $dbservice->component          = $component;
        $dbservice->timecreated        = time();
        $dbservice->id = insert_record('external_services', $dbservice, 'id', true);
        foreach ($service['functions'] as $fname) {
            $newf = new stdClass();
            $newf->externalserviceid = $dbservice->id;
            $newf->functionname      = $fname;
            insert_record('external_services_functions', $newf);
        }
    }

    return true;
}

/**
 * General System type Exception class for errors thrown inside the core
 * web service handling code
 */
class WebserviceException extends MaharaException {
    /**
     * Constructor
     * @param string $errorcode The name of the string to print
     * @param string $debuginfo optional debugging information
     * @param object $a Extra words and phrases that might be required in the error string
     */
    function __construct($errorcode=null, $debuginfo = '', $a=null) {
        parent::__construct(get_string($errorcode, 'auth.webservice', $a) . $debuginfo);
    }
}

/**
 * Web service parameter exception class
 *
 * This exception must be thrown to the web service client when a web service parameter is invalid
 * The error string is gotten from webservice.php
 */
class WebserviceParameterException extends MaharaException {
    /**
     * Constructor
     * @param string $errorcode The name of the string from webservice.php to print
     * @param string $debuginfo optional debugging information
     * @param string $a The name of the parameter
     */
    function __construct($errorcode=null, $debuginfo = '', $a=null) {
        parent::__construct(get_string($errorcode, 'auth.webservice', $a) . $debuginfo);
    }
}

/**
 * Exception indicating programming error, must be fixed by a programer. For example
 * a core API might throw this type of exception if a plugin calls it incorrectly.
 */
class WebserviceCodingException extends MaharaException {
    /**
     * Constructor
     * @param string $debuginfo optional debugging information
     */
    function __construct($debuginfo='') {
        parent::__construct(get_string('codingerror', 'auth.webservice') . $debuginfo);
    }
}

/**
 * Exception indicating malformed parameter problem.
 * This exception is not supposed to be thrown when processing
 * user submitted data in forms. It is more suitable
 * for WS and other low level stuff.
 */
class WebserviceInvalidParameterException extends MaharaException {
    /**
     * Constructor
     * @param string $debuginfo some detailed information
     */
    function __construct($debuginfo=null) {
        parent::__construct(get_string('invalidparameter', 'auth.webservice') . $debuginfo);
    }
}

/**
 * Exception indicating malformed response problem.
 * This exception is not supposed to be thrown when processing
 * user submitted data in forms. It is more suitable
 * for WS and other low level stuff.
 */
class WebserviceInvalidResponseException extends MaharaException {
    /**
     * Constructor
     * @param string $debuginfo some detailed information
     */
    function __construct($debuginfo=null) {
        parent::__construct(get_string('invalidresponse', 'auth.webservice', $a) . $debuginfo);
    }
}

/**
 * Exception indicating access control problem in web service call
 */
class WebserviceAccessException extends MaharaException {
    /**
     * Constructor
     * @param string $debuginfo some detailed information
     */
    function __construct($debuginfo) {
        parent::__construct(get_string('accessexception', 'auth.webservice') . $debuginfo);
    }
}

