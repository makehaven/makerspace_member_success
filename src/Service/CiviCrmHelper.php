<?php

namespace Drupal\makerspace_member_success\Service;

use Drupal\civicrm\Civicrm;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Helper service for CiviCRM interactions.
 */
class CiviCrmHelper {

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new CiviCrmHelper object.
   */
  public function __construct(Civicrm $civicrm, LoggerChannelFactoryInterface $logger_factory) {
    $this->civicrm = $civicrm;
    $this->logger = $logger_factory->get('makerspace_member_success');
  }

  /**
   * Fetches active message templates from CiviCRM.
   *
   * @return array
   *   An array of message templates keyed by ID, with title as value.
   */
  public function getMessageTemplates(): array {
    $templates = [];
    
    if (!$this->civicrm) {
      return $templates;
    }

    try {
      $this->civicrm->initialize();
      $result = civicrm_api3('MessageTemplate', 'get', [
        'is_active' => 1,
        'options' => ['limit' => 0],
        'return' => ['id', 'msg_title'],
      ]);

      if (!empty($result['values'])) {
        foreach ($result['values'] as $id => $template) {
          $templates[$id] = $template['msg_title'];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching CiviCRM message templates: @message', ['@message' => $e->getMessage()]);
    }

    return $templates;
  }

}
