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
 * @subpackage core
 * @author     Catalyst IT Ltd
 * @author     Piers Harding
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2011 Catalyst IT Ltd http://catalyst.net.nz
 *
 */

define('INTERNAL', 1);
define('JSON', 1);
define('INSTITUTIONALADMIN', 1);
require(dirname(dirname(dirname(__FILE__))) . '/init.php');

$action = param_variable('action');

if ($action == 'search') {
    require_once('webservicessearchlib.php');
    $params = new StdClass;
    $params->userquery       = trim(param_variable('userquery', ''));
    $params->functionquery   = trim(param_variable('functionquery', ''));
    $params->institution     = param_alphanum('institution', 'all');
    $params->protocol        = param_alphanum('protocol', 'all');
    $params->authtype        = param_alphanum('authtype', 'all');
    $params->institution_requested = param_alphanum('institution_requested', null);

    $offset  = param_integer('offset', 0);
    $limit   = param_integer('limit', 10);
    $sortby  = param_alpha('sortby', 'timelogged');
    $sortdir = param_alpha('sortdir', 'desc');
    $params->sortby  = $sortby;
    $params->sortdir = $sortdir;
    $params->offset  = $offset;
    $params->limit   = $limit;

    json_headers();
    if (param_boolean('raw', false)) {
        $data = get_log_search_results($params, $offset, $limit, $sortby, $sortdir);
    } else {
        $data['data'] = build_webservice_log_search_results($params, $offset, $limit, $sortby, $sortdir);
    }
    $data['error'] = false;
    $data['message'] = null;
    echo json_encode($data);
    exit;
}
