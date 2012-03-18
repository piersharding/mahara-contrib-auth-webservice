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
require(dirname(dirname(dirname(__FILE__))) . '/init.php');
// define('MENUITEM', 'webservice/config');
define('MENUITEM', 'configextensions/pluginadminwebservices');
// define('SECTION_PLUGINTYPE', 'core');
// define('SECTION_PLUGINNAME', 'admin');
// define('SECTION_PAGE', 'webservice');

$path = get_config('docroot') . 'lib/zend';
set_include_path($path . PATH_SEPARATOR . get_include_path());
require_once(get_config('docroot').'webservice/lib.php');
require_once(get_config('docroot') . 'api/xmlrpc/lib.php');
define('TITLE', get_string('webservices_title', 'auth.webservice'));
require_once('pieforms/pieform.php');

$form = get_config_options_extended();

$form['name'] = 'webserviceconfig';
$form['webserviceconfigform'] = true;
$form['jsform'] = true;
$form['successcallback'] = 'webserviceconfig_submit';
$form['validatecallback'] = 'webserviceconfig_validate';
$form = pieform($form);
$smarty = smarty(array(), array('<link rel="stylesheet" type="text/css" href="' . $THEME->get_url('style/webservice.css', false, 'auth/webservice') . '">',));
safe_require('auth', 'webservice');
PluginAuthWebservice::menu_items($smarty, 'webservice');

$smarty->assign('form', $form);
$heading = get_string('webservices_title', 'auth.webservice');
$smarty->assign('PAGEHEADING', $heading);
$smarty->display('form.tpl');

/* pieforms callback for activate_webservices for
 */
function activate_webservices_submit(Pieform $form, $values) {

    $enabled = $values['enabled'] ? 0 : 1;
    set_config('webservice_enabled', $enabled);

    // reload/upgrade the web services configuration
    if ($enabled) {
        // ensure that we have a webservice auth_instance
        $authinstance = get_record('auth_instance', 'institution', 'mahara', 'authname', 'webservice');
        if (empty($authinstance)) {
            $authinstance = (object)array(
                   'instancename' => 'webservice',
                    'priority'     => 2,
                    'institution'  => 'mahara',
                    'authname'     => 'webservice',
            );
            insert_record('auth_instance', $authinstance);
        }
        external_reload_webservices();
    }
    redirect('/webservice/admin/index.php?plugintype=auth&pluginname=webservice&type=webservice');
}

function activate_webservice_proto_submit(Pieform $form, $values) {

    $enabled = $values['enabled'] ? 0 : 1;
    $proto = $values['protocol'];
    set_config('webservice_'.$proto.'_enabled', $enabled);
    redirect('/webservice/admin/index.php?plugintype=auth&pluginname=webservice&type=webservice');
}

function webservices_function_groups_submit(Pieform $form, $values) {
    global $SESSION;

    if ($values['action'] == 'add') {
        $service = preg_replace('/[^a-zA-Z0-9_ ]+/', '', $values['service']);
        $service = trim($service);
        if (empty($service) || record_exists('external_services', 'name', $service)) {
            $SESSION->add_error_msg(get_string('invalidinput', 'auth.webservice'));
        }
        else {
            $service = array('name' => $service, 'restrictedusers' => 0, 'enabled' => 1, 'component' => 'webservice', 'timecreated' => time());
            insert_record('external_services', $service);
            $SESSION->add_ok_msg(get_string('configsaved', 'auth.webservice'));
        }
    }
    else {
        $service = get_record('external_services', 'id', $values['service']);
        if (!empty($service)) {
            if ($values['action'] == 'edit') {
                redirect('/webservice/admin/serviceconfig.php?service=' . $values['service']);
            }
            else if ($values['action'] == 'delete') {
                // remove everything associated with a service
                $params = array($values['service']);
                delete_records_select('external_tokens', "externalserviceid  = ?", $params);
                delete_records_select('external_services_users', "externalserviceid  = ?", $params);
                delete_records_select('external_services_functions', "externalserviceid  = ?", $params);
                delete_records('external_services', 'id', $values['service']);
                $SESSION->add_ok_msg(get_string('configsaved', 'auth.webservice'));
            }
        }
    }

    // default back to where we came from
    redirect('/webservice/admin/index.php');
}

