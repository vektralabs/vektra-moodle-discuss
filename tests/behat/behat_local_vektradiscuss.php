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

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Steps that configure the Vektra assess stub and read its request log.
 */
class behat_local_vektradiscuss extends behat_base {
    /** @var string Config name of the stub's request log (same as self::STUB_LOG). */
    private const STUB_LOG = 'behatstublog';

    /** @var string Config name prefix of a state fixture (same as self::STUB_STATE). */
    private const STUB_STATE = 'behatstubstate_';

    /** @var string Config name prefix of a transcript fixture (same as behat_stub_transport::TRANSCRIPT). */
    private const STUB_TRANSCRIPT = 'behatstubtranscript_';

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
        set_config('siteid', '00000000-0000-4000-8000-00000000be4a', 'local_vektradiscuss');
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
        $this->set_fixture($idnumber, ['status' => 200, 'body' => $this->state_body($state)]);
    }

    /**
     * Fix the state of an activity with the student's transcripts (columns: session, available, purged).
     *
     * @Given /^the Vektra assess stub returns state "(?P<state>[a-z_]+)" for "(?P<idnumber>[^"]+)" with transcripts:$/
     * @param string $state
     * @param string $idnumber
     * @param TableNode $table
     */
    public function the_stub_returns_state_with_transcripts(string $state, string $idnumber, TableNode $table): void {
        $cm = $this->cm($idnumber);
        $body = $this->state_body($state);
        $body['transcripts'] = [];
        foreach ($table->getHash() as $row) {
            $body['transcripts'][] = [
                'tid' => self::tid((int) $cm->id, (int) $row['session']),
                'n' => (int) $row['session'],
                'available' => $row['available'] === 'yes',
                'purged' => $row['purged'] === 'yes',
            ];
        }
        $this->set_fixture($idnumber, ['status' => 200, 'body' => $body]);
    }

    /**
     * Let the stub serve a generated transcript, downloadable by its owner only.
     *
     * @Given /^the Vektra assess stub has the transcript of session (\d+) of "([^"]+)" for "([^"]+)" named "([^"]*)"$/
     * @param int $n
     * @param string $idnumber
     * @param string $username The owner.
     * @param string $filename The name in Vektra's Content-Disposition.
     */
    public function the_stub_has_a_transcript(int $n, string $idnumber, string $username, string $filename): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $owner = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        set_config(self::STUB_TRANSCRIPT . self::tid((int) $cm->id, $n), json_encode([
            'owner' => $owner,
            'filename' => $filename,
            'body' => self::transcript_body($n),
        ]), 'local_vektradiscuss');
    }

    /**
     * Download the linked transcript with the browser's session and compare bytes and headers.
     *
     * The browser would save the file where the test cannot read it, so the link is fetched with the
     * same session cookie, as core's "following ... should download" step does.
     *
     * @Then /^the transcript link of session (?P<n>\d+) should download the stub transcript as "(?P<filename>[^"]+)"$/
     * @param int $n
     * @param string $filename
     */
    public function the_transcript_link_should_download(int $n, string $filename): void {
        $response = $this->fetch_transcript_link($n);
        $headers = strtolower(implode("\n", (array) $response->headers));
        $checks = [
            'status 200' => (int) $response->status === 200,
            'same bytes' => hash('sha256', (string) $response->results) === hash('sha256', self::transcript_body($n)),
            'content-type' => str_contains($headers, 'content-type: text/html; charset=utf-8'),
            'attachment' => str_contains($headers, strtolower('content-disposition: attachment; filename="' . $filename . '"')),
            'nosniff' => str_contains($headers, 'x-content-type-options: nosniff'),
            'csp sandbox' => str_contains($headers, "content-security-policy: sandbox; default-src 'none'"),
            'no-store' => (bool) preg_match('/^cache-control: [^\n]*no-store/m', $headers),
            'no referrer' => str_contains($headers, 'referrer-policy: no-referrer'),
        ];
        $failed = array_keys(array_filter($checks, fn(bool $ok): bool => !$ok));
        if ($failed) {
            throw new ExpectationException(
                'The transcript download fails: ' . implode(', ', $failed),
                $this->getSession()
            );
        }
    }

    /**
     * Download the linked transcript and expect Moodle's error page with a text: no attachment, no transcript byte.
     *
     * @Then /^the transcript link of session (?P<n>\d+) should fail with "(?P<text>[^"]+)"$/
     * @param int $n
     * @param string $text
     */
    public function the_transcript_link_should_fail(int $n, string $text): void {
        $response = $this->fetch_transcript_link($n);
        $headers = strtolower(implode("\n", (array) $response->headers));
        $body = (string) $response->results;
        if (
            str_contains($headers, 'content-disposition: attachment') || str_contains($body, self::transcript_body($n))
                || !str_contains($body, $text)
        ) {
            throw new ExpectationException(
                "The transcript download of session {$n} did not fail with \"{$text}\"",
                $this->getSession()
            );
        }
    }

    /**
     * Count the stub's download-token mints or transcript downloads of one session.
     *
     * @Then /^the Vektra assess stub received (\d+) (download-token|transcript) requests? for session (\d+) of "([^"]+)"$/
     * @param int $count
     * @param string $kind
     * @param int $n
     * @param string $idnumber
     */
    public function the_stub_received_transcript_requests(int $count, string $kind, int $n, string $idnumber): void {
        $path = '/api/v1/assess/transcripts/' . self::tid((int) $this->cm($idnumber)->id, $n)
            . ($kind === 'download-token' ? '/download-token' : '');
        $this->the_stub_received_requests_to($count, $path);
    }

    /**
     * Open transcript.php for a session directly, with the page's session key (no link needed).
     *
     * @When /^I open the transcript download of session (?P<n>\d+) of "(?P<idnumber>[^"]+)"$/
     * @param int $n
     * @param string $idnumber
     */
    public function i_open_the_transcript_download(int $n, string $idnumber): void {
        $cm = $this->cm($idnumber);
        $sesskey = (string) $this->evaluate_script('return M.cfg.sesskey;');
        $url = new moodle_url('/local/vektradiscuss/transcript.php', [
            'cmid' => $cm->id, 'tid' => self::tid((int) $cm->id, $n), 'n' => $n, 'sesskey' => $sesskey,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * The panel shows a transcript entry as text, without a link.
     *
     * @Then /^the transcript of session (?P<n>\d+) should not be downloadable$/
     * @param int $n
     */
    public function the_transcript_should_not_be_downloadable(int $n): void {
        $entry = $this->find('css', '[data-region="local_vektradiscuss-transcript"][data-n="' . $n . '"]');
        if (strtolower($entry->getTagName()) === 'a' || $entry->getAttribute('href') !== null) {
            throw new ExpectationException("The transcript of session {$n} has a download link", $this->getSession());
        }
    }

    /**
     * Fetch a transcript link with the browser's Moodle session.
     *
     * @param int $n
     * @return \stdClass download_file_content() full response.
     */
    private function fetch_transcript_link(int $n): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $link = $this->find('css', 'a[data-region="local_vektradiscuss-transcript"][data-n="' . $n . '"]');
        $url = (string) $link->getAttribute('href');
        $cookie = $this->getSession()->getCookie('MoodleSession' . $CFG->sessioncookie);
        return download_file_content($url, ['Cookie' => 'MoodleSession' . $CFG->sessioncookie . '=' . $cookie], null, true);
    }

    /**
     * A stable transcript id for an activity and a session number (UUID-shaped, like Vektra's session ids).
     *
     * @param int $cmid
     * @param int $n
     * @return string
     */
    private static function tid(int $cmid, int $n): string {
        return sprintf('00000000-0000-4000-8000-%06d%06d', $cmid, $n);
    }

    /**
     * The stub transcript bytes of a session: inert HTML with a marker.
     *
     * @param int $n
     * @return string
     */
    private static function transcript_body(int $n): string {
        return "<!DOCTYPE html>\n<html lang=\"it\"><head><meta charset=\"utf-8\"><title>Trascrizione</title></head>"
            . "<body><h1>Trascrizione, sessione {$n}</h1><p>stub-transcript-marker-{$n}</p></body></html>\n";
    }

    /**
     * The state body the stub answers, with the fields the plugin can render.
     *
     * @param string $state
     * @return array
     */
    private function state_body(string $state): array {
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
        return $body;
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
        $check = 'return (function(label) {'
            . ' var e = document.activeElement;'
            . ' return !!(e && e.tagName === "BUTTON" && e.closest(".local-vektradiscuss-panel")'
            . ' && e.textContent.trim() === label);'
            . '})(' . json_encode($label) . ');';
        $this->tab_until($check, "\"{$label}\"");
    }

    /**
     * The discussion iframe has the expected title (axe rule frame-title) and is not removed from the tab
     * order or the accessibility tree.
     *
     * @Then /^the Vektra discussion iframe should have the accessible name "(?P<name>[^"]+)"$/
     * @param string $name
     */
    public function the_iframe_should_have_the_name(string $name): void {
        $iframe = $this->find('css', 'iframe.local-vektradiscuss-iframe');
        $title = trim((string) $iframe->getAttribute('title'));
        if ($title !== $name) {
            throw new ExpectationException("The discussion iframe title is \"{$title}\", not \"{$name}\"", $this->getSession());
        }
        if ($iframe->getAttribute('tabindex') === '-1' || $iframe->getAttribute('aria-hidden') === 'true') {
            throw new ExpectationException(
                'The discussion iframe is hidden from keyboard or assistive technology',
                $this->getSession()
            );
        }
    }

    /**
     * The discussion iframe loads the UI page of the configured site on the UI origin, sandboxed and without
     * referrer, and carries no token in its URL (postMessage contract v1).
     *
     * @Then /^the Vektra discussion iframe should load the UI page of the configured site$/
     */
    public function the_iframe_should_load_the_ui_page(): void {
        $iframe = $this->find('css', 'iframe.local-vektradiscuss-iframe');
        $expected = 'https://ui-stub.invalid/api/v1/assess/ui?site='
            . rawurlencode((string) get_config('local_vektradiscuss', 'siteid'));
        $checks = [
            'src' => [$iframe->getAttribute('src'), $expected],
            'sandbox' => [$iframe->getAttribute('sandbox'), 'allow-scripts allow-same-origin allow-downloads'],
            'allow' => [$iframe->getAttribute('allow'), 'clipboard-write'],
            'referrerpolicy' => [$iframe->getAttribute('referrerpolicy'), 'no-referrer'],
        ];
        foreach ($checks as $name => [$actual, $want]) {
            if ($actual !== $want) {
                throw new ExpectationException(
                    "The discussion iframe {$name} is \"{$actual}\", not \"{$want}\"",
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Count the discussion iframes in the page.
     *
     * @Then /^there should be exactly (?P<count>\d+) Vektra discussion iframes?$/
     * @param int $count
     */
    public function there_should_be_iframes(int $count): void {
        $found = count($this->getSession()->getPage()->findAll('css', 'iframe.local-vektradiscuss-iframe'));
        if ($found !== $count) {
            throw new ExpectationException("{$found} discussion iframes, expected {$count}", $this->getSession());
        }
    }

    /**
     * No token minted by the stub appears anywhere in the page (HTML, attributes, inline scripts).
     *
     * @Then /^no Vektra assess stub token should appear in the page$/
     */
    public function no_stub_token_in_the_page(): void {
        $html = $this->getSession()->getPage()->getContent();
        if (preg_match('/stub\.\d+\.[0-9a-f]{24}/', $html, $m)) {
            throw new ExpectationException("A stub token appears in the page: {$m[0]}", $this->getSession());
        }
    }

    /**
     * Press Tab from the top of the page until a script returns true.
     *
     * @param string $check Script returning a boolean.
     * @param string $what Name of the target for the failure message.
     */
    private function tab_until(string $check, string $what): void {
        $this->evaluate_script('document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0);');
        for ($i = 0; $i < self::MAX_TABS; $i++) {
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
            if ($this->evaluate_script($check)) {
                return;
            }
        }
        throw new ExpectationException("{$what} was not reached with " . self::MAX_TABS . ' Tab presses', $this->getSession());
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
        set_config(self::STUB_STATE . $this->cm($idnumber)->id, json_encode($fixture), 'local_vektradiscuss');
    }

    /**
     * The course module of an activity idnumber.
     *
     * @param string $idnumber
     * @return \stdClass
     */
    private function cm(string $idnumber): \stdClass {
        $cm = $this->get_course_module_for_identifier($idnumber);
        if (!$cm) {
            throw new ExpectationException("No activity with idnumber {$idnumber}", $this->getSession());
        }
        return $cm;
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
