<?php

namespace Drupal\makerspace_member_success\Service;

/**
 * Manages lifecycle of outreach queue records.
 */
interface OutreachQueueServiceInterface {

  /**
   * Adds a member snapshot to the outreach queue.
   */
  public function enqueueCandidate(int $uid, string $stage, array $snapshot): int;

  /**
   * Approves a queued action for sending.
   */
  public function approve(
    int $queueId,
    int $staffUid,
    ?string $channel = NULL,
    ?string $templateId = NULL,
    ?string $overrideReason = NULL
  ): void;

  /**
   * Marks a queued action as suppressed.
   */
  public function suppress(int $queueId, string $reasonCode): void;

  /**
   * Marks a queued action as sent.
   */
  public function markSent(int $queueId, array $providerMeta = []): void;

  /**
   * Marks a queued action as failed.
   */
  public function markFailed(int $queueId, string $failureCode, string $message): void;

}

