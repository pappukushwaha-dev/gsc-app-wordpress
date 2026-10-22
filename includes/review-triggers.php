<?php
declare(strict_types=1);

/**
 * Review-popup trigger helpers.
 *
 * The popup opens only at strong moments (plan purchase, first report,
 * improved stats, first sitemap, action-center data, and on logins until
 * the user actually submits). Every named trigger is shown at most once
 * per store; a submitted review silences everything permanently.
 *
 * Tables: review_prompts (02-review-prompts.sql), app_reviews.
 */

if (!function_exists('review_valid_trigger')) {

    function review_valid_trigger(string $t): bool
    {
        return in_array($t, ['plan_purchased', 'first_report', 'stats_improved', 'sitemap_submitted', 'action_center'], true);
    }

    /** Has the store actually submitted a review (not just dismissed)? */
    function review_is_submitted($pdo, string $instanceId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT submitted FROM app_reviews WHERE instance_id = :id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $instanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && (int) $row['submitted'] === 1;
    }

    /** Was this trigger already shown for the store? */
    function review_trigger_taken($pdo, string $instanceId, string $trigger): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM review_prompts WHERE instance_id = :id AND trigger_name = :t LIMIT 1'
        );
        $stmt->execute([':id' => $instanceId, ':t' => $trigger]);
        return (bool) $stmt->fetchColumn();
    }

    /** Reserve the trigger (INSERT IGNORE). TRUE = first time, popup may show. */
    function review_mark_trigger($pdo, string $instanceId, string $trigger): bool
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO review_prompts (instance_id, trigger_name) VALUES (:id, :t)'
        );
        $stmt->execute([':id' => $instanceId, ':t' => $trigger]);
        return $stmt->rowCount() > 0;
    }

    /** Eligible = not already submitted + this trigger not shown yet. */
    function review_eligible($pdo, string $instanceId, string $trigger): bool
    {
        return !review_is_submitted($pdo, $instanceId)
            && !review_trigger_taken($pdo, $instanceId, $trigger);
    }
}
