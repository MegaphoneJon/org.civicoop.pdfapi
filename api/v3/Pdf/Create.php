<?php

/**
 * Pdf.Create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_pdf_create_spec(&$spec) {
  $spec['template_id']['api.required'] = 1;
  $spec['to_email']['api.required'] = 1;
  $spec['contact_id'] = array(
    'name' => 'contact_id',
    'title' => 'contact ID',
    'description' => 'ID of the CiviCRM contact',
    'api.required' => 1,
    'type' => CRM_Utils_Type::T_INT,
  );
  $spec['template_id'] = array(
    'name' => 'template_id',
    'title' => 'template ID to be used for the PDF',
    'description' => 'ID of the template that will be used for the PDF',
    'api.required' => 1,
    'type' => CRM_Utils_Type::T_INT,
  );
  $spec['to_email'] = array(
    'name' => 'to_email',
    'title' => 'to email address',
    'description' => 'the e-mail address the PDF will be sent to',
    'api.required' => 1,
    'type' => CRM_Utils_Type::T_STRING,
  );
  $spec['body_template_id'] = array(
    'name' => 'body_template_id',
    'title' => 'template ID email body',
    'description' => 'ID of the template that will be used for the email body',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_INT,
  );
  $spec['email_subject'] = array(
    'name' => 'email_subject',
    'title' => 'Email subject',
    'description' => 'Subject of the email that sends the PDF',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_STRING,
  );
}

/**
 * Pdf.Create API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_pdf_create($params) {
  $domain  = CRM_Core_BAO_Domain::getDomain();
  $version = CRM_Core_BAO_Domain::version();
  $html    = array();

  if (!preg_match('/[0-9]+(,[0-9]+)*/i', $params['contact_id'])) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $contactIds = explode(",", $params['contact_id']);

  // Compatibility with CiviCRM > 4.3
  if($version >= 4.4) {
    $messageTemplates = new CRM_Core_DAO_MessageTemplate();
  } else {
    $messageTemplates = new CRM_Core_DAO_MessageTemplates();
  }

  $messageTemplates->id = $params['template_id'];
  if (!$messageTemplates->find(TRUE)) {
    throw new API_Exception('Could not find template with ID: ' . $params['template_id']);
  }

  // Optional pdf_format_id, if not default 0
  if (isset($params['pdf_format_id'])) {
    $messageTemplates->pdf_format_id = CRM_Utils_Array::value('pdf_format_id', $params, 0);
  }
  $subject = $messageTemplates->msg_subject;
  $htmlTemplate = _civicrm_api3_pdf_formatMessage($messageTemplates);

  $tokens = CRM_Utils_Token::getTokens($htmlTemplate);

  // Optional template_email_id, if not default 0
  $templateEmailId = CRM_Utils_Array::value('body_template_id', $params, 0);

  if ($templateEmailId) {
    if($version >= 4.4) {
      $messageTemplatesEmail = new CRM_Core_DAO_MessageTemplate();
    } else {
      $messageTemplatesEmail = new CRM_Core_DAO_MessageTemplates();
    }
    $messageTemplatesEmail->id = $templateEmailId;
    if (!$messageTemplatesEmail->find(TRUE)) {
      throw new API_Exception('Could not find template with ID: ' . $templateEmailId);
    }
    $htmlMessageEmail = $messageTemplatesEmail->msg_html;
    if (isset($params['email_subject']) && !empty($params['email_subject'])) {
      $emailSubject = $params['email_subject'];
    }
    else {
      $emailSubject = $messageTemplatesEmail->msg_subject;
    }
    $tokensEmail = CRM_Utils_Token::getTokens($htmlMessageEmail);
  }

  // get replacement text for these tokens
  $returnProperties = array(
      'sort_name' => 1,
      'email' => 1,
      'address' => 1,
      'do_not_email' => 1,
      'is_deceased' => 1,
      'on_hold' => 1,
      'display_name' => 1,
  );
  if (isset($messageToken['contact'])) {
    foreach ($messageToken['contact'] as $key => $value) {
      $returnProperties[$value] = 1;
    }
  }

  foreach($contactIds as $contactId){
    $htmlMessage = $htmlTemplate;
    list($details) = CRM_Utils_Token::getTokenDetails(array($contactId), $returnProperties, false, false, null, $tokens);
    $contact = reset( $details );
    if (isset($contact['do_not_mail']) && $contact['do_not_mail'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because DO NOT MAIL is set');
      else
        continue;
    }
    if (isset($contact['is_deceased']) && $contact['is_deceased'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because contact is deceased');
      else
        continue;
    }
    if (isset($contact['on_hold']) && $contact['on_hold'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because contact is on hold');
      else
        continue;
    }

    // call token hook
    $hookTokens = array();
    CRM_Utils_Hook::tokens($hookTokens);
    $categories = array_keys($hookTokens);

    CRM_Utils_Token::replaceGreetingTokens($htmlMessage, NULL, $contact['contact_id']);
    $htmlMessage = CRM_Utils_Token::replaceDomainTokens($htmlMessage, $domain, TRUE, $tokens, TRUE);
    $htmlMessage = CRM_Utils_Token::replaceContactTokens($htmlMessage, $contact, FALSE, $tokens, FALSE, TRUE);
    $htmlMessage = CRM_Utils_Token::replaceComponentTokens($htmlMessage, $contact, $tokens, TRUE);
    $htmlMessage = CRM_Utils_Token::replaceHookTokens($htmlMessage, $contact , $categories, TRUE);
    if (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY) {
      $smarty = CRM_Core_Smarty::singleton();
      // also add the contact tokens to the template
      $smarty->assign_by_ref('contact', $contact);
      $htmlMessage = $smarty->fetch("string:$htmlMessage");
    }

    $html[] = $htmlMessage;

    if ($templateEmailId) {
      CRM_Utils_Token::replaceGreetingTokens($htmlMessageEmail, NULL, $contact['contact_id']);
      $htmlMessageEmail = CRM_Utils_Token::replaceDomainTokens($htmlMessageEmail, $domain, TRUE, $tokensEmail, TRUE);
      $htmlMessageEmail = CRM_Utils_Token::replaceContactTokens($htmlMessageEmail, $contact, FALSE, $tokensEmail, FALSE, TRUE);
      $htmlMessageEmail = CRM_Utils_Token::replaceComponentTokens($htmlMessageEmail, $contact, $tokensEmail, TRUE);
      $htmlMessageEmail = CRM_Utils_Token::replaceHookTokens($htmlMessageEmail, $contact , $categories, TRUE);
      if (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY) {
        $smarty = CRM_Core_Smarty::singleton();
        // also add the contact tokens to the template
        $smarty->assign_by_ref('contact', $contact);
        $htmlMessageEmail = $smarty->fetch("string:$htmlMessageEmail");
      }
    }
    else {
      $htmlMessageEmail = "CiviCRM has generated a PDF letter";
    }

    if (isset($emailSubject) && !empty($emailSubject)) {
        $emailSubject = CRM_Utils_Token::replaceDomainTokens($emailSubject, $domain, TRUE, $tokensEmail, TRUE);
        $emailSubject = CRM_Utils_Token::replaceContactTokens($emailSubject, $contact, FALSE, $tokensEmail, FALSE, TRUE);
        $emailSubject = CRM_Utils_Token::replaceComponentTokens($emailSubject, $contact, $tokensEmail, TRUE);
        $emailSubject = CRM_Utils_Token::replaceHookTokens($emailSubject, $contact , $categories, TRUE);
    }
    else {
        $emailSubject = 'PDF Letter from Civicrm - ' . $messageTemplates->msg_title;
    }

    //create activity
    try {
      $activityTypeId = civicrm_api3('OptionValue', 'getvalue', array(
        'option_group_id' => 'activity_type',
        'name' => 'Print PDF Letter',
        'return' => 'value',
      ));
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::debug_log_message(ts('Could not find an activity type with name Print PDF Letter in ')
        . __METHOD__ . ', no activity for sending PDF created');
    }
    if ($activityTypeId) {
      $activityParams = array(
        'source_contact_id' => $contactId,
        'activity_type_id' => $activityTypeId,
        'activity_date_time' => date('YmdHis'),
        'details' => $htmlMessage,
        'subject' => $subject,
      );
      $activity = CRM_Activity_BAO_Activity::create($activityParams);
    }
    // Compatibility with CiviCRM >= 4.4
    if($version >= 4.4){
      $activityContacts = CRM_Core_OptionGroup::values('activity_contacts', FALSE, FALSE, FALSE, NULL, 'name');
      $targetId = CRM_Utils_Array::key('Activity Targets', $activityContacts);
      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'contact_id' => $contactId,
        'record_type_id' => $targetId,
      );
      CRM_Activity_BAO_ActivityContact::create($activityTargetParams);
    }
    else{
      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'target_contact_id' => $contactId,
      );
      CRM_Activity_BAO_Activity::createActivityTarget($activityTargetParams);
    }
  }
  $fileName = CRM_Utils_String::munge($messageTemplates->msg_title) . '.pdf';
  $pdf = CRM_Utils_PDF_Utils::html2pdf($html, $fileName, TRUE, $messageTemplates->pdf_format_id);
  $tmpFileName = CRM_Utils_File::tempnam();
  file_put_contents($tmpFileName, $pdf);
  unset($pdf); //we don't need the temp file in memory

  //send PDF to e-mail address
  $from = CRM_Core_BAO_Domain::getNameAndEmail();
  $from = "$from[0] <$from[1]>";
  // set up the parameters for CRM_Utils_Mail::send
  $mailParams = array(
    'groupName' => 'PDF Letter API',
    'from' => $from,
    'toName' => $from[0],
    'toEmail' => $params['to_email'],
    'subject' => $emailSubject,
    'html' => $htmlMessageEmail,
    'attachments' => array(
        array(
            'fullPath' => $tmpFileName,
            'mime_type' => 'application/pdf',
            'cleanName' => $fileName,
        )
    )
  );
  $result = CRM_Utils_Mail::send($mailParams);
  if (!$result) {
    throw new API_Exception('Error sending e-mail to '.$params['to_email']);
  }

  $returnValues = array();
  return civicrm_api3_create_success($returnValues, $params, 'Pdf', 'Create');
}

