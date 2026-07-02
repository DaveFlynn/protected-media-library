=== Protected Media Library ===
Contributors: dave
Tags: media, protected, members, downloads, gated content
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPLv2 or later

A second, private media library that lives outside your public uploads folder. Files are streamed only to logged-in users — never reachable by a direct URL.

== Description ==

= What this plugin does, in one paragraph =

Normal WordPress uploads live in `wp-content/uploads/` and are reachable by anyone who knows or guesses the URL — there is no "logged-in only" protection on the file itself. This plugin gives you a SEPARATE library that stores files OUTSIDE that public folder and only serves them after checking that the visitor is signed in. From the editor's point of view, you get new "Protected" blocks (image, file, audio, video, gallery) that look and feel like the regular ones, but the files behind them are private.

= Who this is for =

* Membership sites delivering paid PDFs, audio, or video.
* Churches publishing sermons or member-only documents.
* Anyone who wants a "secret URL doesn't leak" guarantee instead of relying on an unguessable filename.

= Why you can't just use "private" posts to protect files =

WordPress's "Private" and password-protected post settings only restrict the HTML page — the underlying file URL in `wp-content/uploads/` stays publicly accessible. Once someone has the URL (forwarded email, browser cache, web scraper, "save image as"), they have the file. This plugin fixes that at the file level, not the post level.

= How it works (plain English) =

1. When you upload a file as Protected, it goes into a folder ABOVE your WordPress install (e.g. one level up from `wp-content/`), in a folder named like `pml-storage-a3f9c2e8b401/`. That folder is NOT served by your webserver.
2. When a logged-in user views a page with protected media, the plugin generates URLs like `/protected-media/2026/05/sermon.mp3`. Those URLs don't point to a real file on disk — they're handled by a small PHP script that checks the visitor's signed cookie, then streams the file if (and only if) the cookie checks out.
3. Anonymous visitors see a "🔒 Sign in to view" placeholder instead of the file. No filename, no thumbnail, no leak.

== Installation ==

1. Upload the `protected-media-library` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** menu in WordPress.
3. On activation the plugin:
   * Picks a storage location, ideally one level ABOVE your WordPress install.
   * Generates a secret key (used to sign the auth cookie).
   * Writes a deny-all `.htaccess` inside the storage folder (belt-and-braces; on Apache).
   * Adds a rewrite rule to your site's root `.htaccess` so requests to `/protected-media/...` go to the fast handler (on Apache).
   * Runs a self-test: it drops a temporary file in the storage folder and tries to fetch it via a public URL. If it succeeds (meaning your files would be reachable), you'll see a red admin notice. **Do not ignore that notice** — it means the plugin couldn't place storage in a safe location.

= After activation =

Look for the notice at the top of your admin screens telling you which delivery mode is active:

* **Fast path (Apache):** "Active. Fast-path delivery is enabled." No further action needed.
* **Fallback (Nginx, locked-down hosts):** "Active. Fast-path delivery could not be installed automatically." Files are still secure — they're served through WordPress instead of the standalone handler. Slower for pages with many protected files, but works without server config. Optional speed upgrade: ask your host to add a Nginx `location` block (snippet in the plugin's `nginx-example.conf`).

== Using the plugin ==

= Uploading protected media =

Two ways:

1. **From the post editor.** Add one of the new blocks ("Protected Image", "Protected File", "Protected Audio", "Protected Video", "Protected Gallery"). Click **Upload** to upload a new file, or **Browse Protected Library** to pick an existing one. The block automatically attaches the file to the current post, so it doesn't end up as "(Unattached)" in your library.

