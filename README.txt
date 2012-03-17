mahara-contrib auth-webservice
==============================

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.

 @package    mahara
 @subpackage auth-webservice
 @author     Piers Harding
 @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 @copyright  (C) 2011 and beyond Piers Harding


Webservices support for Mahara (REST, XML-RPC, and SOAP) with OAuth, simple tokens, or user/password authentication.
See https://gitorious.org/mahara-contrib/auth-webservice for further details.

To install you need to download the module:

get https://gitorious.org/mahara-contrib/auth-webservice/archive-tarball/master

Following the instructions below, the process is to unpack the downloaded archive, and then copy the parts within to
the correct locations.

Do the following:

cd /path/to/mahara/htdocs
tar -xzf /path/to/mahara-contrib-auth-webservice-master.tar.gz
cd mahara-contrib-auth-webservice
rsync -av --delete webservice ../
rsync -av --delete auth/webservice ../auth/
cd ..
rmdir mahara-contrib-auth-webservice

You should now have the two necessary module parts in place in /path/to/mahara/htdocs/webservice and /path/to/mahara/htdocs/auth/webservice
Now login as admin to Mahara, and go through the upgrade process to complete the install of the new authentication plugin auth/webservice.
In order to make the auth/webservice module available, you should add this as an authentication plugin for each Institution that requires access via the admin/users/institutions.php page (upon installation it is automatically added to the mahara or 'No Institution' institution.

If you want the users to be able to administer (or atleast see the menu option
for) their own App Tokens then you need to add the line:
  require(get_config('docroot') . 'auth/webservice/lib.php');
To the file /path/to/mahara/htdocs/local/lib.php

This insures that the function call local_right_nav_update() gets picked up.

