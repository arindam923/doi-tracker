<?php

/**
 * Track Flow — Constants & enums
 * Single source of truth for dropdown options used across the app.
 */

if (!defined('TF_CONSTANTS_LOADED')) {
    define('TF_CONSTANTS_LOADED', true);

    // ─── Project Verticals (Item #3) ────────────────────────────
    $GLOBALS['TF_VERTICALS'] = [
        'Survey', 'E-commerce', 'Finance', 'Insurance',
        'Home Improvement', 'Home Services', 'Real Estate', 'Education',
        'Employment', 'Healthcare', 'Legal', 'Travel', 'Automotive',
        'Gaming', 'Gambling', 'Dating', 'Sweepstakes', 'Mobile Apps',
        'Software', 'Utilities', 'Subscription', 'Telecom',
        'Food & Beverage', 'Beauty & Fashion', 'Electronics',
        'Business Services', 'Lead Generation', 'Nonprofit',
        'Entertainment', 'Other',
    ];

    // ─── Conversion Types (Item #4) ─────────────────────────────
    $GLOBALS['TF_CONVERSION_TYPES'] = [
        'SOI', 'DOI', 'Lead', 'Sale', 'Install',
        'Call', 'Trial', 'Subscription',
    ];

    // ─── Target Devices (Item #4) ───────────────────────────────
    $GLOBALS['TF_TARGET_DEVICES'] = ['All', 'Desktop', 'Mobile', 'Tablet'];

    // ─── Campaign Status (Item #13) ──────────────────────────────
    $GLOBALS['TF_CAMPAIGN_STATUS'] = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Client Approval',
        'testing' => 'Testing',
        'live' => 'Live',
        'paused' => 'Paused',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ];

    // ─── Visibility (Item #14) ──────────────────────────────────
    $GLOBALS['TF_VISIBILITY'] = [
        'private' => 'Private',
        'public' => 'Public',
        'invite_only' => 'Invite Only',
    ];

    // ─── Campaign Type (Item #6, extends legacy CPL/CPC/CPA) ─────
    $GLOBALS['TF_CAMPAIGN_TYPES'] = [
        'CPL' => 'CPL (Cost Per Lead)',
        'CPC' => 'CPC (Cost Per Click)',
        'CPA' => 'CPA (Cost Per Acquisition)',
        'CPS' => 'CPS (Cost Per Sale)',
        'CPI' => 'CPI (Cost Per Install)',
        'CPM' => 'CPM (Cost Per Mille)',
        'RevShare' => 'RevShare (Revenue Share)',
        'Hybrid' => 'Hybrid (Mixed Model)',
    ];

    // ─── Vendor Traffic Types (Item #16) ────────────────────────
    $GLOBALS['TF_TRAFFIC_TYPES'] = [
        'Email', 'Facebook', 'Google', 'Native', 'Push',
        'Incent', 'Search', 'Display', 'Influencer', 'API',
    ];

    // ─── Vendor Status (Item #17) ───────────────────────────────
    $GLOBALS['TF_VENDOR_STATUSES'] = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
    ];

    // ─── Currencies (Item #3) ───────────────────────────────────
    $GLOBALS['TF_CURRENCIES'] = [
        'USD', 'EUR', 'GBP', 'INR', 'AED', 'SAR',
        'CAD', 'AUD', 'JPY', 'SGD', 'BRL', 'MXN',
    ];

    // ─── Document Types (Item #35) ──────────────────────────────
    $GLOBALS['TF_DOCUMENT_TYPES'] = [
        'IO' => 'Insertion Order',
        'MSA' => 'Master Service Agreement',
        'NDA' => 'Non-Disclosure Agreement',
        'Brief' => 'Offer Brief',
        'Creative' => 'Creative',
        'Terms' => 'Terms & Conditions',
        'Other' => 'Other',
    ];

    // ─── ISO 3166 country list (used by multi-select) ──────────
    $GLOBALS['TF_COUNTRIES'] = [
        'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
        'AU' => 'Australia', 'IN' => 'India', 'DE' => 'Germany', 'FR' => 'France',
        'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'SE' => 'Sweden',
        'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'IE' => 'Ireland',
        'BE' => 'Belgium', 'AT' => 'Austria', 'CH' => 'Switzerland', 'PT' => 'Portugal',
        'PL' => 'Poland', 'CZ' => 'Czech Republic', 'GR' => 'Greece', 'HU' => 'Hungary',
        'RO' => 'Romania', 'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey',
        'IL' => 'Israel', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia',
        'EG' => 'Egypt', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya',
        'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile',
        'CO' => 'Colombia', 'PE' => 'Peru', 'JP' => 'Japan', 'KR' => 'South Korea',
        'CN' => 'China', 'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'SG' => 'Singapore',
        'MY' => 'Malaysia', 'TH' => 'Thailand', 'ID' => 'Indonesia', 'PH' => 'Philippines',
        'VN' => 'Vietnam', 'NZ' => 'New Zealand', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka', 'NP' => 'Nepal',
    ];
}

/**
 * Convenience accessors so callers don't have to reach into $GLOBALS.
 */
function tf_verticals()        { return $GLOBALS['TF_VERTICALS']; }
function tf_conversion_types() { return $GLOBALS['TF_CONVERSION_TYPES']; }
function tf_target_devices()   { return $GLOBALS['TF_TARGET_DEVICES']; }
function tf_campaign_status()  { return $GLOBALS['TF_CAMPAIGN_STATUS']; }
function tf_visibility()       { return $GLOBALS['TF_VISIBILITY']; }
function tf_campaign_types()   { return $GLOBALS['TF_CAMPAIGN_TYPES']; }
function tf_traffic_types()    { return $GLOBALS['TF_TRAFFIC_TYPES']; }
function tf_vendor_statuses()  { return $GLOBALS['TF_VENDOR_STATUSES']; }
function tf_currencies()       { return $GLOBALS['TF_CURRENCIES']; }
function tf_document_types()   { return $GLOBALS['TF_DOCUMENT_TYPES']; }
function tf_countries()        { return $GLOBALS['TF_COUNTRIES']; }