function webservices_token_submit(Pieform $form, $values) {
    global $SESSION, $USER;

    if ($values['action'] == 'generate') {
        if (isset($values['username'])) {
            $dbuser = get_record('usr', 'username', $values['username']);
            if (!empty($dbuser)) {
                $services = get_records_array('external_services', 'restrictedusers', 0);
                if (empty($services)) {
                    $SESSION->add_error_msg(get_string('noservices', 'auth.webservice'));
                }
                else {
                    // just pass the first one for the moment
                    $service = array_shift($services);
                    $token = webservice_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $dbuser->id);
                    $dbtoken = get_record('external_tokens', 'token', $token);
                    redirect('/webservice/admin/tokenconfig.php?token=' . $dbtoken->id);
                }
            }
            else {
                $SESSION->add_error_msg(get_string('invaliduserselected', 'auth.webservice'));
            }
        }
        else {
            $SESSION->add_error_msg(get_string('nouser', 'auth.webservice'));
        }

    }
    else {
        $token = get_record('external_tokens', 'id', $values['token']);
        if (!empty($token)) {
            if ($values['action'] == 'edit') {
                redirect('/webservice/admin/tokenconfig.php?token=' . $values['token']);
            }
            else if ($values['action'] == 'delete') {
                // remove everything associated with a service
                $params = array($values['token']);
                delete_records_select('external_tokens', "id = ?", $params);
                $SESSION->add_ok_msg(get_string('configsaved', 'auth.webservice'));
            }
        }
    }

    // default back to where we came from
    redirect('/webservice/admin/index.php');
}

function webservices_user_submit(Pieform $form, $values) {
    global $SESSION, $USER;

    if ($values['action'] == 'add') {
        if (isset($values['username'])) {
            $dbuser = get_record('usr', 'username', $values['username']);
            if ($auth_instance = webservice_validate_user($dbuser)) {
                // make sure that this account is not already in use
                $existing = get_record('external_services_users', 'userid', $dbuser->id);
                if (empty($existing)) {
                    $services = get_records_array('external_services', 'restrictedusers', 1);
                    if (empty($services)) {
                      $SESSION->add_error_msg(get_string('noservices', 'auth.webservice'));
                    }
                    else {
                        // just pass the first one for the moment
                        $service = array_shift($services);
                        $dbserviceuser = (object) array('externalserviceid' => $service->id,
                                        'userid' => $dbuser->id,
                                        'institution' => $auth_instance->institution,
                                        'timecreated' => time(),
                                        'publickeyexpires' => time(),
                                        'wssigenc' => 0,
                                        'publickey' => '');
                        $dbserviceuser->id = insert_record('external_services_users', $dbserviceuser, 'id', true);
                        redirect('/webservice/admin/userconfig.php?suid=' . $dbserviceuser->id);
                    }
                }
                else {
                    $SESSION->add_error_msg(get_string('duplicateuser', 'auth.webservice'));
                }
            }
            else {
                $SESSION->add_error_msg(get_string('invaliduserselected', 'auth.webservice'));
            }
        }
        else {
            $SESSION->add_error_msg(get_string('nouser', 'auth.webservice'));
        }
    }
    else {
        $dbserviceuser = get_record('external_services_users', 'id', $values['suid']);
        if (!empty($dbserviceuser)) {
            if ($values['action'] == 'edit') {
                redirect('/webservice/admin/userconfig.php?suid=' . $values['suid']);
            }
            else if ($values['action'] == 'delete') {
                // remove everything associated with a service
                $params = array($values['suid']);
                delete_records_select('external_services_users', "id = ?", $params);
                $SESSION->add_ok_msg(get_string('configsaved', 'auth.webservice'));
            }
        }
    }

    // default back to where we came from
    redirect('/webservice/admin/index.php');
}

/**
 * Form layout for webservices master switch fieldset
 *
 * @return pieforms $element array
 */
