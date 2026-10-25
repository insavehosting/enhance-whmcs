<?php
/**
 * Enhance WHMCS Module — Configuration
 */

// File-based API logging into modules/servers/enhance/logs/. Keep false in production:
// the directory is inside the web root and the log contains raw API responses.
if (!defined('ENHANCE_DEBUG')) {
    define('ENHANCE_DEBUG', false);
}

// Automatic linking by e-mail (default: off).
// When true, CreateAccount may (a) attach a WHMCS client to an existing Enhance customer
// organisation whose owner e-mail matches the client e-mail, and (b) attach an existing
// Enhance login with the same e-mail as Owner of a new organisation.
// Off is safer: a WHMCS client can change their own e-mail, so matching by e-mail may hand
// someone else's organisation/login to them. With it off, such cases fail with a clear
// message and an admin links the organisation manually in the client profile.
if (!defined('ENHANCE_AUTO_LINK_BY_EMAIL')) {
    define('ENHANCE_AUTO_LINK_BY_EMAIL', false);
}
