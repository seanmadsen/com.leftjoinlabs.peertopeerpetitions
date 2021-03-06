<?php

/**
 * This is a helper class to break out code that needs to be called from hooks
 * within this extension.
 */

namespace Civi\Peertopeerpetitions\Campaign\Form;

use \Civi\Peertopeerpetitions\PCP\BAO\PCPBlock;

class PetitionFormModifier {

  /**
   * Array key for the "surveyId" value that we need to store in $GLOBALS
   * The prefix helps avoid name collisions
   */
  const GLOBAL_KEY_SURVEY_ID = 'peertopeerpetitions_surveyId';

  /**
   * Add this prefix to all the form elements that we're injecting into this
   * form. This helps avoid name collisions with the standard survey form
   * elements (e.g `is_active`)
   *
   * IMPORTANT: This prefix is hard-coded into this template:
   *   templates/CRM/PCP/Form/Petition.tpl
   */
  const PREFIX = 'pcp_block_';

  /**
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @throws \HTML_QuickForm_Error
   */
  public static function buildForm(&$form) {
    $acceptableActions = [
      \CRM_Core_Action::ADD,
      \CRM_Core_Action::UPDATE,
      NULL
    ];
    $actionIsAcceptable = in_array($form->_action, $acceptableActions);
    if ($actionIsAcceptable) {
      self::addFormElements($form);
      self::setDefaults($form);

      // insert a template block in the page
      \CRM_Core_Region::instance('page-body')->add(array(
        'template' => "CRM/PCP/Form/Petition.tpl",
      ));
    }
  }

  /**
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @throws \HTML_QuickForm_Error
   */
  protected static function addFormElements(&$form) {
    // Checkbox to enable PCPs
    $form->addElement(
      'checkbox',
      self::PREFIX . 'is_active',
      ts('Enable Personal Campaign Pages? (for this petition)'),
      NULL,
      array('onclick' => "return showHideByValue('" . self::PREFIX . "is_active',true,'pcpFields','table-row','radio',false);")
    );

    // Checkbox to make approval required
    $form->addElement(
      'checkbox',
      self::PREFIX . 'is_approval_needed',
      ts('Approval required')
    );

    // Select element to choose a profile
    $form->add(
      'select',
      self::PREFIX . 'supporter_profile_id',
      ts('Supporter Profile'),
      array('' => ts('- select -')) + self::getProfiles($form),
      FALSE
    );

    // Radio buttons for notification setting
    $form->addRadio(
      self::PREFIX . 'owner_notify_id',
      ts('Owner Email Notification'),
      \CRM_Core_OptionGroup::values('pcp_owner_notify'),
      NULL,
      '<br/>',
      FALSE
    );

    // Email address for notifications
    $form->add(
      'text',
      self::PREFIX . 'notify_email',
      ts('Notify Email'),
      \CRM_Core_DAO::getAttribute('CRM_PCP_DAO_PCPBlock', 'notify_email')
    );

    // Text for the link to create new PCPs
    $form->add(
      'text',
      self::PREFIX . 'link_text',
      ts("'Create Personal Campaign Page' link text"),
      \CRM_Core_DAO::getAttribute('CRM_PCP_DAO_PCPBlock', 'link_text')
    );

  }

  /**
   * Adds more default values to the form so that it loads with data in the
   * pcp_block fields if a corresponding pcp_block already exists
   *
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @throws \HTML_QuickForm_Error
   */
  protected static function setDefaults(&$form) {
    $blankFormValues = [
      'is_approval_needed' => 0,
      'link_text' => ts('Promote this survey with a personal campaign page'),
      'owner_notify_id' => \CRM_Core_OptionGroup::getDefaultValue('pcp_owner_notify'),
      'is_active' => 0,
    ];

    $surveyId = self::getSurveyId($form);

    if ($surveyId) {
      $defaults = PCPBlock::getValuesBySurveyId($surveyId);
    }
    else {
      $defaults = $blankFormValues;
    }

    // Add form element prefix to avoid name collisions
    $defaults = self::prefixKeys($defaults);

    $form->setDefaults($defaults);
    $a = 0;
  }

  /**
   * Return $params, but with the array keys prefixed by the special prefix
   * we use to avoid name collisions for form elements
   *
   * @param array $params
   *
   * @return array
   */
  protected static function prefixKeys($params) {
    $result = [];
    foreach ($params as $k => $v) {
      $result[self::PREFIX . $k] = $v;
    }
    return $result;
  }

  /**
   * Return $params, with elements removed that are not prefixed, and with the
   * prefixes removed from the elements that do have them
   * 
   * @param array $params
   *
   * @return array
   */
  protected static function unPrefixKeys ($params) {
    $result = [];
    foreach ($params as $key => $v) {
      $pattern = '/^' . self::PREFIX . '/';
      if (preg_match($pattern, $key)) {
        $newKey = preg_replace($pattern, '', $key);
        $result[$newKey] = $v;
      }
    }
    return $result;
  }

  /**
   * This function does some magic to retrieve the list of profiles needed for
   * the form element where the user chooses a profile. I copied this code
   * from \CRM_PCP_BAO_PCP::buildPCPForm. I don't understand the difference
   * between $profile and $profiles in this function, but oh well!
   *
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @return array
   */
  protected static function getProfiles($form) {
    $profile = [];
    $isUserRequired = NULL;
    $config = \CRM_Core_Config::singleton();
    if ($config->userFramework != 'Standalone') {
      $isUserRequired = 2;
    }
    \CRM_Core_DAO::commonRetrieveAll(
      'CRM_Core_DAO_UFGroup',
      'is_cms_user',
      $isUserRequired,
      $profiles, array(
        'title',
        'is_active',
      )
    );
    if (!empty($profiles)) {
      foreach ($profiles as $key => $value) {
        if ($value['is_active']) {
          $profile[$key] = $value['title'];
        }
      }
      $form->assign('profile', $profile);
    }
    return $profile;
  }

