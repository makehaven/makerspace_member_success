<?php

namespace Drupal\makerspace_member_success\Commands;

use Drush\Commands\DrushCommands;
use Drupal\makerspace_member_success\Service\MemberSuccessScenarioSimulator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Drush commands for Member Success Scenario Simulation.
 */
class ScenarioCommands extends DrushCommands {

  protected MemberSuccessScenarioSimulator $simulator;
  protected Connection $database;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    MemberSuccessScenarioSimulator $simulator,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct();
    $this->simulator = $simulator;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Lists available personas for scenario simulation.
   *
   * @command ms:scenario-list
   * @aliases ms-scen-list
   */
  public function listPersonas(): void {
    $personas = $this->simulator->getPersonas();
    $rows = [];
    foreach ($personas as $id => $p) {
      $rows[] = [$id, $p['label'], $p['description']];
    }
    $this->io()->table(['ID', 'Label', 'Description'], $rows);
  }

  /**
   * Creates a test user based on a persona.
   *
   * @param string $personaName
   *   The ID of the persona to create.
   *
   * @command ms:scenario-create
   * @aliases ms-scen-create
   */
  public function createPersona(string $personaName): void {
    try {
      $uid = $this->simulator->createTestUser($personaName);
      $this->logger()->success(dt('Created test user @uid for persona @persona.', [
        '@uid' => $uid,
        '@persona' => $personaName,
      ]));
      $this->status($uid);
    } catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Creates one test user for every available persona.
   *
   * @command ms:scenario-bulk
   * @aliases ms-scen-bulk
   */
  public function bulkCreate(): void {
    $personas = $this->simulator->getPersonas();
    foreach (array_keys($personas) as $id) {
      try {
        $uid = $this->simulator->createTestUser($id);
        $this->logger()->success(dt('Created user @uid for persona @persona.', [
          '@uid' => $uid,
          '@persona' => $id,
        ]));
      } catch (\Exception $e) {
        $this->logger()->error($id . ': ' . $e->getMessage());
      }
    }
  }

  /**
   * Advances time for a test user.
   *
   * @param int $uid
   *   The Drupal user ID.
   * @param int $days
   *   Number of days to advance.
   *
   * @command ms:scenario-advance
   * @aliases ms-scen-advance
   */
  public function advance(int $uid, int $days): void {
    try {
      $this->simulator->advanceTime($uid, $days);
      $this->logger()->success(dt('Advanced time by @days days for user @uid.', [
        '@uid' => $uid,
        '@days' => $days,
      ]));
      $this->status($uid);
    } catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Shows the current status of a test user in the member success system.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @command ms:scenario-status
   * @aliases ms-scen-status
   */
  public function status(int $uid): void {
    $snapshot = $this->database->select('ms_member_success_snapshot', 'ms')
      ->fields('ms')
      ->condition('uid', $uid)
      ->condition('is_latest', 1)
      ->execute()
      ->fetchAssoc();

    if (!$snapshot) {
      $this->logger()->error(dt('No snapshot found for user @uid.', ['@uid' => $uid]));
      return;
    }

    $this->io()->title("Status for User $uid");
    
    $snap_rows = [];
    foreach ($snapshot as $k => $v) {
      if ($k === 'risk_reasons') {
        $v = implode(', ', @unserialize($v) ?: []);
      }
      $snap_rows[] = [$k, (string) $v];
    }
    $this->io()->section('Snapshot');
    $this->io()->table(['Field', 'Value'], $snap_rows);

    $queue = $this->database->select('ms_member_outreach_queue', 'q')
      ->fields('q')
      ->condition('uid', $uid)
      ->orderBy('id', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAllAssoc('id');

    if ($queue) {
      $this->io()->section('Outreach Queue (Latest 5)');
      $q_rows = [];
      foreach ($queue as $q) {
        $q_rows[] = [$q->id, $q->stage, $q->status, $q->recommended_channel, date('Y-m-d H:i', $q->scheduled_at)];
      }
      $this->io()->table(['ID', 'Stage', 'Status', 'Channel', 'Scheduled At'], $q_rows);
    } else {
      $this->io()->note('No outreach queue items found.');
    }
  }

  /**
   * Updates a test user's snapshot data.
   *
   * @param int $uid
   *   The Drupal user ID.
   * @param array $options
   *   JSON data to update in the snapshot.
   *
   * @option data
   *   JSON string of snapshot field updates (e.g. '{"stage":"engagement","payment_failed":0}').
   *
   * @command ms:scenario-update
   * @aliases ms-scen-update
   */
  public function update(int $uid, array $options = ['data' => '{}']): void {
    try {
      $data = json_decode($options['data'], TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Invalid JSON in --data.");
      }
      $this->simulator->updateSnapshot($uid, $data);
      $this->logger()->success(dt('Updated snapshot for user @uid.', ['@uid' => $uid]));
      $this->status($uid);
    } catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Resets simulation data for a user.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @command ms:scenario-reset
   * @aliases ms-scen-reset
   */
  public function reset(int $uid): void {
    $this->simulator->resetUser($uid);
    $this->logger()->success(dt('Reset data for user @uid.', ['@uid' => $uid]));
  }

  /**
   * Sets the monthly payment value for a test user.
   *
   * @param int $uid
   *   The Drupal user ID.
   * @param float $value
   *   Monthly payment value.
   *
   * @command ms:scenario-payment
   * @aliases ms-scen-pay
   */
  public function setPayment(int $uid, float $value): void {
    try {
      $this->simulator->setMonthlyPayment($uid, $value);
      $this->logger()->success(dt('Set monthly payment to @value for user @uid.', [
        '@uid' => $uid,
        '@value' => $value,
      ]));
    } catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Simulates a staff contact with an outcome.
   *
   * @param int $uid
   *   The Drupal user ID.
   * @param string $outcome
   *   The outcome machine name (e.g. left_message, payment_updated).
   * @param array $options
   *   Command options.
   *
   * @option method
   *   Contact method (phone, email, sms, in_person, other). Default: phone.
   * @option notes
   *   Optional notes.
   *
   * @command ms:scenario-contact
   * @aliases ms-scen-contact
   */
  public function contact(int $uid, string $outcome, array $options = ['method' => 'phone', 'notes' => 'Simulated contact']): void {
    try {
      $this->simulator->recordContact($uid, $options['method'], $outcome, $options['notes']);
      $this->logger()->success(dt('Logged contact for user @uid with outcome @outcome.', [
        '@uid' => $uid,
        '@outcome' => $outcome,
      ]));
      $this->status($uid);
    } catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Generates historical interaction data for trend testing.
   *
   * @command ms:scenario-history
   * @aliases ms-scen-hist
   */
  public function generateHistory(): void {
    $this->logger()->notice('Generating historical data for Jan/Feb 2026...');
    
    // Create some users first if they don't exist, or just use existing ones.
    // For simplicity, we'll create 5 historical users.
    $uids = [];
    for ($i = 0; $i < 5; $i++) {
      $uids[] = $this->simulator->createTestUser('engagement_fading');
    }

    $jan_dates = ['2026-01-05', '2026-01-12', '2026-01-20'];
    $feb_dates = ['2026-02-02', '2026-02-10', '2026-02-18'];

    // Jan: Low success (1 out of 5)
    foreach ($uids as $index => $uid) {
      $date = $jan_dates[$index % 3];
      $outcome = ($index === 0) ? 'payment_updated' : 'no_answer';
      $this->simulator->createHistoricalInteraction($uid, $date, 'phone', $outcome, 1);
      $this->simulator->setMonthlyPayment($uid, 50.0);
    }

    // Feb: Higher success (3 out of 5)
    foreach ($uids as $index => $uid) {
      $date = $feb_dates[$index % 3];
      $outcome = ($index < 3) ? 'payment_updated' : 'left_message';
      $this->simulator->createHistoricalInteraction($uid, $date, 'email', $outcome, 1);
    }

    $this->logger()->success('Historical data generated.');
  }
}