function webservices_master_switch_form() {
    // enable/disable webservices completely
    $enabled = (get_config('webservice_enabled') || 0);
    $elements =
            array(
                'funnylittleform' =>
                    array(
                            'type' => 'html',
                            'value' =>
                                 pieform(
                                         array(
                                                'name'            => 'activate_webservices',
                                                'renderer'        => 'oneline',
                                                'elementclasses'  => false,
                                                'successcallback' => 'activate_webservices_submit',
                                                'class'           => 'oneline inline',
                                                'jsform'          => false,
                                                'elements' => array(
                                                    'label'      => array('type' => 'html', 'value' => get_string('control_webservices', 'auth.webservice'),),
                                                    'plugintype' => array('type' => 'hidden', 'value' => 'auth'),
                                                    'type'       => array('type' => 'hidden', 'value' => 'webservice'),
                                                    'pluginname' => array('type' => 'hidden', 'value' => 'webservice'),
                                                    'enabled'    => array('type' => 'hidden', 'value' => $enabled),
                                                    'enable'     => array('type' => 'hidden', 'value' => $enabled-1),
                                                    'submit'     => array(
                                                        'type'  => 'submit',
                                                        'class' => 'linkbtn',
                                                        'value' => $enabled ? get_string('disable') : get_string('enable')
                                                    ),
                                                    'state'     => array('type' => 'html', 'value' => '[' . ($enabled ? get_string('enabled', 'auth.webservice') : get_string('disabled', 'auth.webservice')) . ']',),
                                                ),
                                            )
                                        ),
                                ),
                        );

    return $elements;
}

/**
 * Form layout for webservices protocol switch fieldset
 *
 * @return pieforms $element array
 */
function webservices_protocol_switch_form() {
    // enable/disable separate protocols of SOAP/XML-RPC/REST
    $elements = array();
    $elements['label'] = array('title' => ' ', 'class' => 'header', 'type' => 'html', 'value' => get_string('protocol', 'auth.webservice'),);

    foreach (array('soap', 'xmlrpc', 'rest', 'oauth') as $proto) {
        $enabled = (get_config('webservice_' . $proto . '_enabled') || 0);
        $elements[$proto] =  array(
            'class' => 'header',
            'title' => ' ',
            'type' => 'html',
            'value' =>
        pieform(array(
            'name'            => 'activate_webservice_protos_' . $proto,
            'renderer'        => 'oneline',
            'elementclasses'  => false,
            'successcallback' => 'activate_webservice_proto_submit',
            'class'           => 'oneline inline',
            'jsform'          => false,
            'elements' => array(
                'label'      => array('type' => 'html', 'value' => get_string($proto, 'auth.webservice'),),
                'plugintype' => array('type' => 'hidden', 'value' => 'auth'),
                'type'       => array('type' => 'hidden', 'value' => 'webservice'),
                'pluginname' => array('type' => 'hidden', 'value' => 'webservice'),
                'protocol'   => array('type' => 'hidden', 'value' => $proto),
                'enabled'    => array('type' => 'hidden', 'value' => $enabled),
                'submit'     => array(
                    'type'  => 'submit',
                    'class' => 'linkbtn',
                    'value' => $enabled ? get_string('disable') : get_string('enable')
                ),
                'state'     => array('type' => 'html', 'value' => '[' . ($enabled ? get_string('enabled', 'auth.webservice') : get_string('disabled', 'auth.webservice')) . ']',),
            ),
        )));
    }

    return $elements;
}

/**
 * Service Function Groups edit form
 *
 * @return html
 */