  /**
   * Update a pcp_block entity after a survey is updated.
   *
   * Much of this code is mostly copied from
   * \CRM_PCP_Form_Contribute::postProcess
   *
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @throws \CRM_Core_Exception
   *   If we can't find surveyId
   */
  public static function postProcess(&$form) {
    $surveyId = self::getSurveyId($form);

    if (empty($surveyId)) {
      throw new \CRM_Core_Exception('Unable to determine ID of survey while trying to update a pcp_block associated with a survey.');
    }

    // get the submitted form values.
    $params = $form->controller->exportValues($form->getVar('_name'));

    // Remove the prefix added to the names of form elements that we injected
    $params = self::unPrefixKeys($params);

    // Source
    $params['entity_table'] = 'civicrm_survey';
    $params['entity_id'] = $surveyId;

    // Target
    $params['target_entity_type'] = 'civicrm_survey';
    $params['target_entity_id'] = $surveyId;

    $dao = new \CRM_PCP_DAO_PCPBlock();
    $dao->entity_table = $params['entity_table'];
    $dao->entity_id = $params['entity_id'];
    $dao->find(TRUE);
    $params['id'] = $dao->id;
    $params['is_active'] = \CRM_Utils_Array::value('is_active', $params, FALSE);
    $params['is_approval_needed'] = \CRM_Utils_Array::value('is_approval_needed', $params, FALSE);
    $params['is_tellfriend_enabled'] = 0;

    if ($form->_action == \CRM_Core_Action::DELETE) {
      $dao->delete();
    }
    else {
      \CRM_PCP_BAO_PCPBlock::create($params);
    }
  }

  /**
   * Get the ID of a survey after it has been updated so that we can update
   * the pcp_block in hook_civicrm_postProcess.
   *
   * When the form is saved to create or update a Survey entity,
   * hook_civicrm_post fires before hook_civicrm_postProcess. We get the form
   * values in hook_civicrm_postProcess, but in the case of creating a new
   * survey, we don't get the survey ID in hook_civicrm_postProcess. So we grab
   * the ID here and store it globally to access it from
   * hook_civicrm_postProcess later on.
   *
   * @param int $surveyId
   */
  public static function post($surveyId) {
    self::setSurveyId($surveyId);
  }

  /**
   * Implements hook_civicrm_validateForm().
   *
   * @param array $fields
   * @param \CRM_Campaign_Form_Petition $form
   * @param array $errors
   */
  public static function validate(&$fields, &$form, &$errors) {
    if (empty($fields['pcp_active']) || $fields['pcp_active'] != 1) {
      return;
    }

    // Require a profile to be chosen, and make sure the profile has an email address
    if (empty($fields['supporter_profile_id'])) {
      $errors['supporter_profile_id'] = ts('Supporter profile is a required field.');
    }
    else {
      if (\CRM_PCP_BAO_PCP::checkEmailProfile($fields['supporter_profile_id'])) {
        $errors['supporter_profile_id'] = ts('Profile is not configured with Email address.');
      }
    }

    // Require an owner notification strategy
    if (empty($fields['owner_notify_id'])) {
      $errors['owner_notify_id'] = ts('Owner Email Notification is a required field.');
    }

    // Require a valid notification email addresses
    $emails = \CRM_Utils_Array::value('notify_email', $fields);
    if (!empty($emails)) {
      $emailArray = explode(',', $emails);
      foreach ($emailArray as $email) {
        if ($email && !\CRM_Utils_Rule::email(trim($email))) {
          $errors['notify_email'] = ts('A valid Notify Email address must be specified');
        }
      }
    }

  }

  /**
   * @see \Civi\Peertopeerpetitions\Campaign\Form\PetitionFormModifier::getSurveyId
   *
   * @param int $surveyId
   */
  protected static function setSurveyId($surveyId) {
    $GLOBALS[self::GLOBAL_KEY_SURVEY_ID] = $surveyId;
  }

  /**
   * Look in multiple places to find the ID of the survey we're updating, so
   * that we can use this ID update a pcp_block. We need to do this weird logic
   * here because, depending on the survey action (create/update/delete),
   * the survey ID will be in different places.
   *
   * In general:
   * - We want to update the pcp_block from within hook_civicrm_postProcess
   *   because that's where we get the form values for the pcp_block fields.
   *
   * When creating a survey:
   * - We need to use the global var because the form doesn't supply the ID
   *   for the survey
   *
   * When deleting a survey:
   * - We need to use $form->_surveyId because hook_civicrm_postProcess runs
   *   before hook_civicrm_post (so the global var won't have been set yet).
   *
   * When updating a survey:
   * - ??
   *
   * Set the ID of the saved survey in this hacky global var. This is because
   * we can't reliably get the ID from within hook_civicrm_postProcess (where
   * we need it) because the form won't have an ID if it's a new survey. So
   * we grab the ID from hook_civicrm_post and save it globally.
   *
   * @param \CRM_Campaign_Form_Petition $form
   *
   * @return int|null
   */
  protected static function getSurveyId($form = NULL) {

    // If we're deleting a survey, then we need t
    if (!empty($form->_surveyId)) {
      return (int) $form->_surveyId;
    }

    if (!empty($GLOBALS[self::GLOBAL_KEY_SURVEY_ID])) {
      return $GLOBALS[self::GLOBAL_KEY_SURVEY_ID];
    }

    return NULL;
  }

}