2. **From the admin menu.** Go to **Media → Add Protected Media File**. This is a drop-zone uploader for bulk uploads outside any specific post. Files uploaded here go into the protected library without being attached to a post (which is correct — they're not in a post yet).

You can see everything in the protected library at **Media → Protected Library**. It looks like the standard media list but only shows protected files, and has a **Size** column so you can spot large videos quickly.

= Inserting protected media into a post =

In the block editor, use any of the five Protected blocks. In the Classic Editor (or in widgets / ACF text fields), use the matching shortcodes:

`[pml-image id="12"]`
`[pml-file id="14" display="Sermon Notes" preview="true"]`
`[pml-audio id="18" preload="metadata"]`
`[pml-video id="22" poster="23" preload="metadata"]`
`[pml-gallery ids="12,14,18" columns="3" crop="true"]`

What signed-in users see: the actual image, audio player, video player, PDF preview, or gallery.

What signed-out visitors see: a generic "🔒 Sign in to view" card. No filename, no thumbnail, no caption — by design, so even the EXISTENCE of specific content stays private.

= Switching a file from protected to public (or vice versa) =

You can't, by design. Changing a file's protected/public state would mean moving the physical file AND rewriting every URL referencing it across your content. If you need to change a file's protection level, upload it fresh on the other side and delete the original.

== Frequently Asked Questions ==

= Where do the files actually live? =

In a folder one level above your WordPress install (e.g. if WordPress is at `/var/www/html/wp/`, files go in `/var/www/html/pml-storage-<hash>/`). That folder is not served by your webserver, so direct URLs to it return 403 or 404 — there's no way to "guess" your way to a file.

If the plugin can't find a writable directory above your WordPress install, it falls back to `wp-content/protected-uploads/`. On Apache this is still safe (a `.htaccess` denies access). On Nginx it is NOT safe by itself, and the plugin will show a red warning notice. In that case, ask your host to either give PHP write access to a parent directory, or add an Nginx rule blocking direct access to `protected-uploads`.

= I see "the response is not a valid JSON response" when uploading a large file. =

Your file is bigger than your server's PHP upload limit. The plugin tries to detect this and show a clean error like "Upload (148 MB) exceeds the server's post_max_size limit (100 MB)" — but if your webserver itself rejects the request before PHP runs (Nginx `client_max_body_size`, Apache `LimitRequestBody`), you'll get that generic JSON error. Raise the limits in php.ini and your webserver config, or use smaller files.

To check your current limits, look at **Media → Add Protected Media File** — the page shows the active `upload_max_filesize`.

= Will my .mov video play in browsers? =

Maybe. `.mov` is a container — Safari plays them natively, Chrome and Firefox handle them only if the codec inside is H.264. For maximum compatibility, transcode to MP4 with H.264 video and AAC audio, with the moov atom at the front of the file:

`ffmpeg -i input.mov -c:v libx264 -preset medium -crf 23 -c:a aac -b:a 128k -movflags +faststart output.mp4`

The `+faststart` flag lets the browser start playing before the whole file downloads — important since protected files don't get CDN caching.

= Does this work with page caches (WP Super Cache, Cloudflare, etc.)? =

Yes. Any post containing a protected block or `[pml-...]` shortcode automatically sends `Cache-Control: private, no-cache` and `Vary: Cookie`, which tells well-behaved caches not to store the page. Make sure your caching layer respects those headers (the defaults usually do).

= Does it work with featured images, ACF image fields, or the standard wp.media picker? =

Not in v0.1. Those all use WordPress's built-in media frame, which only sees public files. Protected files can be inserted via:

* Any of the five Protected blocks.
* Any of the five `[pml-*]` shortcodes (Classic Editor, ACF text/wysiwyg fields, page builders).
* The "Add Protected Media" / "Add Protected Gallery" buttons next to "Add Media" in the Classic Editor.

= What happens to a user's access when I cancel their membership? =

The auth cookie has a 1-hour lifetime. A user who's had their account deactivated will lose access at the next cookie expiry (within an hour). For instant revocation, change the HMAC secret (planned: `wp pml rotate-secret`).

= Can I store files on S3 / R2 / object storage? =

No, by design. The fast-path handler reads files from local disk to avoid a WordPress bootstrap per request — that's what makes it fast. Object-storage offload is incompatible with this design.

= Does this support multisite? =

Not in v0.1. Single-site only.

== Security model ==

* **Files outside the document root.** This is the single most important property — there is no URL on your domain that maps to the actual file path.
* **HMAC-signed cookie.** Authentication for the file handler doesn't trust the WordPress session blindly — it relies on a separately-signed `wp_protected_media` cookie, signed with a secret stored at 0600 permissions outside the webserver's reach.
* **Path-traversal proof.** The handler rejects any `..` or non-canonical path components before it touches the filesystem.
* **No PHP execution from storage.** Even if a `.php` file ended up in the storage folder, the handler refuses to serve it.
* **No-leak placeholders.** Anonymous viewers see a generic "🔒 Sign in" card — no filename, no thumbnail, no caption, no MIME type. Even the existence of content stays private.
* **Range request support.** Audio and video can be seeked without downloading the full file, and each Range request is independently authenticated.

== Frontend behavior summary ==

For each block, when a signed-out visitor lands on the page:

* **Protected Image:** locked card preserving aspect ratio (so layout doesn't jump after sign-in).
* **Protected File:** locked card with "Sign in to download" button.
* **Protected Audio:** locked card with "Sign in to listen" button.
* **Protected Video:** locked card with "Sign in to watch" button.
* **Protected Gallery:** generic locked card (no count, no thumbnails).

All sign-in links point to wp-login.php with a `redirect_to` parameter pre-filled to the current page, so the user is taken back to where they were after signing in.

== Compatibility ==

* **Apache + mod_rewrite:** full fast-path support, auto-configured.
* **Nginx:** works out of the box via WordPress fallback routing. For maximum speed, add a `location ^~ /protected-media/ { ... }` block that proxies to the plugin's `handler.php`.
* **PHP 8.1+** required.
* **WordPress 6.4+** required (block.json apiVersion 3 / dynamic blocks).
* **S3 offload plugins:** incompatible — protected files must stay on local disk.
* **Page caching plugins:** compatible — the plugin emits the right `Cache-Control` headers automatically.
* **Multisite:** not supported in v0.1.

== Changelog ==

= 0.1.4 =
* Fixed a critical bug where updating the plugin (via the built-in updater) broke all protected media delivery: `handler-config.php`, needed by the fast-path handler, was generated only at activation and lived inside the plugin's own directory — which WordPress's updater replaces wholesale on every update, silently deleting it. The file now lives in `wp-content/` (outside the replaced directory), and the plugin self-heals on the next admin page load after any update, re-checking anything activation would have set up. If you were on 0.1.2 or 0.1.3 and protected media stopped loading after updating, this fixes it going forward automatically.

= 0.1.3 =
* Fixed the native WordPress drag-and-drop dropzone on the Protected Library admin page (`upload.php?pml_mode=protected`) silently uploading files to the public library with no protected-storage routing and no visual feedback. Drag-and-drop on that page is now blocked with a message pointing to "Add New File".

= 0.1.2 =
* Added a "Protected Image" ACF field type (`pml_protected_image`) — a drop-in replacement for ACF's native Image field that selects from / uploads to protected storage. Stores a plain attachment ID, so it is value-compatible with an existing image field. Registered only when ACF is active (no hard dependency).
* Fixed protected files being orphaned on disk when an attachment is deleted: core's cleanup refuses to unlink files outside the docroot. The plugin now maps `get_attached_file()` to the real protected-storage path (also fixing Regenerate Thumbnails and other `get_attached_file()` callers) and removes the real files on `delete_attachment`.
* Fixed activation under WP-CLI not installing the Apache fast-path: WP-CLI can't detect the server, so the server-dependent setup (root `.htaccess` block, leak self-test, delivery mode) is now deferred to the first real web request.
* Fixed the "Add Media File" button on the Protected Library admin page pointing at the public uploader (it silently uploaded to public storage); it now points at the protected uploader.
* Documented the `PML_STORAGE_PATH` constant as an explicit storage-location override for hosts where the auto-choice is wrong.

= 0.1.1 =
* Added Protected Video block + `[pml-video]` shortcode, with optional poster image, mute, plays-inline, and loop controls. Range support already shipped in 0.1 means seeking works.
* Added Size column to the Protected Library admin page.
* Picker modal now includes an Upload button (previously had to close the modal and use the block's Upload affordance).
* Picker now shows real thumbnails for image rows in list mode, and falls back to an icon for non-image MIME types in grid mode.
* Modal widened to 92vw / 1100px max.
* Picking an existing attachment from the library now automatically attaches it to the current post (was leaving them "(Unattached)").
* Clean error messages for oversized uploads (file size, server limit, and what to ask the host) instead of the cryptic "response is not a valid JSON response."
* Page caches now receive `Cache-Control: private, no-cache` + `Vary: Cookie` for any post containing a protected block or shortcode.
* Each block now ships a v0.1 attribute-snapshot `deprecated` entry so future save() shape changes don't break existing posts.

= 0.1.0 =
* Initial release. Protected Image, File, Audio, and Gallery blocks. Matching `[pml-image]`, `[pml-file]`, `[pml-audio]`, `[pml-gallery]` shortcodes. Apache fast-path handler with automatic activation. WordPress-routed fallback for Nginx and locked-down hosts. HMAC-signed cookie auth. HTTP Range support (audio scrubbing, PDF progressive render). Storage outside docroot with leak self-test. Classic Editor media buttons.

== Roadmap (not promises) ==

* Per-attachment / per-role access control (the schema already supports this; UI is missing).
* `wp pml rotate-secret` WP-CLI command for instant access revocation.
* Settings page for cookie TTL, allowed MIME types, default access rule.
* Featured image picker via a meta-box pattern (since the built-in featured-image UI uses the unextensible wp.media frame).
* Multisite support.