function service_fg_edit_form() {

    $form = array(
        'name'            => 'webservices_function_groups',
        'elementclasses'  => false,
        'successcallback' => 'webservices_function_groups_submit',
        'renderer'   => 'multicolumntable',
        'elements'   => array(
                        'servicegroup' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('service', 'auth.webservice'),
                        ),
                        'component' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('component', 'auth.webservice'),
                        ),
                        'enabled' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('enabled', 'auth.webservice'),
                        ),
                        'restricted' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('restrictedusers', 'auth.webservice'),
                        ),
                        'tokenusers' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('fortokenusers', 'auth.webservice'),
                        ),
                        'functions' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('functions', 'auth.webservice'),
                        ),
                    ),
        );

    $dbservices = get_records_array('external_services', null, null, 'name');
    if ($dbservices) {
        foreach ($dbservices as $service) {
            $form['elements']['id'. $service->id . '_service'] = array(
                'value'        =>  $service->name,
                'type'         => 'html',
                'key'          => $service->name,
            );
            $form['elements']['id'. $service->id . '_component'] = array(
                'value'        =>  $service->component,
                'type'         => 'html',
                'key'          => $service->name,
            );
            $form['elements']['id'. $service->id . '_enabled'] = array(
                'defaultvalue' => (($service->enabled == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $service->name,
            );
            $form['elements']['id'. $service->id . '_restricted'] = array(
                'defaultvalue' => (($service->restrictedusers == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $service->name,
            );
            $form['elements']['id'. $service->id . '_tokenusers'] = array(
                'defaultvalue' => (($service->tokenusers == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $service->name,
            );
            $functions = get_records_array('external_services_functions', 'externalserviceid', $service->id);
            $function_list = array();
            if ($functions) {
                foreach ($functions as $function) {
                    $dbfunction = get_record('external_functions', 'name', $function->functionname);
                    $function_list[]= '<a href="' . get_config('wwwroot') . 'webservice/wsdoc.php?id=' . $dbfunction->id . '">' . $function->functionname . '</a>';
                }
            }
            $form['elements']['id'. $service->id . '_functions'] = array(
                'value'        =>  implode(', ', $function_list),
                'type'         => 'html',
                'key'          => $service->name,
            );

            // edit and delete buttons
            $form['elements']['id'. $service->id . '_actions'] = array(
                'value'        => '<span class="actions inline">' .
                                pieform(array(
                                    'name'            => 'webservices_function_groups_edit_' . $service->id,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_function_groups_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => false,
                                    'action'          => get_config('wwwroot') . 'webservice/admin/index.php',
                                    'elements' => array(
                                        'service'    => array('type' => 'hidden', 'value' => $service->id),
                                        'action'     => array('type' => 'hidden', 'value' => 'edit'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('edit')
                                            ),
                                    ),
                                ))
                                .
                                pieform(array(
                                    'name'            => 'webservices_function_groups_delete_' . $service->id,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_function_groups_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => false,
                                    'action'          => get_config('wwwroot') . 'webservice/admin/index.php',
                                    'elements' => array(
                                        'service'    => array('type' => 'hidden', 'value' => $service->id),
                                        'action'     => array('type' => 'hidden', 'value' => 'delete'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('delete')
                                            ),
                                    ),
                                )) . '</span>'
                                ,
                'type'         => 'html',
                'key'          => $service->name,
                'class'        => 'actions',
            );
        }
    }

    return '<tr><td colspan="2">' .
        pieform($form) . '</td></tr><tr><td colspan="2">' .
                            pieform(array(
                                'name'            => 'webservices_function_groups_add',
                                'renderer'        => 'oneline',
                                'successcallback' => 'webservices_function_groups_submit',
                                'class'           => 'oneline inline',
                                'jsform'          => false,
                                'action'          => get_config('wwwroot') . 'webservice/admin/index.php',
                                'elements' => array(
                                    'service'    => array('type' => 'text'),
                                    'action'     => array('type' => 'hidden', 'value' => 'add'),
                                    'submit'     => array(
                                            'type'  => 'submit',
                                            'class' => 'linkbtn',
                                            'value' => get_string('add')
                                        ),
                                ),
                            )) .
         '</td></tr>';
}

/**
 * Service Tokens Groups edit form
 *
 * @return html
 */
function service_tokens_edit_form() {
    global $THEME, $USER;

    $form = array(
        'name'            => 'webservices_tokens',
        'elementclasses'  => false,
        'successcallback' => 'webservices_tokens_submit',
        'renderer'   => 'multicolumntable',
        'elements'   => array(
                        'token' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('token', 'auth.webservice'),
                        ),
                        'institution' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('institution'),
                        ),
                        'username' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('username', 'auth.webservice'),
                        ),
                        'servicename' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('servicename', 'auth.webservice'),
                        ),
                        'enabled' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('enabled', 'auth.webservice'),
                        ),
                        'wssigenc' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('titlewssigenc', 'auth.webservice'),
                        ),
                        'functions' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('functions', 'auth.webservice'),
                        ),
                    ),
        );

    $dbtokens = get_records_sql_array('SELECT et.id as tokenid, et.wssigenc AS wssigenc, et.externalserviceid as externalserviceid, et.institution as institution, u.id as userid, u.username as username, et.token as token, es.name as name, es.enabled as enabled FROM {external_tokens} AS et LEFT JOIN {usr} AS u ON et.userid = u.id LEFT JOIN {external_services} AS es ON et.externalserviceid = es.id WHERE et.tokentype = ? ORDER BY u.username', array(EXTERNAL_TOKEN_PERMANENT));
    if (!empty($dbtokens)) {
        foreach ($dbtokens as $token) {
            $form['elements']['id'. $token->tokenid . '_token'] = array(
                'value'        =>  $token->token,
                'type'         => 'html',
                'key'          => $token->token,
            );
            $dbinstitution = get_record('institution', 'name', $token->institution);
            $form['elements']['id'. $token->tokenid . '_institution'] = array(
                'value'        =>  $dbinstitution->displayname,
                'type'         => 'html',
                'key'          => $token->token,
            );
            if ($USER->is_admin_for_user($token->userid)) {
                $user_url = get_config('wwwroot') . 'admin/users/edit.php?id=' . $token->userid;
            }
            else {
                $user_url = get_config('wwwroot') . 'user/view.php?id=' . $token->userid;
            }
            $form['elements']['id'. $token->tokenid . '_username'] = array(
                'value'        =>  '<a href="' . $user_url . '">' . $token->username . '</a>',
                'type'         => 'html',
                'key'          => $token->token,
            );
            $form['elements']['id'. $token->tokenid . '_servicename'] = array(
                'value'        =>  $token->name,
                'type'         => 'html',
                'key'          => $token->token,
            );
            $form['elements']['id'. $token->tokenid . '_enabled'] = array(
                'defaultvalue' => (($token->enabled == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $token->token,
            );
            $form['elements']['id'. $token->tokenid . '_wssigenc'] = array(
                'defaultvalue' => (($token->wssigenc == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $token->token,
            );

            $functions = get_records_array('external_services_functions', 'externalserviceid', $token->externalserviceid);
            $function_list = array();
            if ($functions) {
                foreach ($functions as $function) {
                    $dbfunction = get_record('external_functions', 'name', $function->functionname);
                    $function_list[]= '<a href="' . get_config('wwwroot') . 'webservice/wsdoc.php?id=' . $dbfunction->id . '">' . $function->functionname . '</a>';
                }
            }
            $form['elements']['id'. $token->tokenid . '_functions'] = array(
                'value'        =>  implode(', ', $function_list),
                'type'         => 'html',
                'key'          => $token->token,
            );

            // edit and delete buttons
            $form['elements']['id'. $token->tokenid . '_actions'] = array(
                'value'        => '<span class="actions inline">' .
                                pieform(array(
                                    'name'            => 'webservices_token_edit_' . $token->tokenid,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_token_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => true,
                                    'elements' => array(
                                        'token'      => array('type' => 'hidden', 'value' => $token->tokenid),
                                        'action'     => array('type' => 'hidden', 'value' => 'edit'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('edit')
                                            ),
                                    ),
                                ))
                                .
                                pieform(array(
                                    'name'            => 'webservices_token_delete_' . $token->tokenid,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_token_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => true,
                                    'elements' => array(
                                        'token'      => array('type' => 'hidden', 'value' => $token->tokenid),
                                        'action'     => array('type' => 'hidden', 'value' => 'delete'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('delete')
                                            ),
                                    ),
                                )) . '</span>'
                                ,
                'type'         => 'html',
                'key'          => $token->token,
                'class'        => 'actions',
            );
        }
    }

    $username = '';
    if ($user  = param_integer('user', 0)) {
        $dbuser = get_record('usr', 'id', $user);
        if (!empty($dbuser)) {
            $username = $dbuser->username;
        }
    }
    else {
        $username = param_alphanum('username', '');
    }
    $searchicon = $THEME->get_url('images/btn-search.gif', false, 'auth/webservice');
    return '<tr><td colspan="2">' .
        pieform($form) . '</td></tr><tr><td colspan="2">' .
                            pieform(array(
                                'name'            => 'webservices_token_generate',
                                'renderer'        => 'oneline',
                                'successcallback' => 'webservices_token_submit',
                                'class'           => 'oneline inline',
                                'jsform'          => false,
                                'action'          => get_config('wwwroot') . 'webservice/admin/index.php',
                                'elements' => array(
                                    'username'  => array(
                                                           'type'        => 'text',
                                                           'title'       => get_string('username'),
                                                           'value'       => $username,
                                                       ),
                                    'usersearch'  => array(
                                                           'type'        => 'html',
                                                           'value'       => '&nbsp;<a href="' . get_config('wwwroot') .'webservice/admin/search.php?token=add"><img src="' . $searchicon . '" id="usersearch"/></a> &nbsp; ',
                                                       ),

                                    'action'     => array('type' => 'hidden', 'value' => 'generate'),
                                    'submit'     => array(
                                            'type'  => 'submit',
                                            'class' => 'linkbtn',
                                            'value' => get_string('generate', 'auth.webservice')
                                        ),
                                ),
                            )).
         '</td></tr>';

}

/**
 * Service Users edit form
 *
 * @return html
 */
function service_users_edit_form() {
    global $THEME, $USER;

    $form = array(
        'name'            => 'webservices_users',
        'elementclasses'  => false,
        'successcallback' => 'webservices_users_submit',
        'renderer'   => 'multicolumntable',
        'elements'   => array(
                        'username' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('username', 'auth.webservice'),
                        ),
                        'institution' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('institution'),
                        ),
                        'servicename' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('servicename', 'auth.webservice'),
                        ),
                        'enabled' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('enabled', 'auth.webservice'),
                        ),
                        'wssigenc' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('titlewssigenc', 'auth.webservice'),
                        ),
                        'functions' => array(
                            'title' => ' ',
                            'class' => 'header',
                            'type'  => 'html',
                            'value' => get_string('functions', 'auth.webservice'),
                        ),
                    ),
        );

    $dbusers = get_records_sql_array('SELECT eu.id as id, eu.userid as userid, eu.wssigenc AS wssigenc, eu.externalserviceid as externalserviceid, eu.institution as institution, u.username as username, es.name as name, es.enabled as enabled FROM {external_services_users} AS eu LEFT JOIN {usr} AS u ON eu.userid = u.id LEFT JOIN {external_services} AS es ON eu.externalserviceid = es.id ORDER BY eu.id', false);
    if (!empty($dbusers)) {
        foreach ($dbusers as $user) {
            $dbinstitution = get_record('institution', 'name', $user->institution);
            if ($USER->is_admin_for_user($user->id)) {
                $user_url = get_config('wwwroot') . 'admin/users/edit.php?id=' . $user->userid;
            }
            else {
                $user_url = get_config('wwwroot') . 'user/view.php?id=' . $user->userid;
            }
            $form['elements']['id'. $user->id . '_username'] = array(
                'value'        =>  '<a href="' . $user_url . '">' . $user->username . '</a>',
                'type'         => 'html',
                'key'          => $user->id,
            );
            $form['elements']['id'. $user->id . '_institution'] = array(
                'value'        =>  $dbinstitution->displayname,
                'type'         => 'html',
                'key'          => $user->id,
            );
            $form['elements']['id'. $user->id . '_servicename'] = array(
                'value'        =>  $user->name,
                'type'         => 'html',
                'key'          => $user->id,
            );
            $form['elements']['id'. $user->id . '_enabled'] = array(
                'defaultvalue' => (($user->enabled == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $user->id,
            );
            $form['elements']['id'. $user->id . '_wssigenc'] = array(
                'defaultvalue' => (($user->wssigenc == 1) ? 'checked' : ''),
                'type'         => 'checkbox',
                'disabled'     => true,
                'key'          => $user->id,
            );

            $functions = get_records_array('external_services_functions', 'externalserviceid', $user->externalserviceid);
            $function_list = array();
            if ($functions) {
                foreach ($functions as $function) {
                    $dbfunction = get_record('external_functions', 'name', $function->functionname);
                    $function_list[]= '<a href="' . get_config('wwwroot') . 'webservice/wsdoc.php?id=' . $dbfunction->id . '">' . $function->functionname . '</a>';
                }
            }
            $form['elements']['id'. $user->id . '_functions'] = array(
                'value'        =>  implode(', ', $function_list),
                'type'         => 'html',
                'key'          => $user->id,
            );

            // edit and delete buttons
            $form['elements']['id'. $user->id . '_actions'] = array(
                'value'        => '<span class="actions inline">' .
                                pieform(array(
                                    'name'            => 'webservices_user_edit_' . $user->id,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_user_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => true,
                                    'elements' => array(
                                        'suid'       => array('type' => 'hidden', 'value' => $user->id),
                                        'action'     => array('type' => 'hidden', 'value' => 'edit'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('edit')
                                            ),
                                    ),
                                ))
                                .
                                pieform(array(
                                    'name'            => 'webservices_user_delete_' . $user->id,
                                    'renderer'        => 'div',
                                    'elementclasses'  => false,
                                    'successcallback' => 'webservices_user_submit',
                                    'class'           => 'oneline inline',
                                    'jsform'          => true,
                                    'elements' => array(
                                        'suid'       => array('type' => 'hidden', 'value' => $user->id),
                                        'action'     => array('type' => 'hidden', 'value' => 'delete'),
                                        'submit'     => array(
                                                'type'  => 'submit',
                                                'class' => 'linkbtn inline',
                                                'value' => get_string('delete')
                                            ),
                                    ),
                                )) . '</span>'
                                ,
                'type'         => 'html',
                'key'          => $user->id,
                'class'        => 'actions',
            );
        }
    }

    $username = '';
    if ($user  = param_integer('user', 0)) {
        $dbuser = get_record('usr', 'id', $user);
        if (!empty($dbuser)) {
            $username = $dbuser->username;
        }
    }
    else {
        $username = param_alphanum('username', '');
    }
    $searchicon = $THEME->get_url('images/btn-search.gif', false, 'auth/webservice');
    return '<tr><td colspan="2">' .
        pieform($form) . '</td></tr><tr><td colspan="2">' .
                            pieform(array(
                                'name'            => 'webservices_user_generate',
                                'renderer'        => 'oneline',
                                'successcallback' => 'webservices_user_submit',
                                'class'           => 'oneline inline',
                                'jsform'          => false,
                                'action'          => get_config('wwwroot') . 'webservice/admin/index.php',
                                'elements' => array(
                                    'username'  => array(
                                                           'type'        => 'text',
                                                           'title'       => get_string('username'),
                                                           'value'       => $username,
                                                       ),
                                    'usersearch'  => array(
                                                           'type'        => 'html',
                                                           'value'       => '&nbsp;<a href="' . get_config('wwwroot') . 'webservice/admin/search.php?suid=add"><img src="' . $searchicon . '" id="usersearch"/></a> &nbsp; ',
                                                       ),

                                    'action'     => array('type' => 'hidden', 'value' => 'add'),
                                    'submit'     => array(
                                            'type'  => 'submit',
                                            'class' => 'linkbtn',
                                            'value' => get_string('add')
                                        ),
                                ),
                            )).
         '</td></tr>';
}

/**
 *  Custom webservices config page
 *  - activate/deactivate webservices comletely
 *  - activate/deactivat protocols - SOAP/XML-RPC/REST
 *  - manage service clusters
 *  - manage users and access tokens
 *
 *  @return pieforms $element array
 */
function get_config_options_extended() {

    $protos =
        pieform(array(
            'name'            => 'activate_webservice_protos',
            'renderer'        => 'multicolumntable',
            'elements'        => webservices_protocol_switch_form(),
            ));

    // certificate values from MNet
    $openssl = OpenSslRepo::singleton();
    $yesno = array(true  => get_string('yes'),
                   false => get_string('no'));

    $elements = array(
            // fieldset of master switch
            'webservicesmaster' => array(
                'type' => 'fieldset',
                'legend' => get_string('masterswitch', 'auth.webservice'),
                'elements' =>  webservices_master_switch_form(),
                'collapsible' => true,
                'collapsed'   => true,
            ),

            // fieldset of protocol switches
            'protocolswitches' => array(
                                'type' => 'fieldset',
                                'legend' => get_string('protocolswitches', 'auth.webservice'),
                                'elements' =>  array(
                                                'protos_help' =>  array(
                                                'type' => 'html',
                                                'value' => get_string('manage_protocols', 'auth.webservice'),
                                                ),
                                                'enablewebserviceprotos' =>  array(
                                                'type' => 'html',
                                                'value' => $protos,
                                                ),
                                            ),
                                'collapsible' => true,
                                'collapsed'   => true,
                            ),

            // System Certificates
            'certificates' => array(
                                'type' => 'fieldset',
                                'legend' => get_string('certificates', 'auth.webservice'),
                                'elements' =>  array(
                                                'protos_help' =>  array(
                                                'type' => 'html',
                                                'value' => get_string('manage_certificates', 'auth.webservice', get_config('wwwroot') . 'admin/site/networking.php'),
                                                ),

                                                'pubkey' => array(
                                                    'type'         => 'html',
                                                    'title'        => get_string('publickey','admin'),
                                                    'description'  => get_string('publickeydescription2', 'admin', 365),
                                                    'value'        => '<pre style="font-size: 0.7em; white-space: pre;">' . $openssl->certificate . '</pre>'
                                                ),
                                                'sha1fingerprint' => array(
                                                    'type'         => 'html',
                                                    'title'        => 'SHA1 Fingerprint',
                                                    'value'        => $openssl->sha1_fingerprint
                                                ),
                                                'md5fingerprint' => array(
                                                    'type'         => 'html',
                                                    'title'        => 'MD5 Fingerprint',
                                                    'value'        => $openssl->md5_fingerprint
                                                ),
                                                'expires' => array(
                                                    'type'         => 'html',
                                                    'title'        => get_string('publickeyexpires','admin'),
                                                    'value'        => format_date($openssl->expires)
                                                ),
                                            ),
                                'collapsible' => true,
                                'collapsed'   => true,
                            ),

            // fieldset for managing service function groups
            'servicefunctiongroups' => array(
                                'type' => 'fieldset',
                                'legend' => get_string('servicefunctiongroups', 'auth.webservice'),
                                'elements' => array(
                                    'sfgdescription' => array(
                                        'value' => '<tr><td colspan="2">' . get_string('sfgdescription', 'auth.webservice') . '</td></tr>'
                                    ),
                                    'webservicesservicecontainer' => array(
                                        'type'         => 'html',
                                        'value' => service_fg_edit_form(),
                                    )
                                ),
                                'collapsible' => true,
                                'collapsed'   => true,
                            ),


            // fieldset for managing service tokens
            'servicetokens' => array(
                                'type' => 'fieldset',
                                'legend' => get_string('servicetokens', 'auth.webservice'),
                                'elements' => array(
                                    'stdescription' => array(
                                        'value' => '<tr><td colspan="2">' . get_string('stdescription', 'auth.webservice') . '</td></tr>'
                                    ),
                                    'webservicestokenscontainer' => array(
                                        'type'         => 'html',
                                        'value' => service_tokens_edit_form(),
                                    )
                                ),
                                'collapsible' => true,
                                'collapsed'   => false,
                            ),

            // fieldset for managing service tokens
            'serviceusers' => array(
                                'type' => 'fieldset',
                                'legend' => get_string('manageserviceusers', 'auth.webservice'),
                                'elements' => array(
                                    'sudescription' => array(
                                        'value' => '<tr><td colspan="2">' . get_string('sudescription', 'auth.webservice') . '</td></tr>'
                                    ),
                                    'webservicesuserscontainer' => array(
                                        'type'         => 'html',
                                        'value' => service_users_edit_form(),
                                    )
                                ),
                                'collapsible' => true,
                                'collapsed'   => false,
                            ),
);

    $form = array(
        'renderer' => 'table',
        'type' => 'div',
        'elements' => $elements,
    );

    return $form;
}
