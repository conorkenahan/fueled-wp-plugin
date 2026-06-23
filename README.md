# Featured Content Block

A WordPress plugin that adds a Gutenberg block for displaying featured posts. The block is dynamic and server-rendered, backed by a custom REST endpoint with transient caching.

## What it does

- Adds a "Featured Posts" block to the Gutenberg inserter
- Editors choose number of posts (1–20) and an optional category filter from the block sidebar
- Two layout modes: grid and list
- Toggles for showing the excerpt and post date
- Server-rendered on every request so content updates without re-saving pages
- Backed by a custom REST endpoint with per-parameter transient caching, invalidated on `save_post`

## Install

1. Create a LocalWP site (PHP 8.1+, WordPress 6.4+)
2. Copy this plugin folder into the site's `wp-content/plugins/` directory
3. From the plugin folder, run `npm install`
4. Run `npm run build`
5. In WP Admin, go to Plugins and activate Featured Content Block

To use it: edit any post or page, open the block inserter, and search for "Featured Posts."

## Build

| Command | What it does |
|---|---|
| `npm run build` | Production build |
| `npm run start` | Watch mode for development |
| `npm run lint:js` | ESLint |
| `npm run lint:ts` | TypeScript type-check |
| `npm run format` | Prettier |

## Architecture

### Dynamic block, server-side render

The block's `save()` function returns `null`, so nothing except the block delimiter comment and its attributes gets stored in `post_content`. The HTML is generated on every request by a PHP `render_callback` registered via `block.json`, which queries the latest posts and returns the markup fresh.

This fits the use case: featured posts change as new content is published. A static block would freeze the post list at save time and require re-saving every page containing the block whenever content updated.

### REST endpoint with transient caching

The plugin registers a custom REST endpoint at `/wp-json/featured-content-block/v1/posts` that returns shaped post data for the block. The PHP render callback (`FCB_Block_Register::render()`) consumes this endpoint via `rest_do_request()` rather than running its own `WP_Query`, which keeps the caching logic in one place.

Each combination of `count` and `category` parameters gets its own transient, keyed by an MD5 hash of those values. A single shared cache would serve the wrong posts when two block instances on the same page use different settings.

Cache invalidation is hooked to `save_post`, which fires on any post create, update, or status change. Since any post change can affect any cached query result, the handler bulk-deletes every `fcb_`-prefixed transient via a single `$wpdb` LIKE query rather than tracking which entries might be affected.

### Editor uses the WordPress core data store

The editor component (`src/edit.tsx`) uses `useSelect` from `@wordpress/data` to fetch posts and categories from WordPress's core store, rather than calling the plugin's custom REST endpoint with `useEffect` and `fetch`. The core store handles request de-duplication across the editor, in-session caching, and resolution tracking via `hasFinishedResolution`, so loading state and re-fetching on attribute changes work without custom code.

The two data paths are intentionally different: the front end uses the plugin's custom endpoint (with its own caching shape and response structure), while the editor uses the core store (which integrates with the rest of the editor's data layer). Conflating them would mean reinventing what the core store already does.

### Output escaping happens once, at the template

The REST endpoint returns raw post data — raw title, raw permalink, raw author name. Escaping happens at the point of output, in `templates/block-output.php`, using context-appropriate functions: `esc_html` for text content, `esc_attr` for attribute values, and `esc_url` for URLs.

The principle is *escape once, at output*. Escaping earlier in the pipeline (for example, inside the data layer) leads to double-escaping bugs: a title like "AT&T" becomes "AT&amp;amp;T" after two passes. The editor canvas handles this differently — since the WordPress core store returns titles with HTML entities already encoded, the editor decodes them at render with `decodeEntities` from `@wordpress/html-entities`. Different data path, different concern, handled in the right place.

### Conditional asset loading via block.json

`block.json` declares the block's front-end script (`viewScript`) and styles (`style`). WordPress loads them only on pages that actually contain the block, rather than on every page of the site. The alternative — enqueuing the assets globally via `wp_enqueue_scripts` — would mean every visitor downloads and parses the plugin's CSS and JS even on pages where the block doesn't appear.

## File structure

```
featured-content-block/
│
├── featured-content-block.php      Main plugin file. Bootstrap class,
│                                   activation/deactivation hooks.
│
├── block.json                      Block metadata: name, attributes, supports,
│                                   asset paths.
│
├── includes/
│   ├── class-rest-api.php          Custom REST endpoint with MD5-keyed transient
│   │                               cache. Handles registration, response shaping,
│   │                               and cache invalidation on save_post.
│   └── class-block-register.php    Registers the block type and runs the PHP
│                                   render callback.
│
├── src/                            TypeScript source — compiled to build/
│   │                               by @wordpress/scripts.
│   ├── index.ts                    Webpack entry. Calls registerBlockType().
│   ├── edit.tsx                    Editor component. useSelect for posts and
│   │                               categories. InspectorControls sidebar.
│   ├── save.tsx                    Returns null — dynamic block.
│   └── types.ts                    Shared TypeScript interfaces.
│
├── assets/
│   ├── css/
│   │   ├── style.css               Front-end + editor canvas styles.
│   │   └── editor.css              Editor-only overrides.
│   └── js/
│       └── frontend.js             Vanilla JS. IntersectionObserver fade-in,
│                                   keyboard focus handling.
│
├── templates/
│   └── block-output.php            PHP template. Escapes output at render time.
│
└── package.json                    @wordpress/scripts build toolchain.
```