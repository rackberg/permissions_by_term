<?php

namespace Drupal\permissions_by_term;

/**
 * AccessCheckService class.
 */
class AccessCheckService {

  /**
   * AccessCheckService constructor.
   */
  public function __construct($iNid) {
    $this->oUser = \Drupal::currentUser();
    if ($iNid !== NULL) {
      $this->oNode = \Drupal::entityManager()->getStorage('node')->load($iNid);
    }
  }

  /**
   * Checks if a user can access a node by given node id.
   */
  public function canUserAccessByNodeId($iNid = NULL) {
    // In case of access checking on a view.
    if ($iNid !== NULL) {
      $this->oNode = \Drupal::entityManager()->getStorage('node')->load($iNid);
    }

    $access_allowed = TRUE;
    foreach ($this->oNode->getFields() as $field) {
      if ($field->getFieldDefinition()->getType() == 'entity_reference' && $field->getFieldDefinition()->getSetting('target_type') == 'taxonomy_term') {
        $aReferencedTaxonomyTerms = $field->getValue();
        if (!empty($aReferencedTaxonomyTerms)) {
          foreach ($aReferencedTaxonomyTerms as $aReferencedTerm) {
            if (isset($aReferencedTerm['target_id']) && !$this->isAccessAllowedByDatabase($aReferencedTerm['target_id'])) {
              $access_allowed = FALSE;
            }
          }
        }
      }
    }
    return $access_allowed;
  }

  /**
   * Returns a boolean if the view is containing nodes.
   */
  public function viewContainsNode($view) {
    $bViewContainsNodes = FALSE;

    foreach ($view->result as $view_result) {
      if (array_key_exists('nid', $view_result) === TRUE) {
        $bViewContainsNodes = TRUE;
        break;
      }
    }
    return $bViewContainsNodes;
  }

  /**
   * Removes forbidden nodes from view listing.
   */
  public function removeForbiddenNodesFromView(&$view) {
    $aNodesToHideInView = array();

    // Iterate over all nodes in view.
    foreach ($view->result as $v) {

      if ($this->canUserAccessByNodeId($v->nid) === FALSE) {
        $aNodesToHideInView[] = $v->nid;
      }

    }

    $iCounter = 0;

    foreach ($view->result as $v) {
      if (in_array($v->nid, $aNodesToHideInView)) {
        unset($view->result[$iCounter]);
      }
      $iCounter++;
    }
  }

  /**
   * Implements permissions_by_term_allowed().
   *
   * This hook-function checks if a user is either allowed or not allowed to
   * access a given term.
   *
   * @param int $tid
   *   The taxonomy term id.
   *
   * @return bool
   *   Determines by boolean if access is allowed by given tid and the signed
   *   in user.
   */
  public function isAccessAllowedByDatabase($tid) {

    // Admin can access everything (user id "1").
    if ($this->oUser->id() == 1) {
      return TRUE;
    }

    $iTid = intval($tid);

    if (!$this->isAnyPermissionSetForTerm($iTid)) {
      return TRUE;
    }

    /* At this point permissions are enabled, check to see if this user or one
     * of their roles is allowed.
     */
    $aUserRoles = $this->oUser->getRoles();

    foreach ($aUserRoles as $sUserRole) {

      if ($this->isTermAllowedByUserRole($iTid, $sUserRole)) {
        return TRUE;
      }

    }

    $iUid = intval($this->oUser->id());

    if ($this->isTermAllowedByUserId($iTid, $iUid)) {
      return TRUE;
    }

    return FALSE;

  }

  /**
   * Returns a boolean if the term is allowed by given user id.
   *
   * @param int $iTid
   *   The taxonomy term id.
   * @param int $iUid
   *   The user id.
   *
   * @return bool
   *   Determines by boolean if the given term id is allowed by given user id.
   */
  public function isTermAllowedByUserId($iTid, $iUid) {

    $query_result = db_query("SELECT uid FROM {permissions_by_term_user} WHERE tid = :tid AND uid = :uid",
      array(':tid' => $iTid, ':uid' => $iUid))->fetchField();

    if (!empty($query_result)) {
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  /**
   * Returns a boolean if the term is allowed by given user role id.
   *
   * @param int $iTid
   *   The term id.
   * @param string $sUserRole
   *   The user role.
   *
   * @return bool
   *   Determines if the term is allowed by the given user role.
   */
  public function isTermAllowedByUserRole($iTid, $sUserRole) {
    $query_result = db_query("SELECT rid FROM {permissions_by_term_role} WHERE tid = :tid AND rid IN (:user_roles)",
      array(':tid' => $iTid, ':user_roles' => $sUserRole))->fetchField();

    if (!empty($query_result)) {
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  /**
   * Gets boolean for set permission on a term.
   *
   * @param int $iTid
   *   The taxonomy term id.
   *
   * @return bool
   *   Returns either TRUE or FALSE if there is any permission set for the term.
   */
  public function isAnyPermissionSetForTerm($iTid) {

    $iUserTableResults = intval(db_query("SELECT COUNT(1) FROM {permissions_by_term_user} WHERE tid = :tid",
      array(':tid' => $iTid))->fetchField());

    $iRoleTableResults = intval(db_query("SELECT COUNT(1) FROM {permissions_by_term_role} WHERE tid = :tid",
      array(':tid' => $iTid))->fetchField());

    if ($iUserTableResults > 0 ||
      $iRoleTableResults > 0) {
      return TRUE;
    }

  }

}
