# Vendored pdf.js

| Field | Value |
| --- | --- |
| Package | `pdfjs-dist` |
| Version | 6.3.289 |
| Build | `legacy/build/pdf.min.mjs` and `legacy/build/pdf.worker.min.mjs` (the legacy build supports a wider range of browsers) |
| npm shasum | `9e46d89489782a479f58d674ae5ddde8481aaa17` |
| License | Apache-2.0 (see `LICENSE`) |

Both files are ES modules. They are stored with a `.js` extension because many hosts do not serve `.mjs` with a JavaScript MIME type, and browsers refuse to load a module that is not served as JavaScript. `import.js` loads the library with a dynamic `import()` and points `GlobalWorkerOptions.workerSrc` at the vendored worker. No file is fetched from a CDN at runtime.

To upgrade: download the new `pdfjs-dist` tarball from the npm registry, verify its shasum against the registry metadata, copy the two legacy build files here under the same names, and update the version and shasum above. The import page adds the plugin version to both URLs as a cache-busting query string.

Earlier releases vendored pdf.js 3.11.174, which is affected by CVE-2024-4367 (arbitrary JavaScript from a crafted PDF font). Fixed upstream in 4.2.67.
