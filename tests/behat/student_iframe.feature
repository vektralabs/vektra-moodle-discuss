@local @local_vektradiscuss @javascript
Feature: The discussion iframe
  In order to discuss an assessment without exposing my session token
  As a student
  I need the discussion to open in a sandboxed iframe of the Vektra UI, with no token in the page

  # The handshake itself (ready -> token with v: 1 and the mode -> closed) needs the real UI on an https origin
  # distinct from Moodle, which the CI site cannot serve; it is verified live in F1. Here: what the plugin renders
  # before the UI answers.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name     | course | idnumber |
      | quiz     | Quiz 1   | C1     | quiz1    |
      | assign   | Assign 1 | C1     | assign1  |
    And the Vektra assess stub is enabled

  Scenario Outline: Start opens the UI page of the site in a sandboxed iframe, with no token in the page
    Given the Vektra assess stub returns state "eligible" for "<activity>"
    And I am on the "<activity>" Activity page logged in as student1
    When I click on "Discuss this assessment" "button" in the ".local-vektradiscuss-panel" "css_element"
    And I click on "Start" "button" in the ".modal-dialog" "css_element"
    And I wait until "iframe.local-vektradiscuss-iframe" "css_element" exists
    Then the Vektra discussion iframe should load the UI page of the configured site
    And no Vektra assess stub token should appear in the page
    # Only the state call minted: the iframe gets its token on its ready message, never at render time.
    And the Vektra assess stub received 1 request to "/api/v1/assess/tokens"
    And the Vektra assess stub received 0 requests to "/api/v1/assess/sessions"

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario: Resume opens the same UI page, with one iframe for repeated clicks
    Given the Vektra assess stub returns state "active" for "quiz1"
    And I am on the "quiz1" Activity page logged in as student1
    When I click on "Resume" "button" in the ".local-vektradiscuss-panel" "css_element"
    And I wait until "iframe.local-vektradiscuss-iframe" "css_element" exists
    And I click on "Resume" "button" in the ".local-vektradiscuss-panel" "css_element"
    Then the Vektra discussion iframe should load the UI page of the configured site
    And there should be exactly 1 Vektra discussion iframe
    And no Vektra assess stub token should appear in the page
