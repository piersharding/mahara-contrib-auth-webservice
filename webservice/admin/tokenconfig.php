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
 * @subpackage admin
 * @author     Catalyst IT Ltd
 * @author     Piers Harding
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2011 Catalyst IT Ltd http://catalyst.net.nz
 *
 */

define('INTERNAL', 1);
define('ADMIN', 1);
define('MENUITEM', 'configextensions/pluginadminwebservices');
// define('MENUITEM', 'webservice/config');
// define('SECTION_PLUGINTYPE', 'core');
// define('SECTION_PLUGINNAME', 'admin');
define('SECTION_PAGE', 'webservice');
require(dirname(dirname(dirname(__FILE__))) . '/init.php');
define('TITLE', get_string('pluginadmin', 'admin'));
require_once('pieforms/pieform.php');
require_once(get_config('docroot') . 'api/xmlrpc/lib.php');

$token  = param_variable('token', 0);
// lookup user cancelled
if ($token == 'add') {
    redirect('/webservice/admin/index.php');
}

$dbtoken = get_record('external_tokens', 'id', $token);
if (empty($dbtoken)) {
    $SESSION->add_error_msg(get_string('invalidtoken', 'auth.webservice'));
    redirect('/webservice/admin/index.php');
}

$dbuser = get_record('usr', 'id', $dbtoken->userid);
$dbservice = get_record('external_services', 'id', $dbtoken->externalserviceid);

$token_details =
    array(
        'name'             => 'allocate_webservice_tokens',
        'successcallback'  => 'allocate_webservice_tokens_submit',
        'validatecallback' => 'allocate_webservice_tokens_validate',
        'jsform'           => true,
        'renderer'         => 'multicolumntable',
        'elements'   => array(
                        'tokenid' => array(
                            'type'  => 'hidden',
                            'value' => $dbtoken->id,
                        ),
                ),
        );

$institutions = get_records_array('institution');
$iopts = array();
foreach ($institutions as $institution) {
    $iopts[trim($institution->name)] = $institution->displayname;
}

$services = get_records_array('external_services', 'restrictedusers', 0);
$sopts = array();
foreach ($services as $service) {
    $sopts[$service->id] = $service->name;
}

$token_details['elements']['institution'] = array(
    'type'         => 'select',
    'title'        => get_string('institution'),
    'options'      => $iopts,
    'defaultvalue' => trim($dbtoken->institution),
);

$searchicon = $THEME->get_url('images/btn-search.gif', false, 'auth/webservice');

if ($USER->is_admin_for_user($dbuser->id)) {
    $user_url = get_config('wwwroot') . 'admin/users/edit.php?id=' . $dbuser->id;
}
else {
    $user_url = get_config('wwwroot') . 'user/view.php?id=' . $dbuser->id;
}

$token_details['elements']['usersearch'] = array(
    'type'        => 'html',
    'title'       => get_string('username'),
    'value'       => '<a href="' . $user_url . '">' . $dbuser->username . '</a>',
);

$token_details['elements']['user'] = array(
    'type'        => 'hidden',
    'value'       => $dbuser->id,
);

$token_details['elements']['service'] = array(
    'type'         => 'select',
    'title'        => get_string('servicename', 'auth.webservice'),
    'options'      => $sopts,
    'defaultvalue' => $dbtoken->externalserviceid,
);

$token_details['elements']['enabled'] = array(
    'title'        => get_string('enabled', 'auth.webservice'),
    'defaultvalue' => (($dbservice->enabled == 1) ? 'checked' : ''),
    'type'         => 'checkbox',
    'disabled'     => true,
);

$token_details['elements']['restricted'] = array(
    'title'        => get_string('restrictedusers', 'auth.webservice'),
    'defaultvalue' => (($dbservice->restrictedusers == 1) ? 'checked' : ''),
    'type'         => 'checkbox',
    'disabled'     => true,
);

$functions = get_records_array('external_services_functions', 'externalserviceid', $dbtoken->externalserviceid);
$function_list = array();
if ($functions) {
    foreach ($functions as $function) {
        $dbfunction = get_record('external_functions', 'name', $function->functionname);
        $function_list[]= '<a href="' . get_config('wwwroot') . 'webservice/wsdoc.php?id=' . $dbfunction->id . '">' . $function->functionname . '</a>';
    }
}
$token_details['elements']['functions'] = array(
    'title'        => get_string('functions', 'auth.webservice'),
    'value'        =>  implode(', ', $function_list),
    'type'         => 'html',
);

$token_details['elements']['wssigenc'] = array(
    'defaultvalue' => (($dbtoken->wssigenc == 1) ? 'checked' : ''),
    'type'         => 'checkbox',
    'disabled'     => false,
    'title'        => get_string('wssigenc', 'auth.webservice'),
);

