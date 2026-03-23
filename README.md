# Folder Browser · CERN EOS

A lightweight, self-contained web-based file browser designed for **CERN EOS storage**, served via the CERN web proxy (e.g. `https://fpantale.web.cern.ch/`).

## Features

| Feature | Description |
|---------|-------------|
| 📁 **Folder navigation** | Click-through directory tree with breadcrumb |
| 🔍 **Search** | Real-time search across the current directory and all sub-folders |
| 🗂 **Type filters** | One-click filter by Images, PDFs, Videos, Audio, Data, Code, Notebooks, Documents, Archives |
| ↕️ **Sorting** | Sort by name, date (newest/oldest), size, or file type |
| 🖼 **Image preview** | Inline lightbox with zoom (click to zoom), keyboard ‹ / › navigation |
| 📄 **PDF preview** | Embedded PDF viewer using the browser's native renderer |
| 🎬 **Video / Audio** | HTML5 in-browser playback |
| 💻 **Text / Code view** | Syntax-highlighted viewer for Python, C++, JavaScript, JSON, CSV, Markdown, LaTeX, … |
| 📓 **Jupyter notebooks** | Inline cell-by-cell preview of `.ipynb` files, including embedded figures |
| 📊 **CSV preview** | Auto-detected column headers and row preview in a table |
| 🔬 **EXIF metadata** | Camera make/model, exposure, ISO, focal length, GPS (with map link) for JPEG/TIFF images |
| 🌙 **Dark mode** | Automatic (follows OS preference) + manual toggle |
| 🔗 **Shareable links** | Copy direct links to any file or folder |
| ⬇️ **Downloads** | One-click download button for every file |
| ⌨️ **Keyboard shortcuts** | `←` / `→` to navigate previews, `Esc` to close, `/` to focus search |
| 📱 **Responsive** | Works on desktop, tablet, and mobile |

### Scientific / HEP-specific file type recognition
`ROOT`, `HDF5 / H5`, `FITS`, `Parquet`, `NumPy (.npy / .npz)`, `HepMC`, `YAML`, `LaTeX (.tex)`, Jupyter notebooks, and more.

---

## Setup (CERN EOS / personal web area)

### Requirements
- A CERN web area served via Apache (e.g. personal web area at `https://<user>.web.cern.ch/`)
- **PHP ≥ 8.0** available (required for the directory listing API)
- No database, no build step, no npm – just copy the files

### Installation

1. **Copy the two files** into the root of your EOS web folder:
   ```
   index.php
   .htaccess
   ```

2. **Make sure PHP is enabled** in your CERN web area.  
   Log in to [CERN Web Services](https://webservices.web.cern.ch/) and confirm PHP is activated for your site.

3. **Visit your URL** – e.g. `https://fpantale.web.cern.ch/` – and the browser will appear automatically.

That's it. Subfolders and all files are browsed dynamically; no configuration is needed.

---

## How it works

```
Browser  ──GET /?action=list&path=/subdir──►  index.php  ──scandir()──►  filesystem
         ◄──── JSON: [{name, size, type, …}] ────────────────────────────────────────

Browser  ──GET /?action=meta&path=/img.jpg──►  index.php  ──exif_read_data()──►  EXIF
         ◄──── JSON: {exif: {camera, settings, gps}, image: {width, height}} ───────

Browser  ──GET /?action=search&path=/&query=plot──►  index.php  ──recursive search──►
         ◄──── JSON: [{name, path, type, …}] ──────────────────────────────────────
```

`index.php` serves **both** the HTML frontend (on a plain `GET /`) and the JSON API (on `GET /?action=...`). Everything is self-contained in two files.

---

## Keyboard shortcuts

| Key | Action |
|-----|--------|
| `/` | Focus search box |
| `Esc` | Close preview / metadata panel |
| `←` / `→` | Navigate between files in preview |
| Right-click | Open metadata panel for a file |

---

## Security

- **Path traversal prevention**: all paths are resolved with `realpath()` and verified to be inside `BASE_DIR` before being used.
- **Script self-exclusion**: `index.php` hides itself from the file listing.
- **Hidden files hidden**: dotfiles (`.htaccess`, `.git`, …) are not shown.
- **Security headers**: set via `.htaccess` (`X-Content-Type-Options`, `X-Frame-Options`, etc.).

---

## Customisation

Open `index.php` and edit the `CONFIG` object near the top of the `<script>` section:

```js
const CONFIG = {
  defaultView:  'grid',     // 'grid' | 'list'
  previewText:  [...],      // file extensions to show as text
  maxTextSize:  512*1024,   // max file size for text preview (bytes)
  searchDelay:  300,        // debounce in ms
};
```

To change the site title or logo, edit the `<header>` section in `index.php`.

---

## License

MIT – see [LICENSE](LICENSE).