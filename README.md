# Kntnt Unicode Fix

[![License: GPL v2+](semi-copy.assets/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-3.0) [![Requires PHP: 8.3+](semi-copy.assets/PHP-8.3+-blue.svg)](https://php.net) [![Requires WordPress: 6.0+](semi-copy.assets/WordPress-6.0+-blue.svg)](https://wordpress.org)

WordPress plugin that fixes corrupt Unicode escape sequences in block editor JSON data.

## Description

WordPress stores block editor settings (colors, fonts, spacing) as JSON data inside HTML comments. When these settings contain special characters, they get encoded as Unicode escape sequences like `\u00e5` or `\u0026`.

Sometimes other plugins or processes accidentally strip the backslash, leaving broken sequences like `u00e5` instead of `\u00e5`. This corrupts the JSON structure, causing blocks to lose styling and display "Block contains unexpected or invalid content" errors.

This plugin scans your content for these broken Unicode sequences and repairs them by adding back the missing backslashes.

## Installation

1. [Download the plugin zip archive.](https://github.com/Kntnt/kntnt-unicode-fix/releases/latest/download/kntnt-unicode-fix.zip)
2. Go to WordPress admin panel → Plugins → Add New.
3. Click "Upload Plugin" and select the downloaded zip archive.
4. Activate the plugin.

## Usage

The dashboard widget shows a list of posts with corrupt Unicode sequences (refreshed every 12 hours). Click "Scan for corrupt unicodes" to get a new list.

For each post, you can:

* **View**: Opens the post on your site
* **Edit**: Opens the post in the WordPress editor
* **Fix**: Repairs the corruption and creates a new revision

Fixed posts are removed from the list and shown as "Recently fixed" with error counts.

## For advanced users and developers

### User Capabilities

Only users with the `kntnt_unicode_fix` capability can use this plugin. By default, this capability is granted to Administrators.

You can grant this capability to other roles or individual users using capability management plugins like [Members](https://sv.wordpress.org/plugins/members/).

### WordPress Filters

#### `kntnt-unicode-fix-post-types`

Modifies which post types are scanned.

```php
add_filter('kntnt-unicode-fix-post-types', function($post_types) {
    // Only scan posts and pages
    return ['post', 'page'];
});
```

#### `kntnt-unicode-fix-post-ids`

Modifies which post IDs are scanned.

```php
add_filter('kntnt-unicode-fix-post-ids', function($post_ids) {
    // Limit to first 100 posts
    return array_slice($post_ids, 0, 100);
});
```

## Questions & Answers

### How can I get help?

If you have questions about the plugin and cannot find an answer here, start by looking at issues and pull requests on our GitHub repository. If you still cannot find the answer, feel free to ask in the plugin's issue tracker on GitHub.

### How can I report a bug?

If you have found a potential bug, please report it on the plugin's issue tracker on GitHub.

### How can I contribute?

Contributions to the code or documentation are much appreciated.
If you are familiar with Git, please create a pull request.
If you are not familiar with Git, please create a new ticket on the plugin's issue tracker on GitHub.

## Changelog

### 1.0.0

* Initial release
* Dashboard widget with scan and fix functionality
* Automatic corruption detection using regex patterns
* Safe fixing with WordPress revision creation
* Memory monitoring and concurrent scan protection
* Capability-based security system
* Internationalization support
* Developer filter hooks
* Batch processing support
* Preview functionality
* Detailed scan statistics