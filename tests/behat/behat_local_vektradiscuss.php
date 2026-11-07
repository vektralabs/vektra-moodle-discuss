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
 * Behat steps for local_vektradiscuss: the in-site Vektra assess stub.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps that configure the Vektra assess stub and read its request log.
 */
class behat_local_vektradiscuss extends behat_base {
    /** @var string Config name of the stub's request log (same as self::STUB_LOG). */
    private const STUB_LOG = 'behatstublog';

    /** @var string Config name prefix of a state fixture (same as self::STUB_STATE). */
    private const STUB_STATE = 'behatstubstate_';

    /** @var int Tab presses allowed to reach the discussion button from the top of the page. */
    private const MAX_TABS = 150;

    /**
     * The requests recorded by the stub.
     *
     * Read from the config_plugins row directly: this file is loaded before Moodle's autoloader, so it must
     * not load the stub class (which implements a plugin interface).
     *
     * @return array[]
     */
    private static function stub_log(): array {
        global $DB;
        // Straight from the table: get_config() may return a copy cached before the browser's requests.
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_vektradiscuss', 'name' => self::STUB_LOG]);
        $log = json_decode((string) $value, true);
        return is_array($log) ? $log : [];
    }
    /**
     * Turn the plugin on against the stub: an https API URL and a UI origin distinct from wwwroot, two
     * dedicated keys, an empty request log. No request leaves the site.
     *
     * @Given /^the Vektra assess stub is enabled$/
     */
    public function the_vektra_assess_stub_is_enabled(): void {
        set_config('apiurl', 'https://vektra-stub.invalid', 'block_vektra');
        set_config('apikey', 'behat-block-admin-key', 'block_vektra');
        set_config('mintkey', 'behat-mint-key', 'local_vektradiscuss');
        set_config('teacherkey', 'behat-teacher-key', 'local_vektradiscuss');
        set_config('uiurl', 'https://ui-stub.invalid', 'local_vektradiscuss');
        set_config('enabled', 1, 'local_vektradiscuss');
        set_config('behatstub', 1, 'local_vektradiscuss');
        set_config(self::STUB_LOG, '[]', 'local_vektradiscuss');
    }

    /**
     * Fix the state the stub answers for an activity, with the fields the plugin can render.
     *
     * @Given /^the Vektra assess stub returns state "(?P<state>[a-z_]+)" for "(?P<idnumber>[^"]+)"$/
     * @param string $state
     * @param string $idnumber Activity idnumber.
     */
    public function the_stub_returns_state(string $state, string $idnumber): void {
        $body = ['state' => $state, 'reason' => 'ok'];
        if ($state === 'eligible' || $state === 'reset_granted') {
            $body['pre_session'] = [
                'duration_min' => 30,
                'recipients' => [['role' => 'editingteacher', 'name' => 'Teacher One']],
                'course_material_used' => false,
                'limits' => ['max_message_chars' => 2000, 'messages_per_min' => 4],
                'privacy' => ['notice_url' => 'https://vektra-stub.invalid/privacy', 'notice_version' => '2026-11'],
                'previous_sessions' => $state === 'reset_granted' ? 1 : 0,
            ];
        }
        if ($state === 'reset_granted') {
            $body['session_no_next'] = 2;
        }
        if ($state === 'active') {
            $body['active'] = ['session_no' => 1, 'minutes_left' => 37];
        }
        $this->set_fixture($idnumber, ['status' => 200, 'body' => $body]);
    }

    /**
     * Make the stub fail the state call of an activity with an HTTP status and an error envelope.
     *
     * @Given /^the Vektra assess stub fails state with HTTP (?P<status>\d+) for "(?P<idnumber>[^"]+)"$/
     * @param int $status
     * @param string $idnumber
     */
    public function the_stub_fails_state(int $status, string $idnumber): void {
        $this->set_fixture($idnumber, ['status' => $status, 'body' => ['error' => [
            'code' => 'ERR-ASSESS-001', 'message' => 'stub failure', 'request_id' => 'stub-request-id',
        ]]]);
    }

    /**
     * Count the recorded requests to a path (any method).
     *
     * @Then /^the Vektra assess stub received (?P<count>\d+) requests? to "(?P<path>[^"]+)"$/
     * @param int $count
     * @param string $path
     */
    public function the_stub_received_requests_to(int $count, string $path): void {
        $actual = count(array_filter(self::stub_log(), fn(array $r): bool => $r['path'] === $path));
        if ($actual !== $count) {
            throw new ExpectationException(
                "The stub received {$actual} requests to {$path}, expected {$count}",
                $this->getSession()
            );
        }
    }

    /**
     * Count every recorded request.
     *
     * @Then /^the Vektra assess stub received (?P<count>\d+) requests? in total$/
     * @param int $count
     */
    public function the_stub_received_in_total(int $count): void {
        $actual = count(self::stub_log());
        if ($actual !== $count) {
            throw new ExpectationException("The stub received {$actual} requests, expected {$count}", $this->getSession());
        }
    }

    /**
     * Every recorded mint carried the positive login-as assertion.
     *
     * @Then /^every Vektra assess stub mint asserted no login-as$/
     */
    public function every_mint_asserted_no_loginas(): void {
        foreach (self::stub_log() as $request) {
            if ($request['path'] !== '/api/v1/assess/tokens') {
                continue;
            }
            $body = json_decode((string) $request['body'], true);
            if (
                !is_array($body) || !array_key_exists('realuser_id', $body) || $body['realuser_id'] !== null
                    || ($body['loginas'] ?? null) !== false
            ) {
                throw new ExpectationException('A mint without the login-as assertion was sent', $this->getSession());
            }
        }
    }

