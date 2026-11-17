<?php
/**
 * Shop2LLM self-hosted channel marker (GitHub distribution only).
 *
 * Presence of this file activates the cloud update checker
 * (includes/class-selfhost-updater.php). It is absent from the
 * wordpress.org package, which therefore never checks shop2llm.com
 * and keeps using the official wordpress.org update channel.
 */
define('SHOP2LLM_SELFHOST_CHANNEL', 'github');
