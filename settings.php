<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Site settings for local_vektradiscuss.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_vektradiscuss',
        get_string('pluginname', 'local_vektradiscuss')
    );
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $config = new \local_vektradiscuss\local\config();

        $settings->add(new admin_setting_configcheckbox(
            'local_vektradiscuss/enabled',
            get_string('settings_enabled', 'local_vektradiscuss'),
            get_string('settings_enabled_desc', 'local_vektradiscuss'),
            0
        ));

        // Connection: shared with the block, shown read-only.
        $settings->add(new admin_setting_heading(
            'local_vektradiscuss/connection',
            get_string('settings_connection', 'local_vektradiscuss'),
            get_string('settings_connection_desc', 'local_vektradiscuss')
        ));
        $blocksettings = new moodle_url('/admin/settings.php', ['section' => 'blocksettingvektra']);
        foreach (['apiurl' => $config->api_url(), 'publicurl' => $config->public_url()] as $name => $value) {
            $settings->add(new admin_setting_description(
                'local_vektradiscuss/shared_' . $name,
                get_string('settings_' . $name . '_shared', 'local_vektradiscuss'),
                get_string('settings_shared_link', 'local_vektradiscuss', [
                    'value' => $value === '' ? get_string('settings_shared_notset', 'local_vektradiscuss') : s($value),
                    'url' => $blocksettings->out(),
                ])
            ));
        }

        $settings->add(new \local_vektradiscuss\admin\setting_uiurl(
            'local_vektradiscuss/uiurl',
            get_string('settings_uiurl', 'local_vektradiscuss'),
            get_string('settings_uiurl_desc', 'local_vektradiscuss')
        ));

        $settings->add(new admin_setting_configtext(
            'local_vektradiscuss/siteid',
            get_string('settings_siteid', 'local_vektradiscuss'),
            get_string('settings_siteid_desc', 'local_vektradiscuss'),
            '',
            PARAM_ALPHANUMEXT
        ));

        // Keys: four separate, narrow secrets; never the block admin key.
        $settings->add(new admin_setting_heading(
            'local_vektradiscuss/keys',
            get_string('settings_keys', 'local_vektradiscuss'),
            get_string('settings_keys_desc', 'local_vektradiscuss')
        ));

        $settings->add(new \local_vektradiscuss\admin\setting_assess_key(
            'local_vektradiscuss/mintkey',
            get_string('settings_mintkey', 'local_vektradiscuss'),
            get_string('settings_mintkey_desc', 'local_vektradiscuss'),
            'mint'
        ));

        $settings->add(new \local_vektradiscuss\admin\setting_assess_key(
            'local_vektradiscuss/teacherkey',
            get_string('settings_teacherkey', 'local_vektradiscuss'),
            get_string('settings_teacherkey_desc', 'local_vektradiscuss'),
            'teacher'
        ));

        $settings->add(new \local_vektradiscuss\admin\setting_assess_key(
            'local_vektradiscuss/privacyexportkey',
            get_string('settings_privacyexportkey', 'local_vektradiscuss'),
            get_string('settings_privacyexportkey_desc', 'local_vektradiscuss'),
            'privacy_export'
        ));

        $settings->add(new \local_vektradiscuss\admin\setting_assess_key(
            'local_vektradiscuss/privacyerasekey',
            get_string('settings_privacyerasekey', 'local_vektradiscuss'),
            get_string('settings_privacyerasekey_desc', 'local_vektradiscuss'),
            'privacy_erase'
        ));
    }
}