function _civicrm_api3_pdf_formatMessage($messageTemplates){
  $htmlMessage = $messageTemplates->msg_html;

  //time being hack to strip '&nbsp;'
  //from particular letter line, CRM-6798
  $newLineOperators = array(
      'p' => array(
          'oper' => '<p>',
          'pattern' => '/<(\s+)?p(\s+)?>/m',
      ),
      'br' => array(
          'oper' => '<br />',
          'pattern' => '/<(\s+)?br(\s+)?\/>/m',
      ),
  );
  $htmlMsg = preg_split($newLineOperators['p']['pattern'], $htmlMessage);
  foreach ($htmlMsg as $k => & $m) {
    $messages = preg_split($newLineOperators['br']['pattern'], $m);
    foreach ($messages as $key => & $msg) {
      $msg = trim($msg);
      $matches = array();
      if (preg_match('/^(&nbsp;)+/', $msg, $matches)) {
        $spaceLen = strlen($matches[0]) / 6;
        $trimMsg = ltrim($msg, '&nbsp; ');
        $charLen = strlen($trimMsg);
        $totalLen = $charLen + $spaceLen;
        if ($totalLen > 100) {
          $spacesCount = 10;
          if ($spaceLen > 50) {
            $spacesCount = 20;
          }
          if ($charLen > 100) {
            $spacesCount = 1;
          }
          $msg = str_repeat('&nbsp;', $spacesCount) . $trimMsg;
        }
      }
    }
    $m = implode($newLineOperators['br']['oper'], $messages);
  }
  $htmlMessage = implode($newLineOperators['p']['oper'], $htmlMsg);

  return $htmlMessage;
}
