# Orbit

Orbit is a lightweight, self-contained web file browser for **any folder exposed on the web through PHP**, not just CERN EOS.

## Features

| Feature | Description |
|---------|-------------|
| 📁 **Folder navigation** | Click-through directory tree with breadcrumb |
| 🔍 **Search** | Real-time search across the current directory and all sub-folders |
| 🗂 **Type filters** | One-click filter by Images, PDFs, Videos, Audio, Data, Code, Notebooks, Documents, Archives |
| ↕️ **Sorting** | Sort by name, date (newest/oldest), size, or file type |
| 🖼 **Image preview** | Inline lightbox with zoom, keyboard navigation, and image streaming through PHP |
| 📄 **PDF preview** | Embedded PDF viewer using the browser's native renderer |
| 🎬 **Video / Audio** | HTML5 in-browser playback |
| 💻 **Text / Code view** | Inline viewer for Python, C++, JavaScript, JSON, CSV, Markdown, LaTeX, and more |
| 📓 **Jupyter notebooks** | Inline cell-by-cell preview of `.ipynb` files, including embedded figures |
| 📊 **CSV preview** | Auto-detected column headers and row preview in a table |
| 🔬 **EXIF metadata** | Camera make/model, exposure, ISO, focal length, GPS (with map link) for JPEG/TIFF images |
| 🌙 **Dark mode** | Automatic + manual toggle |
| 🔗 **Shareable links** | Copy direct links to any file or folder |
| ⬇️ **Downloads** | One-click download button for every file via the PHP file endpoint |
| ⌨️ **Keyboard shortcuts** | `←` / `→` to navigate previews, `Esc` to close, `/` to focus search |
| 📱 **Responsive** | Works on desktop, tablet, and mobile |

### File type recognition
Images, PDFs, videos, audio, text/code, CSV, notebooks, archives, documents, and scientific formats such as `ROOT`, `HDF5`, `FITS`, `Parquet`, `NumPy`, and `HepMC`.

---

## Setup

### Requirements
- A web server with **PHP ≥ 8.0**
- A folder you want to browse
- No database, build step, or npm tooling

### Installation

1. Copy `index.php` into the root of the folder you want to expose.
2. Open the page in your browser.
3. Browse files and folders immediately.

Orbit serves both the frontend and the backend API from the same `index.php` file.

---

## How it works

```text
Browser  ──GET /?action=list&path=/subdir──►  index.php  ──scandir()──►  filesystem
         ◄──── JSON: [{name, size, type, …}] ────────────────────────────────────────

Browser  ──GET /?action=meta&path=/img.jpg──►  index.php  ──metadata / EXIF──►
         ◄──── JSON: {exif: {camera, settings, gps}, image: {width, height}} ───────

Browser  ──GET /?action=file&path=/img.jpg──►  index.php  ──stream file bytes──►
         ◄──── inline file response / download ──────────────────────────────────────
```

The `action=file` endpoint ensures previews and downloads work reliably even when direct static file access is not available or not configured the way the browser expects.

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

- **Path traversal prevention**: all paths are resolved with `realpath()` and verified to stay inside `BASE_DIR`
- **Script self-exclusion**: `index.php` hides itself from the file listing
- **Hidden files hidden**: dotfiles (`.htaccess`, `.git`, …) are not shown
- **Security headers**: `X-Content-Type-Options` and `X-Frame-Options` are sent by the PHP app

---

## Customisation

Edit the `CONFIG` object in `index.php` to change defaults such as the initial view, previewable text extensions, and lazy-loading threshold.

To rename the app again, update the `<title>`, header logo text, and subtitle in `index.php`.

---

## License

MIT – see [LICENSE](LICENSE).
