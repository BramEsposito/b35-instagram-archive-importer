# B35 Instagram Archive Importer

A WordPress plugin that imports posts from a personal Instagram data export into WordPress. Each Instagram post becomes a WordPress post with its images or videos, hashtags as taxonomy terms, and location data linked to Google Maps.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- A [Meta data export](https://accountscenter.instagram.com/info_and_permissions/dyi/) from your Instagram account

## Installation

1. Copy this plugin folder into `wp-content/plugins/`.
2. Activate the plugin in **Plugins > Installed Plugins**.
3. Optionally, define a Google Places API key in `wp-config.php` to enable the location picker on the post editor:

```php
define( 'GOOGLE_PLACES_API', 'your-api-key-here' );
```

## Preparing your archive

Request your data export from Meta (JSON format). You will need two files:

**`posts_1.json`** — included in the Instagram export under `your_instagram_activity/content/`. It contains an array of post objects, each with a `media` array of images or videos.

**`locations.csv`** — a CSV you maintain manually to associate timestamps with place names. Format:

```
1693935233,"Antwerp, Belgium",51.2157,4.4141
1693821600,"Amsterdam, Netherlands",52.3676,4.9041
```

Columns: Unix timestamp, location name (quoted), latitude, longitude. The timestamp must match the `creation_timestamp` value in the JSON for the location to be assigned.

## Configuration

Go to **Tools > Instagram Importer**.

| Setting | Description |
|---|---|
| Media base URL | Base URL where your Instagram media files are hosted (e.g. `https://example.com/wp-content/uploads/instagram/`). The `uri` paths from the JSON are appended to this. |
| Post category | WordPress category assigned to every imported post. |
| Post author | WordPress user set as the post author. |
| Post status | Create posts as Published or Draft. |
| Instagram JSON | Upload and select your `posts_1.json` file via the media library. |
| Locations CSV | Upload and select your `locations.csv` file via the media library. |

Save settings, then click **Start Import**.

## What gets imported

- **Images** — downloaded from the media base URL and added to the WordPress media library, then embedded as Gutenberg image blocks.
- **Videos** — same process, embedded as Gutenberg video blocks.
- **Hashtags** — extracted from captions and stored as `ig_tag` taxonomy terms.
- **Locations** — matched by timestamp from the CSV and stored as `ig_location` taxonomy terms. Location term links redirect to Google Maps.

## Post editor

Each post gets a **Location** meta box in the sidebar. If `GOOGLE_PLACES_API` is defined, a Google Places search field lets you update or reassign a location. The `ig_location` taxonomy panel in the block editor is hidden in favour of this meta box.

## Notes

- The plugin does not modify or delete existing posts. Running the import twice will create duplicate posts.
- Media files must already be hosted at the configured base URL; the importer downloads them from there into WordPress.
- Any failed image downloads or unmatched locations are collected and shown as an admin error notice after the import completes.