$token_details['elements']['publickey'] = array(
    'type' => 'textarea',
    'title' => get_string('publickey', 'admin'),
    'defaultvalue' => $dbtoken->publickey,
    'rows' => 15,
    'cols' => 90,
);

$token_details['elements']['publickeyexpires']= array(
    'type' => 'html',
    'title' => get_string('publickeyexpires', 'admin'),
    'value' => ($dbtoken->publickeyexpires ? format_date($dbtoken->publickeyexpires, 'strftimedatetime', 'formatdate', 'auth.webservice') : format_date(time(), 'strftimedatetime', 'formatdate', 'auth.webservice')),
);

$token_details['elements']['submit'] = array(
    'type'  => 'submitcancel',
    'value' => array(get_string('save'), get_string('back')),
    'goto'  => get_config('wwwroot') . 'webservice/admin/index.php',
);

$elements = array(
        // fieldset for managing service function list
        'token_details' => array(
                            'type' => 'fieldset',
                            'legend' => get_string('token', 'auth.webservice') . ': ' . $dbtoken->token,
                            'elements' => array(
                                'sflist' => array(
                                    'type'         => 'html',
                                    'value' =>     pieform($token_details),
                                )
                            ),
                            'collapsible' => false,
                        ),
    );

$form = array(
    'renderer' => 'table',
    'type' => 'div',
    'id' => 'maintable',
    'name' => 'tokenconfig',
    'jsform' => true,
    'successcallback' => 'allocate_webservice_tokens_submit',
    'validatecallback' => 'allocate_webservice_tokens_validate',
    'elements' => $elements,
);


$form = pieform($form);

$smarty = smarty(array(), array('<link rel="stylesheet" type="text/css" href="' . $THEME->get_url('style/webservice.css', false, 'auth/webservice') . '">',));
safe_require('auth', 'webservice');
PluginAuthWebservice::menu_items($smarty, 'webservice');
$smarty->assign('token', $dbtoken->token);
$smarty->assign('form', $form);
$heading = get_string('tokens', 'auth.webservice');
$smarty->assign('PAGEHEADING', $heading);
$smarty->display('form.tpl');

function allocate_webservice_tokens_submit(Pieform $form, $values) {
    global $SESSION;
    $dbtoken = get_record('external_tokens', 'id', $values['tokenid']);
    if (empty($dbtoken)) {
        $SESSION->add_error_msg(get_string('invalidtoken', 'auth.webservice'));
        redirect('/webservice/admin/index.php');
        return;
    }

    if (!empty($values['wssigenc'])) {
        if (empty($values['publickey'])) {
            $SESSION->add_error_msg('Must supply a public key to enable WS-Security');
            redirect('/webservice/admin/tokenconfig.php?token=' . $dbtoken->id);
        }
        $dbtoken->wssigenc = 1;
    }
    else {
        $dbtoken->wssigenc = 0;
    }

    if (!empty($values['publickey'])) {
        $publickey = openssl_x509_parse($values['publickey']);
        if (empty($publickey)) {
            $SESSION->add_error_msg('Invalid public key');
            redirect('/webservice/admin/tokenconfig.php?token=' . $dbtoken->id);
        }
        $dbtoken->publickey = $values['publickey'];
        $dbtoken->publickeyexpires = $publickey['validTo_time_t'];
    }
    else {
        $dbtoken->publickey = '';
        $dbtoken->publickeyexpires = time();
    }

    if ($dbtoken->externalserviceid != $values['service']) {
        $dbtoken->externalserviceid = $values['service'];
    }

    $dbuser = get_record('usr', 'id', $values['user']);
    if ($dbtoken->userid != $dbuser->id) {
        $dbtoken->userid = $dbuser->id;
    }
    $inst = get_record('usr_institution', 'usr', $dbuser->id, 'institution', trim($values['institution']));
    if (empty($inst) && trim($values['institution']) != 'mahara') {
        $SESSION->add_error_msg(get_string('invaliduserselectedinstitution', 'auth.webservice'));
        redirect('/webservice/admin/tokenconfig.php?token=' . $dbtoken->id);
        return;
    }
    if ($dbtoken->institution != $values['institution']) {
        $dbtoken->institution = trim($values['institution']);
    }
    update_record('external_tokens', $dbtoken);

    $SESSION->add_ok_msg(get_string('configsaved', 'auth.webservice'));
    redirect('/webservice/admin/tokenconfig.php?token=' . $dbtoken->id);
}

function allocate_webservice_tokens_validate(PieForm $form, $values) {
    global $SESSION;
    $dbtoken = get_record('external_tokens', 'id', $values['tokenid']);
    if (empty($dbtoken)) {
        $SESSION->add_error_msg(get_string('invalidtoken', 'auth.webservice'));
        redirect('/webservice/admin/index.php');
        return;
    }
    return true;
}
