=== Atelier de Medios ===
Contributors: your-wordpress-org-username
Tags: media library, folders, media folders, organize, page builder
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Organize your media library in folders and subfolders, also inside the media window of Divi, Elementor and the block editor.

== Description ==

Atelier de Medios turns the media library into an ordered tree of folders and shows that same tree inside the media window used by page builders, so you never face a single pile of files again.

**Features**

* Folders and subfolders at any depth. Folders are virtual: file URLs never change and deleting a folder never deletes files.
* Folder tree inside the native media window used by Divi (including the Visual Builder), Elementor, the block editor and any plugin that uses the standard WordPress media picker.
* Drag and drop files onto folders, and folders onto folders.
* Drag a folder from your computer or an external drive onto the tree: folders, subfolders and names are recreated and every image is uploaded to its folder.
* Folder categories with colors, and filtering by category.
* Rename, move and delete folders from a context menu; search folders by name.
* Folder column and bulk action "Move to folder…" in the list view of the media library.
* Import your existing folders and file assignments from FileBird without modifying FileBird.
* Per-role permissions: choose who can create, rename and delete folders.
* Built-in user guide, settings page and a changelog tab.

The interface is currently available in Spanish.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Go to Media → Library and create your first folder with the "+" button, or open Media → Atelier de Medios for the guide and settings.

== Frequently Asked Questions ==

= If I delete a folder, do I lose images? =

No. Files move to "No folder" and subfolders move up one level.

= Do my image URLs change? =

No. Folders are an organization layer; files stay where they are on the server.

= Can a file be in two folders? =

No, a file belongs to a single folder. Use folder categories to group folders in other ways.

= Can I import my FileBird folders? =

Yes. Open Media → Atelier de Medios → "Importar desde FileBird". Make a database backup first and start with the analysis step.

= What happens to my data when I uninstall? =

Nothing is deleted unless you enable the option "delete data on uninstall" in the settings. Media files are never deleted.

== Changelog ==

= 1.5.0 =
* Code reviewed with Plugin Check; internal identifier renamed to atelier-de-medios.

= 1.4.0 =
* New: "Novedades" tab with the changelog of the latest versions.

= 1.3.0 =
* New: drag folders from your computer onto the tree to import them with their structure and names.

= 1.2.1 =
* Fixed: the folder tree was cut off in the media grid when opening a folder with few files.

= 1.2.0 =
* New: update checks from GitHub releases (GitHub edition only).

= 1.1.1 =
* Fixed: thumbnails overlapped the folder sidebar in the media window.

= 1.1.0 =
* New: import from FileBird.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.0 =
The plugin folder and main file were renamed to atelier-de-medios. Reinstall once if you installed an earlier version manually.