    /**
     * The disabled button with a label is focusable and exposes its reason.
     *
     * @Then /^the Vektra discussion button "(?P<label>[^"]+)" should be disabled with a reason$/
     * @param string $label
     */
    public function the_button_should_be_disabled_with_a_reason(string $label): void {
        $button = $this->find_button_by_label($label);
        if ($button->getAttribute('aria-disabled') !== 'true' || $button->hasAttribute('disabled')) {
            throw new ExpectationException("\"{$label}\" is not aria-disabled and focusable", $this->getSession());
        }
        $this->assert_described($button, $label);
    }

    /**
     * The enabled button with a label.
     *
     * @Then /^the Vektra discussion button "(?P<label>[^"]+)" should be enabled$/
     * @param string $label
     */
    public function the_button_should_be_enabled(string $label): void {
        $button = $this->find_button_by_label($label);
        if ($button->getAttribute('aria-disabled') === 'true' || $button->hasAttribute('disabled')) {
            throw new ExpectationException("\"{$label}\" is disabled", $this->getSession());
        }
        $this->assert_described($button, $label);
    }

    /**
     * Reach a discussion button with the Tab key only, from the top of the page (NFR-016 keyboard path).
     *
     * @When /^I tab to the Vektra discussion button "(?P<label>[^"]+)"$/
     * @param string $label
     */
    public function i_tab_to_the_button(string $label): void {
        $this->find_button_by_label($label);
        $this->evaluate_script('document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0);');
        $check = 'return (function(label) {'
            . ' var e = document.activeElement;'
            . ' return !!(e && e.tagName === "BUTTON" && e.closest(".local-vektradiscuss-panel")'
            . ' && e.textContent.trim() === label);'
            . '})(' . json_encode($label) . ');';
        for ($i = 0; $i < self::MAX_TABS; $i++) {
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
            if ($this->evaluate_script($check)) {
                return;
            }
        }
        throw new ExpectationException(
            "\"{$label}\" was not reached with " . self::MAX_TABS . ' Tab presses',
            $this->getSession()
        );
    }

    /**
     * Every element of the region lies within the viewport width and the region does not scroll sideways
     * (WCAG 1.4.10 reflow, checked at a 320 CSS px viewport).
     *
     * @Then /^the "(?P<selector>[^"]+)" region should reflow without horizontal scrolling$/
     * @param string $selector CSS selector of the region.
     */
    public function the_region_should_reflow(string $selector): void {
        $this->find('css', $selector);
        $script = 'return (function(sel) {'
            . ' var root = document.querySelector(sel), w = document.documentElement.clientWidth, bad = [];'
            . ' if (root.scrollWidth > root.clientWidth + 1) { bad.push(sel + " scrolls: " + root.scrollWidth'
            . ' + " > " + root.clientWidth); }'
            . ' [root].concat(Array.prototype.slice.call(root.querySelectorAll("*"))).forEach(function(e) {'
            . ' var r = e.getBoundingClientRect();'
            . ' if (r.width > 0 && (r.left < -1 || r.right > w + 1)) { bad.push(e.tagName + "." + e.className'
            . ' + " [" + Math.round(r.left) + ", " + Math.round(r.right) + "] of " + w); }'
            . ' });'
            . ' return bad.slice(0, 5).join("; ");'
            . '})(' . json_encode($selector) . ');';
        $bad = $this->evaluate_script($script);
        if ($bad !== '' && $bad !== null) {
            throw new ExpectationException("\"{$selector}\" does not reflow: {$bad}", $this->getSession());
        }
    }

    /**
     * Store a state fixture for an activity.
     *
     * @param string $idnumber
     * @param array $fixture
     */
    private function set_fixture(string $idnumber, array $fixture): void {
        $cm = $this->get_course_module_for_identifier($idnumber);
        if (!$cm) {
            throw new ExpectationException("No activity with idnumber {$idnumber}", $this->getSession());
        }
        set_config(self::STUB_STATE . $cm->id, json_encode($fixture), 'local_vektradiscuss');
    }

    /**
     * The button of the panel with a label.
     *
     * @param string $label
     * @return \Behat\Mink\Element\NodeElement
     */
    private function find_button_by_label(string $label): \Behat\Mink\Element\NodeElement {
        $xpath = "//section[contains(concat(' ', normalize-space(@class), ' '), ' local-vektradiscuss-panel ')]"
            . "//button[normalize-space(.) = " . behat_context_helper::escape($label) . "]";
        return $this->find('xpath', $xpath);
    }

    /**
     * The button is described by a non-empty element.
     *
     * @param \Behat\Mink\Element\NodeElement $button
     * @param string $label
     */
    private function assert_described(\Behat\Mink\Element\NodeElement $button, string $label): void {
        $id = (string) $button->getAttribute('aria-describedby');
        $reason = $id !== '' ? $this->getSession()->getPage()->find('css', '#' . $id) : null;
        if (!$reason || trim($reason->getText()) === '') {
            throw new ExpectationException("\"{$label}\" exposes no reason", $this->getSession());
        }
    }
}
