<?php

/**
 * Shop2LLM — AI Crawler Groups for robots.txt (v1.7.2)
 *
 * Single source of truth for classifying AI crawlers by business impact
 * (search-citation / user-triggered / training) and rendering the grouped
 * robots.txt section with explicit allow/block consequences.
 *
 * Why groups: "Allow all AI bots" hides the fact that each crawler answers a
 * different business question. Blocking OAI-SearchBot (search citation) is not
 * the same decision as blocking GPTBot (training) — the first removes the
 * store from ChatGPT Search citations, the second only keeps content out of
 * future training corpora. The grouped output makes that visible to the
 * merchant in the generated robots.txt itself.
 *
 * Pure functions only — no WP state, directly unit-testable
 * (tests/unit/robots_groups_unit_test.php requires this file as-is).
 *
 * @package Shop2LLM
 * @since   1.7.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// ─── Group catalog (single source of truth) ────────────────────────────

/**
 * AI crawler groups by business impact, in robots.txt render order.
 *
 * Tokens are the official robots.txt user-agent names (case-insensitive on
 * lookup). Group notes state the verifiable consequence of allowing/blocking
 * each group — deliberately free of exposure or ranking promises.
 *
 * @return array<string, array{label: string, allow_note: string, block_note: string, bots: array<int, string>}>
 */
function shop_to_llm_robots_bot_groups(): array
{
    return [
        // Decides whether AI search engines can cite the store.
        'search-citation' => [
            'label'      => 'Search & citation crawlers',
            'allow_note' => 'These crawlers index the store so AI search engines (ChatGPT Search, Perplexity, Claude Search, Meta AI) can cite and link its pages.',
            'block_note' => 'Blocking OAI-SearchBot removes the store from ChatGPT Search citations; blocking PerplexityBot removes it from the Perplexity index.',
            'bots'       => [
                'OAI-SearchBot',
                'PerplexityBot',
                'Perplexity-User',
                'ClaudeBot',
                'Claude-SearchBot',
                'Meta-ExternalAgent',
            ],
        ],
        // Fetches a page only when a user explicitly asks the assistant to.
        'user-triggered' => [
            'label'      => 'User-triggered fetchers',
            'allow_note' => 'These fetch a page only when a user asks ChatGPT or Claude to look at it — they do not crawl on their own schedule.',
            'block_note' => 'Blocking them makes real-time user requests fail: the assistant reports the page as unavailable instead of reading it.',
            'bots'       => [
                'ChatGPT-User',
                'Claude-User',
            ],
        ],
        // Adds content to future model corpora; no citation or traffic promise.
        'training' => [
            'label'      => 'Model training crawlers',
            'allow_note' => 'These collect content for future model training corpora — allowing them carries no citation, ranking or traffic promise.',
            'block_note' => 'Blocking keeps content out of training corpora and does not affect live search citations (those use the separate crawlers above).',
            'bots'       => [
                'GPTBot',
                'Google-Extended',
                'Applebot-Extended',
                'Bytespider',
            ],
        ],
    ];
}

/**
 * Classify one robots.txt user-agent token into its business group.
 *
 * @param string $botToken Official crawler token (e.g. 'OAI-SearchBot').
 * @return string Group id ('search-citation'|'user-triggered'|'training'),
 *                or '' when the token is not classified.
 */
function shop_to_llm_robots_group_of(string $botToken): string
{
    $needle = strtolower(trim($botToken));
    if ($needle === '') {
        return '';
    }
    foreach (shop_to_llm_robots_bot_groups() as $groupId => $group) {
        foreach ($group['bots'] as $bot) {
            if (strtolower($bot) === $needle) {
                return $groupId;
            }
        }
    }
    return '';
}

/**
 * Render the grouped AI-crawler robots.txt section.
 *
 * Classified patterns come first (search-citation → user-triggered →
 * training), each under a comment header carrying the group's allow/block
 * consequences. Unclassified patterns land in a trailing "other" section so
 * the full allow list behavior is preserved.
 *
 * @param array<string, mixed>  $patterns    Allowed robots.txt user-agent
 *                                           patterns as keys (registry map).
 * @param array<string, string> $groupLabels Optional group id → label override.
 * @return string robots.txt fragment (always ends with a newline).
 */
function shop_to_llm_robots_render_grouped(array $patterns, array $groupLabels = []): string
{
    $groups = shop_to_llm_robots_bot_groups();
    $classified = [];
    $other = [];
    foreach (array_keys($patterns) as $pattern) {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            continue;
        }
        $gid = shop_to_llm_robots_group_of($pattern);
        if ($gid === '') {
            $other[] = $pattern;
        } else {
            $classified[$gid][] = $pattern;
        }
    }

    $out = '';
    foreach (array_keys($groups) as $gid) {
        $bots = $classified[$gid] ?? [];
        if ($bots === []) {
            continue;
        }
        $label = $groupLabels[$gid] ?? $groups[$gid]['label'];
        $out .= '# ' . $label . "\n";
        $out .= '# Allow: ' . $groups[$gid]['allow_note'] . "\n";
        $out .= '# Block: ' . $groups[$gid]['block_note'] . "\n";
        foreach ($bots as $bot) {
            $out .= 'User-agent: ' . $bot . "\nAllow: /\n\n";
        }
    }
    if ($other !== []) {
        $out .= "# Other AI agents & search engines\n";
        foreach ($other as $bot) {
            $out .= 'User-agent: ' . $bot . "\nAllow: /\n\n";
        }
    }
    return $out;
}
