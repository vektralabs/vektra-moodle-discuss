@local @local_vektradiscuss
Feature: Vektra assessment discussion settings
  In order to keep the exam evidence keys separate from the Vektra admin key
  As an admin
  I need the settings page to refuse the block admin key

  Background:
    Given the following config values are set as admin:
      | apikey | block-admin-key-for-behat | block_vektra |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Vektra assessment discussion" in site administration

  Scenario: The block admin key is refused as the mint key
    When I set the field "Mint key (assess:mint)" to "block-admin-key-for-behat"
    And I press "Save changes"
    Then I should see "This is the Vektra AI Assistant block admin key"

  Scenario: A dedicated key is accepted and the plugin stays disabled by default
    When I set the field "Mint key (assess:mint)" to "dedicated-mint-key-for-behat"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Enable assessment discussions" matches value ""
