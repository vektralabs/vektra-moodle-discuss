@local @local_vektradiscuss @javascript
Feature: A student downloads their own transcript
  In order to keep a copy of my assessment discussion
  As a student
  I need to download my own transcript as a file, and never another student's

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
      | student2 | Student   | Two      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity | name     | course | idnumber |
      | quiz     | Quiz 1   | C1     | quiz1    |
      | assign   | Assign 1 | C1     | assign1  |
    And the Vektra assess stub is enabled

  Scenario Outline: The transcript link downloads Vektra's bytes as an attachment with the safe-serving headers
    Given the Vektra assess stub returns state "closed" for "<activity>" with transcripts:
      | session | available | purged |
      | 1       | yes       | no     |
    And the Vektra assess stub has the transcript of session 1 of "<activity>" for "student1" named "C1_42_7_s1_20261108.html"
    When I am on the "<activity>" Activity page logged in as student1
    Then I should see "Transcript of session 1 (download)" in the ".local-vektradiscuss-panel" "css_element"
    And the transcript link of session 1 should download the stub transcript as "C1_42_7_s1_20261108.html"
    And the Vektra assess stub received 1 download-token request for session 1 of "<activity>"

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario: A file name outside the BR-016 pattern is replaced by a fallback name
    Given the Vektra assess stub returns state "closed" for "quiz1" with transcripts:
      | session | available | purged |
      | 1       | yes       | no     |
    And the Vektra assess stub has the transcript of session 1 of "quiz1" for "student1" named "../evil name.html"
    When I am on the "quiz1" Activity page logged in as student1
    Then the transcript link of session 1 should download the stub transcript as "transcript_s1.html"

  Scenario: A transcript Vektra does not serve to this student gives the generic error and no byte
    Given the Vektra assess stub returns state "closed" for "quiz1" with transcripts:
      | session | available | purged |
      | 1       | yes       | no     |
    And the Vektra assess stub has the transcript of session 1 of "quiz1" for "student2" named "C1_42_8_s1_20261108.html"
    When I am on the "quiz1" Activity page logged in as student1
    Then the transcript link of session 1 should fail with "The transcript cannot be downloaded right now. Try again later."
    And the Vektra assess stub received 0 transcript requests for session 1 of "quiz1"

  Scenario: A transcript not generated yet asks to try again
    Given the Vektra assess stub returns state "closed" for "quiz1" with transcripts:
      | session | available | purged |
      | 1       | yes       | no     |
    When I am on the "quiz1" Activity page logged in as student1
    Then the transcript link of session 1 should fail with "The transcript is not ready yet. Try again in a minute."

  Scenario: Unavailable and purged transcripts are listed without a link, also after a granted reset
    Given the Vektra assess stub returns state "reset_granted" for "quiz1" with transcripts:
      | session | available | purged |
      | 1       | no        | no     |
      | 2       | no        | yes    |
    When I am on the "quiz1" Activity page logged in as student1
    Then I should see "Transcript of session 1: not available at the moment." in the ".local-vektradiscuss-panel" "css_element"
    And I should see "Transcript of session 2: no longer available (retention period expired)." in the ".local-vektradiscuss-panel" "css_element"
    And the transcript of session 1 should not be downloadable
    And the transcript of session 2 should not be downloadable
    And the ".local-vektradiscuss-panel" "css_element" should meet accessibility standards
