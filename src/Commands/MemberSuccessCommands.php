<?php

namespace Drupal\makerspace_member_success\Commands;

use Drush\Commands\DrushCommands;
use Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Drush commands for Makerspace Member Success.
 */
class MemberSuccessCommands extends DrushCommands {

  /**
   * The snapshot builder.
   *
   * @var \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder
   */
  protected $snapshotBuilder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MemberSuccessCommands object.
   *
   * @param \Drupal\makerspace_member_success\Service\MemberSuccessSnapshotBuilder $snapshot_builder
   *   The snapshot builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(MemberSuccessSnapshotBuilder $snapshot_builder, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->snapshotBuilder = $snapshot_builder;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Builds member success snapshots.
   *
   * @param string|null $uid
   *   Optional user ID to build a snapshot for. If omitted, builds for all active members.
   *
   * @command ms-snapshot:build
   * @aliases ms-build
   * @usage ms-snapshot:build
   *   Builds snapshots for all active members.
   * @usage ms-snapshot:build 123
   *   Builds snapshot for user 123.
   */
  public function build($uid = NULL) {
    if ($uid) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user) {
        $this->logger()->error(dt('User @uid not found.', ['@uid' => $uid]));
        return;
      }
      
      $this->logger()->notice(dt('Building snapshot for user @uid...', ['@uid' => $uid]));
      $date = new \DateTimeImmutable('now');
      $snapshot_date = $date->format('Y-m-d');
      $now_ts = time();
      
      $row = $this->snapshotBuilder->buildSnapshotForUser((int) $uid, $snapshot_date, 'daily', $now_ts);
      $this->snapshotBuilder->upsertSnapshot($row);
      
      $this->logger()->success(dt('Built snapshot for user @uid.', ['@uid' => $uid]));
    }
    else {
      $this->logger()->notice(dt('Building daily snapshots for all active members...'));
      $count = $this->snapshotBuilder->buildDailySnapshots();
      $this->logger()->success(dt('Built @count snapshots.', ['@count' => $count]));
    }
  }

}
