<?php
/**
 * partials/first-report-banner.php  (WordPress)
 *
 * The strip at the top of the dashboard, shown while Google has not yet
 * sent a first performance report.
 *
 * Include from dashboard.php immediately after the layoutTop include:
 *
 *     <?php include __DIR__ . '/partials/first-report-banner.php'; ?>
 *
 * WHY THE HOURS ARE COMPUTED, NOT WRITTEN DOWN
 *
 * A banner that always reads "72 hours" says the same thing on day one
 * and on day four. The merchant who comes back on Thursday is told to
 * wait three days again, which is both wrong and the kind of wrong that
 * makes people stop believing the rest of the page.
 *
 * The window is measured from the moment the domain was verified, since
 * that is when Google starts collecting. What is left is worked out from
 * that, and once it has passed the banner says so plainly rather than
 * counting into the negative.
 *
 * The banner hides itself entirely once real data arrives - it reads
 * $gscSampleSections, which sample_fallback.php fills, so it cannot
 * disagree with what the page is actually showing.
 */

// The number in one place. Referenced by why-first-report.php too, so the
// two pages cannot drift apart.
if (!defined('GSC_FIRST_REPORT_HOURS')) {
    define('GSC_FIRST_REPORT_HOURS', 72);
}

$frShow = false;
$frText = '';

// Only while the page is showing sample data. Once Google has sent
// something, this strip is noise.
$frIsSample = !empty($gscSampleSections);

if ($frIsSample) {
    $frShow = true;

    // $connectedRaw is already resolved above in dashboard.php:
    // verification_verified_at, falling back to created_at.
    $frFrom = null;
    if (!empty($connectedRaw)) {
        try {
            $frFrom = new DateTimeImmutable((string)$connectedRaw);
        } catch (Throwable $e) {
            $frFrom = null;
        }
    }

    if ($frFrom) {
        $frReady   = $frFrom->modify('+' . GSC_FIRST_REPORT_HOURS . ' hours');
        $frNow     = new DateTimeImmutable('now');
        $frSeconds = $frReady->getTimestamp() - $frNow->getTimestamp();

        if ($frSeconds > 0) {
            $frHours = (int)ceil($frSeconds / 3600);

            if ($frHours >= 24) {
                $frDays = (int)ceil($frHours / 24);
                $frText = 'Your first Google performance report will load in about '
                        . $frDays . ($frDays === 1 ? ' day' : ' days') . '.';
            } else {
                $frText = 'Your first Google performance report will load in about '
                        . $frHours . ($frHours === 1 ? ' hour' : ' hours') . '.';
            }
        } else {
            // Past the window and still nothing. Saying "0 hours left" would
            // be a countdown that ended without anything happening; this is
            // the honest version, and the details page explains why it
            // happens.
            $frText = 'Google has not sent your first report yet. This can take '
                    . 'longer on sites with little search traffic.';
        }
    } else {
        // No verification date to measure from - say the general thing
        // rather than invent a start.
        $frText = 'Your first Google performance report takes up to '
                . GSC_FIRST_REPORT_HOURS . ' hours to arrive.';
    }
}

if ($frShow):
?>

<style>
/* Scoped to .fr- so nothing here reaches the rest of the page, and a
   purged Tailwind build cannot drop it. */
.fr-bar {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 16px; margin: 0 0 16px;
    border: 1px solid #fde68a; border-radius: 12px;
    background: #fffbeb;
}
.fr-ic {
    flex: none; width: 32px; height: 32px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: #fef3c7; color: #b45309; font-size: 17px;
}
.fr-body { flex: 1; min-width: 0; }
.fr-t { margin: 0; font-size: 13.5px; line-height: 1.55; color: #422006; font-weight: 600; }
.fr-s { margin: 3px 0 0; font-size: 12.5px; line-height: 1.55; color: #78350f; }
.fr-link {
    flex: none; align-self: center;
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 13px; border-radius: 8px; text-decoration: none;
    font-size: 12.5px; font-weight: 600; white-space: nowrap;
    background: #fff; border: 1px solid #fcd34d; color: #92400e;
}
.fr-link:hover { background: #fef3c7; color: #92400e; }

@media (max-width: 640px) {
    .fr-bar { flex-wrap: wrap; }
    .fr-link { width: 100%; justify-content: center; margin-top: 4px; }
}
</style>

<div class="fr-bar" role="status">
    <span class="fr-ic">
        <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
    </span>

    <div class="fr-body">
        <p class="fr-t"><?= htmlspecialchars($frText) ?></p>
        <p class="fr-s">
            Everything below is example data so you can see how the reports work.
            It is replaced automatically &mdash; there is nothing to do.
        </p>
    </div>

    <a href="why-first-report.php?instance_id=<?= urlencode((string)$instanceId) ?>" class="fr-link">
        View details
        <iconify-icon icon="solar:arrow-right-linear" style="font-size:15px;"></iconify-icon>
    </a>
</div>

<?php endif; ?>
