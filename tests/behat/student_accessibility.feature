@local @local_vektradiscuss @javascript @accessibility
Feature: Accessibility of the student entry point
  In order to discuss an assessment whatever device or assistive technology I use
  As a student
  I need the discussion button, the pre-session screen and the discussion container to be keyboard operable,
  to reflow on a narrow screen and to meet the WCAG 2.1 AA checks

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

  Scenario Outline: Keyboard only: Tab reaches the button, Enter opens the pre-session screen, Escape closes it
    Given the Vektra assess stub returns state "eligible" for "<activity>"
    And I am on the "<activity>" Activity page logged in as student1
    And I should see "You can discuss this assessment with the assistant." in the ".local-vektradiscuss-panel" "css_element"
    And the ".local-vektradiscuss-panel" "css_element" should meet accessibility standards
    When I tab to the Vektra discussion button "Discuss this assessment"
    And I press the enter key
    Then I should see "Before you start" in the ".modal-dialog" "css_element"
    And the ".modal-dialog" "css_element" should meet accessibility standards
    And I press the escape key
    And ".modal-dialog" "css_element" should not be visible
    And the focused element is "Discuss this assessment" "button"
    And the Vektra assess stub received 0 requests to "/api/v1/assess/sessions"

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario Outline: A disabled button is reached with Tab and its reason meets the checks
    Given the Vektra assess stub returns state "<state>" for "quiz1"
    And I am on the "quiz1" Activity page logged in as student1
    And I should see "<text>" in the ".local-vektradiscuss-panel" "css_element"
    When I tab to the Vektra discussion button "Discuss this assessment"
    Then the Vektra discussion button "Discuss this assessment" should be disabled with a reason
    And the ".local-vektradiscuss-panel" "css_element" should meet accessibility standards

    Examples:
      | state       | text                                   |
      | not_graded  | Available when the grade is published. |
      | unavailable | The discussion is temporarily unavailable. |

  # axe cannot scan the cross-origin iframe here (the stub UI host does not resolve, so the run returns no
  # data), and Tab skips a frame whose document has nothing focusable; the iframe content is the Vektra UI's own
  # accessibility scope. The container is checked for what this plugin owns: the frame's accessible name (axe
  # rule frame-title) and that it is not removed from the tab order or the accessibility tree.
  Scenario: The discussion iframe has an accessible name
    Given the Vektra assess stub returns state "eligible" for "quiz1"
    And I am on the "quiz1" Activity page logged in as student1
    And I click on "Discuss this assessment" "button" in the ".local-vektradiscuss-panel" "css_element"
    And I click on "Start" "button" in the ".modal-dialog" "css_element"
    When I wait until "iframe.local-vektradiscuss-iframe" "css_element" exists
    Then the Vektra discussion iframe should have the accessible name "Assessment discussion"

  Scenario Outline: The panel and the pre-session screen reflow at 320 CSS pixels (200% and 400% zoom)
    Given I change viewport size to "320x640"
    And the Vektra assess stub returns state "<state>" for "<activity>"
    And I am on the "<activity>" Activity page logged in as student1
    And I should see "<text>" in the ".local-vektradiscuss-panel" "css_element"
    Then the ".local-vektradiscuss-panel" region should reflow without horizontal scrolling

    Examples:
      | activity | state       | text                                               |
      | quiz1    | eligible    | You can discuss this assessment with the assistant. |
      | assign1  | eligible    | You can discuss this assessment with the assistant. |
      | quiz1    | active      | 37 minutes left                                    |
      | quiz1    | unavailable | The discussion is temporarily unavailable.         |

  Scenario: The pre-session screen reflows at 320 CSS pixels
    Given I change viewport size to "320x640"
    And the Vektra assess stub returns state "eligible" for "quiz1"
    And I am on the "quiz1" Activity page logged in as student1
    When I click on "Discuss this assessment" "button" in the ".local-vektradiscuss-panel" "css_element"
    Then I should see "Before you start" in the ".modal-dialog" "css_element"
    And the ".modal-dialog" region should reflow without horizontal scrolling
